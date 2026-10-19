<?php

namespace App\Modules\Risk\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentGate;
use App\Modules\Risk\Models\ModelVersion;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cálculo del riesgo de caries (SDD §5.8.2; CUS-55, CUS-57; RN-58 a RN-61, RN-64, DD-27, RF-165 a
 * RF-170):
 *
 * 1. Condiciones de RN-58: finalidad (d) vigente, registro de variables más reciente, completo y
 *    capturado hace 6 meses o menos (422 con `missing`, sin llamar al motor). El plan lo exige la ruta.
 * 2. Sin versión activa, «Predicción no disponible» y aviso `sin_modelo_activo` (FE-4).
 * 3. La misma solicitud (registro y versión) en los últimos 60 s devuelve la predicción existente.
 * 4. Llamada al motor fuera de la transacción; si falla, no se guarda nada y la vigente sigue.
 * 5. En una transacción: nivel con los umbrales de la versión, la vigente anterior pasa a
 *    `reemplazada`, la nueva queda `vigente` 12 meses, el registro se bloquea y, si el nivel es
 *    alto, se crea la alerta para el odontólogo solicitante con aviso in-app y correo (cola
 *    `critical`).
 */
class RiskPredictionService
{
    public const IDEMPOTENCY_SECONDS = 60;

    public function __construct(
        private readonly RiskEngineClient $engine,
        private readonly ConsentGate $consent,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws BusinessRuleException
     */
    public function predict(Patient $patient, RiskVariableSet $set, User $dentist): PredictionResult
    {
        $this->ensureConditions($patient, $set);

        $version = ModelVersion::active();
        if ($version === null) {
            Log::warning('ml.sin_modelo_activo');

            return PredictionResult::unavailable();
        }

        $recent = RiskPrediction::query()
            ->where('risk_variable_set_id', $set->id)
            ->where('model_version_id', $version->id)
            ->where('predicted_at', '>=', now()->subSeconds(self::IDEMPOTENCY_SECONDS))
            ->latest('predicted_at')
            ->first();
        if ($recent !== null) {
            return new PredictionResult($recent);
        }

        try {
            $result = $this->engine->predict($version->version, $set->age_group, RiskVariableValidator::features($set));
        } catch (RiskEngineUnavailable) {
            return PredictionResult::unavailable();
        }

        [$prediction, $alert] = DB::transaction(fn () => $this->store($patient, $set, $dentist, $version, $result));

        $this->audit->record(AuditEvent::RiskPredicted, $prediction, actor: $dentist);
        if ($alert !== null) {
            $this->notifyAlert($alert, $patient, $dentist);
        }

        return new PredictionResult($prediction, created: true);
    }

    /**
     * @throws BusinessRuleException
     */
    private function ensureConditions(Patient $patient, RiskVariableSet $set): void
    {
        if (! $this->consent->allows($patient, 'prediccion')) {
            throw new BusinessRuleException('RN-58', 'El paciente no autorizó la finalidad (d) «predicción de riesgo» o su consentimiento no está vigente.');
        }

        $latest = RiskVariableSet::query()->where('patient_id', $patient->id)->latest('id')->value('id');
        if ($latest !== $set->id) {
            throw new BusinessRuleException('RF-164', 'Use el registro de variables más reciente del paciente.');
        }

        $missing = RiskVariableValidator::missing($set, now());
        if ($missing !== []) {
            throw new BusinessRuleException('RN-58', 'Faltan variables para calcular el riesgo.', extensions: ['missing' => $missing]);
        }
    }

    /**
     * @return array{RiskPrediction, RiskAlert|null}
     */
    private function store(Patient $patient, RiskVariableSet $set, User $dentist, ModelVersion $version, EnginePrediction $result): array
    {
        // Se redondea antes de decidir el nivel para que coincida con el CHECK de numeric(5,4).
        $probability = round($result->probability, 4);
        $level = match (true) {
            $probability >= (float) $version->threshold_high => 'alto',
            $probability >= (float) $version->threshold_medium => 'medio',
            default => 'bajo',
        };

        RiskPrediction::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'vigente')
            ->lockForUpdate()
            ->get()
            ->each(fn (RiskPrediction $previous) => $previous->forceFill(['status' => 'reemplazada'])->save());

        $predictedAt = now()->utc();
        $prediction = new RiskPrediction;
        $prediction->forceFill([
            'patient_id' => $patient->id,
            'risk_variable_set_id' => $set->id,
            'model_version_id' => $version->id,
            'probability' => $probability,
            'level' => $level,
            'confidence' => round($result->confidence, 4),
            'threshold_medium' => $version->threshold_medium,
            'threshold_high' => $version->threshold_high,
            'base_value' => round($result->baseValue, 6),
            'explanation_global' => array_map(
                fn (array $item) => ['feature' => $item['feature'], 'mean_abs_shap' => round((float) $item['mean_abs_shap'], 6)],
                $result->explanationGlobal,
            ),
            'explanation_individual' => array_map(fn (array $item) => [
                'feature' => $item['feature'],
                'value' => RiskFeatureLabels::stored($item['value'] ?? null),
                'shap' => round((float) $item['shap'], 6),
            ], $result->explanationIndividual),
            'status' => 'vigente',
            'requested_by' => $dentist->id,
            'predicted_at' => $predictedAt,
            // Igual que `+ interval '12 months'` de PostgreSQL: sin desbordar al mes siguiente.
            'expires_at' => $predictedAt->copy()->addMonthsNoOverflow(12),
            'request_ref' => $result->requestRef,
            'latency_ms' => $result->latencyMs,
        ])->save();

        if (! $set->isLocked()) {
            $set->forceFill(['locked_at' => $predictedAt])->save();
        }

        $alert = null;
        if ($level === 'alto' && ! $prediction->isStale()) {
            $alert = new RiskAlert;
            $alert->forceFill([
                'risk_prediction_id' => $prediction->id,
                'prediction_level' => 'alto',
                'patient_id' => $patient->id,
                'dentist_id' => $dentist->id,
                'status' => 'abierta',
            ])->save();
        }

        return [$prediction, $alert];
    }

    /** Aviso in-app y correo al odontólogo solicitante, sin datos de identificación (RNF-110). */
    private function notifyAlert(RiskAlert $alert, Patient $patient, User $dentist): void
    {
        $base = rtrim((string) config('app.spa_url'), '/').'/c/'.TenantContext::tenantOrFail()->slug.'/app';
        $links = ['alerts' => "{$base}/alertas", 'patient' => "{$base}/pacientes/{$patient->uuid}/riesgo"];

        $this->notifications->notifyInApp(NotificationEvent::AlertaRiesgoAlto, $dentist, ['level' => 'alto'], $links, "alerta_riesgo_alto:{$alert->uuid}");
        $this->notifications->sendEmail(NotificationEvent::AlertaRiesgoAlto, $dentist, [], $links, "alerta_riesgo_alto:correo:{$alert->uuid}");
    }
}
