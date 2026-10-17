<?php

/*
 * Cliente del motor ML ante respuestas fallidas o mal formadas (TASK-095; SDD §4.6; RN-64, RF-166,
 * DI-12): toda falla se registra sin el contenido, cuenta para el Circuit Breaker y termina en
 * «Predicción no disponible»; un 401 o un 404 además avisa a la plataforma.
 */

use App\Modules\Risk\Models\ExternalCallLog;
use App\Modules\Risk\Services\CircuitBreaker;
use App\Modules\Risk\Services\RiskEngineClient;
use App\Modules\Risk\Services\RiskEngineUnavailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ExternalServices;
use Tests\Support\MlEngine;

beforeEach(fn () => app(CircuitBreaker::class)->reset());
afterEach(fn () => app(CircuitBreaker::class)->reset());

/** `outcome` de la indisponibilidad, o `ok` si el motor respondió bien. */
function predictOutcome(): string
{
    try {
        app(RiskEngineClient::class)->predict('1.0.0', 'adulto', MlEngine::features());
    } catch (RiskEngineUnavailable $unavailable) {
        return $unavailable->outcome;
    }

    return 'ok';
}

it('counts a server error as a failure of the breaker', function () {
    ExternalServices::respondsWith(MlEngine::URL, ['title' => 'internal'], 500);

    expect(predictOutcome())->toBe('error');

    $log = ExternalCallLog::query()->sole();
    expect($log->only(['operation', 'outcome', 'http_status']))->toBe(['operation' => 'predict', 'outcome' => 'error', 'http_status' => 500]);
    foreach (range(1, 4) as $ignored) {
        predictOutcome();
    }
    expect(app(CircuitBreaker::class)->state())->toBe(CircuitBreaker::OPEN);
})->group('RN-64', 'RF-166');

it('warns the platform about a rejected credential or a version without artifacts', function (int $status, string $warning) {
    Log::spy();
    ExternalServices::respondsWith(MlEngine::URL, ['title' => 'x'], $status);

    expect(predictOutcome())->toBe('error');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === $warning)->once();
})->with([
    'credencial rechazada' => [401, 'ml.unauthorized'],
    'versión sin artefactos' => [404, 'ml.sin_modelo_activo'],
])->group('RN-64', 'RNF-099');

it('rejects an answer that breaks the contract', function (array $overrides) {
    Http::fake([MlEngine::URL => fn ($request) => Http::response(MlEngine::response($request->data(), 0.3, $overrides))]);

    expect(predictOutcome())->toBe('respuesta_invalida')
        ->and(ExternalCallLog::query()->value('outcome'))->toBe('respuesta_invalida');
})->with([
    'probabilidad fuera de [0, 1]' => [['probability' => 1.4]],
    'sin base_value' => [['base_value' => null]],
    'contribución que no es un objeto' => [['explanation' => ['global' => [], 'individual' => ['cpod']]]],
])->group('RN-64', 'RNF-174');

it('reports the engine models as unavailable when it does not answer or fails', function (Closure $engine, string $outcome) {
    MlEngine::reset();
    $engine();

    expect(fn () => app(RiskEngineClient::class)->models())
        ->toThrow(fn (RiskEngineUnavailable $unavailable) => expect($unavailable->outcome)->toBe($outcome));
    expect(ExternalCallLog::query()->where('operation', 'models')->value('outcome'))->toBe($outcome);
})->with([
    'sin respuesta' => [fn () => ExternalServices::timesOut('*/v1/models'), 'timeout'],
    'error del motor' => [fn () => ExternalServices::respondsWith('*/v1/models', ['title' => 'x'], 500), 'error'],
    'sin la lista de modelos' => [fn () => ExternalServices::respondsWith('*/v1/models', ['items' => []]), 'respuesta_invalida'],
])->group('RN-84', 'CUS-61');

it('reads the breaker as closed and resets it without Redis', function () {
    config(['database.redis.default.port' => 1, 'database.redis.default.max_retries' => 0]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');

    expect(app(CircuitBreaker::class)->state())->toBe(CircuitBreaker::CLOSED);
    app(CircuitBreaker::class)->reset();

    config(['database.redis.default.port' => (int) env('REDIS_PORT', 6379)]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
})->group('DI-12', 'RN-64');
