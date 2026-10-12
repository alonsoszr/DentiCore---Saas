<?php

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Risk\Models\RiskAlert;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Alerta de riesgo alto (SDD §2.11; CUS-57, CUS-58; RN-61, RN-65): paciente, predicción que la
 * originó, estado y la acción con que se reconoció.
 *
 * @mixin RiskAlert
 */
class RiskAlertResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            /** @var 'abierta'|'reconocida' */
            'status' => $this->status,
            'patient' => [
                'id' => $this->patient->uuid,
                'name' => trim("{$this->patient->first_name} {$this->patient->last_name}"),
            ],
            'prediction' => [
                'id' => $this->prediction->uuid,
                /** @var 'bajo'|'medio'|'alto' */
                'level' => $this->prediction->level,
                'confidence' => $this->prediction->confidence,
                'predicted_at' => $this->prediction->predicted_at->toIso8601String(),
            ],
            /** @var 'plan_preventivo'|'cita_control'|'justificacion'|null */
            'action' => $this->action,
            'treatment_plan_id' => $this->treatmentPlan?->uuid,
            'appointment_id' => $this->appointment?->uuid,
            'justification' => $this->justification,
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
