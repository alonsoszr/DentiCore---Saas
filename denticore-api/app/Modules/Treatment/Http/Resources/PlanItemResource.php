<?php

namespace App\Modules\Treatment\Http\Resources;

use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Treatment\Models\PlanItem;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Ítem del plan de tratamiento (CUS-33, CUS-40; RF-110, RF-111, RF-129): posición, procedimiento,
 * pieza y superficies, cantidad planificada y realizada, sesión, estado de SRS §5.5.2 y los
 * hallazgos del odontograma que atiende.
 *
 * @mixin PlanItem
 */
class PlanItemResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'position' => $this->position,
            'procedure' => [
                'id' => $this->procedure->uuid,
                'code' => $this->procedure->code,
                'name' => $this->procedure->name,
            ],
            'tooth' => $this->tooth,
            /** @var list<'M'|'D'|'O'|'I'|'V'|'L'|'P'> */
            'surfaces' => $this->surfaces,
            'quantity' => $this->quantity,
            'performed_quantity' => $this->performed_quantity,
            'session_number' => $this->session_number,
            'observations' => $this->observations,
            /** @var 'propuesto'|'aceptado'|'realizado'|'descartado' */
            'status' => $this->status,
            'discard_reason' => $this->discard_reason,
            /** @var 'manual'|'ia' */
            'origin' => $this->origin,
            'finding_ids' => $this->findings->map(fn (OdontogramEntry $entry) => $entry->uuid)->values()->all(),
        ];
    }
}
