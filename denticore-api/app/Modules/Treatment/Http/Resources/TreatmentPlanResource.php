<?php

namespace App\Modules\Treatment\Http\Resources;

use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Plan de tratamiento (CUS-33, CUS-40; RF-110, RF-114, RF-129, RF-130): estado de SRS §5.5.2,
 * ítems en orden y avance (ítems realizados sobre total y monto realizado sobre aceptado).
 *
 * @mixin TreatmentPlan
 */
class TreatmentPlanResource extends ApiResource
{
    /**
     * Relaciones que usa el recurso.
     */
    public const RELATIONS = ['patient', 'creator', 'items.procedure', 'items.findings', 'acceptedBudget.lines'];

    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'patient_id' => $this->patient->uuid,
            'title' => $this->title,
            /** @var 'borrador'|'propuesto'|'aceptado'|'en_ejecucion'|'completado'|'cancelado' */
            'status' => $this->status,
            /** @var 'manual'|'ia'|'urgencia'|'alerta' */
            'origin' => $this->origin,
            'created_by' => [
                'id' => $this->creator->uuid,
                'name' => $this->creator->name,
            ],
            'items' => PlanItemResource::collection($this->items),
            'progress' => $this->progress(),
            'cancel_reason' => $this->cancel_reason,
            'cancelled_at' => $this->cancelled_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
