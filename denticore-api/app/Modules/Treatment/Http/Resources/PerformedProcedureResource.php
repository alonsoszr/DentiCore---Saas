<?php

namespace App\Modules\Treatment\Http\Resources;

use App\Modules\Treatment\Models\PerformedProcedure;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Procedimiento realizado (CUS-39; RF-126, RF-127, RF-130): cantidad, fecha, odontólogo con COP,
 * atención, entrada de evolución del odontograma y consentimiento informado usados, y el avance
 * del ítem y del plan tras registrarlo (con su presupuesto aceptado).
 *
 * @mixin PerformedProcedure
 */
class PerformedProcedureResource extends ApiResource
{
    /**
     * Relaciones que usa el recurso.
     */
    public const RELATIONS = ['dentist', 'attention', 'informedConsent', 'planItem.plan.acceptedBudget'];

    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        $item = $this->planItem;
        $plan = $item->plan;

        return [
            'quantity' => $this->quantity,
            'performed_at' => $this->performed_at,
            'observations' => $this->observations,
            'dentist' => [
                'id' => $this->dentist->uuid,
                'name' => $this->dentist->name,
                'cop' => $this->dentist->cop_number,
            ],
            'attention_id' => $this->attention->uuid,
            'odontogram_entry_id' => $this->odontogram_entry_uuid,
            'informed_consent_id' => $this->informedConsent?->uuid,
            'plan_item' => [
                'id' => $item->uuid,
                /** @var 'propuesto'|'aceptado'|'realizado'|'descartado' */
                'status' => $item->status,
                'quantity' => $item->quantity,
                'performed_quantity' => $item->performed_quantity,
            ],
            'plan' => [
                'id' => $plan->uuid,
                /** @var 'borrador'|'propuesto'|'aceptado'|'en_ejecucion'|'completado'|'cancelado' */
                'status' => $plan->status,
            ],
            'budget_id' => $plan->acceptedBudget?->uuid,
        ];
    }
}
