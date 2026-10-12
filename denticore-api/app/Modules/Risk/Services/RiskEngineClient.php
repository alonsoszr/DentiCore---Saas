<?php

namespace App\Modules\Risk\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente del motor ML (SDD §4.6; IE-02, RF-166, RF-167, RN-64, RNF-045). Cada predicción pasa
 * por el Circuit Breaker, usa timeout de 3 s (conexión 1 s), cero reintentos y un `request_ref`
 * UUID v4 de un solo uso, y valida la respuesta (contrato de mayor 1, misma versión y referencia,
 * probabilidades en [0, 1] y una contribución por variable). Toda llamada queda en
 * `external_call_logs`.
 *
 * Los 422 del motor (variable fuera de dominio, RNF-174) no cuentan como fallo del breaker: el
 * motor respondió. Los 401 y 404 sí, y además se avisan en el log (alerta M13 y
 * `sin_modelo_activo`; la tabla de alertas llega en MS-14).
 */
class RiskEngineClient
{
    public const CONTRACT_VERSION = '1.0';

    private const SERVICE = 'ml';

    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly ExternalCallLogger $calls,
        private readonly MlSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $features  Las 12 variables del contrato, sin datos personales.
     *
     * @throws RiskEngineUnavailable
     */
    public function predict(string $modelVersion, string $ageGroup, array $features): EnginePrediction
    {
        $correlationId = (string) Str::uuid();

        if ($this->breaker->acquire() === CircuitBreaker::REJECTED) {
            $this->calls->record(self::SERVICE, 'predict', 'cb_abierto', 0, null, CircuitBreaker::OPEN, $correlationId);

            throw new RiskEngineUnavailable('cb_abierto');
        }

        $requestRef = (string) Str::uuid();
        $started = hrtime(true);

        try {
            $response = $this->request($correlationId)->post('/v1/predict', [
                'contract_version' => self::CONTRACT_VERSION,
                'request_ref' => $requestRef,
                'model_version' => $modelVersion,
                'age_group' => $ageGroup,
                'features' => $features,
            ]);
        } catch (ConnectionException) {
            $this->fail('predict', 'timeout', $this->elapsedMs($started), null, $correlationId);
        }

        $latencyMs = $this->elapsedMs($started);

        if ($response->status() === 422) {
            $this->breaker->recordSuccess();
            $this->calls->record(self::SERVICE, 'predict', 'error', $latencyMs, 422, CircuitBreaker::CLOSED, $correlationId);

            throw new RiskEngineUnavailable('error', 422);
        }

        if (! $response->successful()) {
            $this->warnAbout($response, $modelVersion);
            $this->fail('predict', 'error', $latencyMs, $response->status(), $correlationId);
        }

        $body = $response->json();

        if (! is_array($body) || ! self::isValid($body, $requestRef, $modelVersion, array_keys($features))) {
            $this->fail('predict', 'respuesta_invalida', $latencyMs, $response->status(), $correlationId);
        }

        $this->calls->record(self::SERVICE, 'predict', 'ok', $latencyMs, $response->status(), $this->breaker->recordSuccess(), $correlationId);

        /** @var array{probability: float|int, confidence: float|int, base_value: float|int, explanation: array{global: list<array{feature: string, mean_abs_shap: float}>, individual: list<array{feature: string, value: mixed, shap: float}>}} $body */
        return new EnginePrediction(
            requestRef: $requestRef,
            probability: (float) $body['probability'],
            confidence: (float) $body['confidence'],
            baseValue: (float) $body['base_value'],
            explanationGlobal: $body['explanation']['global'],
            explanationIndividual: $body['explanation']['individual'],
            latencyMs: $latencyMs,
        );
    }

    /**
     * Versiones disponibles en el motor con sus metadatos (`GET /v1/models`, CUS-61, RN-84). No
     * pasa por el breaker: la usa el comando de registro, no la atención.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RiskEngineUnavailable
     */
    public function models(): array
    {
        $correlationId = (string) Str::uuid();
        $started = hrtime(true);

        try {
            $response = $this->request($correlationId)->get('/v1/models');
        } catch (ConnectionException) {
            $this->calls->record(self::SERVICE, 'models', 'timeout', $this->elapsedMs($started), null, $this->breaker->state(), $correlationId);

            throw new RiskEngineUnavailable('timeout');
        }

        $models = $response->json('models');
        $outcome = $response->successful() ? (is_array($models) ? 'ok' : 'respuesta_invalida') : 'error';
        $this->calls->record(self::SERVICE, 'models', $outcome, $this->elapsedMs($started), $response->status(), $this->breaker->state(), $correlationId);

        if ($outcome !== 'ok') {
            throw new RiskEngineUnavailable($outcome, $response->status());
        }

        return array_values(array_filter((array) $models, 'is_array'));
    }

    /**
     * Validación de la respuesta en Laravel (SDD §4.6, CUS-55 paso 7; RNF-045, RNF-152).
     *
     * @param  array<mixed>  $body
     * @param  list<string>  $features
     */
    public static function isValid(array $body, string $requestRef, string $modelVersion, array $features): bool
    {
        $contract = $body['contract_version'] ?? null;
        $individual = $body['explanation']['individual'] ?? null;
        $global = $body['explanation']['global'] ?? null;

        if (! is_string($contract) || explode('.', $contract)[0] !== explode('.', self::CONTRACT_VERSION)[0]) {
            return false;
        }

        if (($body['request_ref'] ?? null) !== $requestRef || ($body['model_version'] ?? null) !== $modelVersion) {
            return false;
        }

        foreach (['probability', 'confidence'] as $key) {
            if (! is_numeric($body[$key] ?? null) || $body[$key] < 0 || $body[$key] > 1) {
                return false;
            }
        }

        if (! is_numeric($body['base_value'] ?? null) || ! is_array($global) || ! is_array($individual) || ! array_is_list($individual)) {
            return false;
        }

        $returned = [];
        foreach ($individual as $contribution) {
            if (! is_array($contribution) || ! is_string($contribution['feature'] ?? null) || ! is_numeric($contribution['shap'] ?? null)) {
                return false;
            }
            $returned[] = $contribution['feature'];
        }

        sort($returned);
        sort($features);

        return $returned === $features;
    }

    private function request(string $correlationId): PendingRequest
    {
        return Http::baseUrl((string) config('services.ml.url'))
            ->withHeaders(['X-ML-Service-Key' => (string) config('services.ml.key'), 'X-Correlation-Id' => $correlationId])
            ->acceptJson()
            ->connectTimeout(MlSettings::CONNECT_TIMEOUT_SECONDS)
            ->timeout($this->settings->timeoutMs / 1000);
    }

    /**
     * @throws RiskEngineUnavailable
     */
    private function fail(string $operation, string $outcome, int $latencyMs, ?int $httpStatus, string $correlationId): never
    {
        $state = $this->breaker->recordFailure();
        $this->calls->record(self::SERVICE, $operation, $outcome, $latencyMs, $httpStatus, $state, $correlationId);

        throw new RiskEngineUnavailable($outcome, $httpStatus);
    }

    /** 401 (credencial) y 404 (versión sin artefactos) exigen atención de la plataforma. */
    private function warnAbout(Response $response, string $modelVersion): void
    {
        match ($response->status()) {
            401 => Log::warning('ml.unauthorized'),
            404 => Log::warning('ml.sin_modelo_activo', ['model_version' => $modelVersion]),
            default => null,
        };
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
