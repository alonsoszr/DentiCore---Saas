<?php

namespace App\Modules\Risk\Services;

use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reconocimiento de una alerta de riesgo alto (SDD §5.8.4; CUS-58; RN-65, RF-171). La acción es
 * obligatoria y se vincula a algo que ya existe:
 *
 * - `plan_preventivo`: un plan no cancelado ni completado del mismo paciente, que pasa a
 *   `origin = alerta` con `risk_alert_id`;
 * - `cita_control`: una cita activa del mismo paciente, que recibe `risk_alert_id`;
 * - `justificacion`: un texto de 20 caracteres o más.
 *
 * Todo ocurre en una transacción con la alerta bloqueada; una alerta ya reconocida responde 409.
 */
class RiskAlertService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{action: string, treatment_plan_id?: string|null, appointment_id?: string|null, justification?: string|null}  $data
     *
     * @throws BusinessRuleException|ValidationException
     */
    public function acknowledge(RiskAlert $alert, array $data): RiskAlert
    {
        $alert = DB::transaction(function () use ($alert, $data): RiskAlert {
            $alert = RiskAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();

            if ($alert->status !== 'abierta') {
                throw new BusinessRuleException('RN-65', 'La alerta ya fue reconocida.', status: 409);
            }

            $attributes = ['status' => 'reconocida', 'action' => $data['action'], 'acknowledged_at' => now()->utc()];

            match ($data['action']) {
                'plan_preventivo' => $attributes['treatment_plan_id'] = $this->linkPlan($alert, (string) $data['treatment_plan_id']),
                'cita_control' => $attributes['appointment_id'] = $this->linkAppointment($alert, (string) $data['appointment_id']),
                default => $attributes['justification'] = trim((string) $data['justification']),
            };

            $alert->forceFill($attributes)->save();

            return $alert;
        });

        $this->audit->record(AuditEvent::RiskAlertAcknowledged, $alert, ['status', 'action'], meta: ['action' => $alert->action]);

        return $alert;
    }

    /** @throws ValidationException */
    private function linkPlan(RiskAlert $alert, string $uuid): int
    {
        $plan = TreatmentPlan::query()
            ->where('uuid', $uuid)
            ->where('patient_id', $alert->patient_id)
            ->whereNotIn('status', ['cancelado', 'completado'])
            ->lockForUpdate()
            ->first();

        if ($plan === null || ($plan->risk_alert_id !== null && $plan->risk_alert_id !== $alert->id)) {
            throw ValidationException::withMessages(['treatment_plan_id' => 'Elija un plan vigente del paciente que no esté vinculado a otra alerta.']);
        }

        $plan->forceFill(['origin' => 'alerta', 'risk_alert_id' => $alert->id])->save();

        return $plan->id;
    }

    /** @throws ValidationException */
    private function linkAppointment(RiskAlert $alert, string $uuid): int
    {
        $appointment = Appointment::query()
            ->where('uuid', $uuid)
            ->where('patient_id', $alert->patient_id)
            ->whereIn('status', ['programada', 'confirmada', 'en_atencion'])
            ->lockForUpdate()
            ->first();

        if ($appointment === null || ($appointment->risk_alert_id !== null && $appointment->risk_alert_id !== $alert->id)) {
            throw ValidationException::withMessages(['appointment_id' => 'Elija una cita activa del paciente que no esté vinculada a otra alerta.']);
        }

        $appointment->forceFill(['risk_alert_id' => $alert->id])->save();

        return $appointment->id;
    }
}
