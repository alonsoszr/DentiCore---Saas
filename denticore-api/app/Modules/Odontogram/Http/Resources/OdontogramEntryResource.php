<?php

namespace App\Modules\Odontogram\Http\Resources;

use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Entrada del odontograma (SDD §4.3 `POST /attentions/{attention}/odontogram-entries`; CUS-22,
 * CUS-23; RF-081, RN-17, RN-23, RN-75): pieza, superficies, hallazgo y estado con sus siglas,
 * color, origen, autor con su COP y hora del servidor. Una corrección indica la entrada que
 * corrige, su tipo y su motivo; en el historial, la entrada corregida indica su corrección.
 *
 * @mixin OdontogramEntry
 */
class OdontogramEntryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            /** @var 'inicial'|'evolucion'|'correccion' */
            'entry_type' => $this->entry_type,
            'tooth' => $this->tooth,
            'tooth_end' => $this->tooth_end,
            /** @var list<'M'|'D'|'O'|'I'|'V'|'L'|'P'> */
            'surfaces' => $this->surfaces,
            'finding' => $this->finding === null ? null : [
                'code' => $this->finding->code,
                'name' => $this->finding->name,
                'acronym' => $this->finding->acronym,
            ],
            'state' => $this->findingState === null ? null : [
                'code' => $this->findingState->code,
                'name' => $this->findingState->name,
                'acronym' => $this->findingState->acronym,
            ],
            /** @var 'azul'|'rojo'|null */
            'color' => $this->color,
            /** @var 'manual'|'ia'|'procedimiento' */
            'origin' => $this->origin,
            // SDD §3.4 CUS-21: recepción ve el odontograma sin notas clínicas.
            'note' => $this->when($request->user()?->role !== 'receptionist', $this->note),
            'corrects_entry_id' => $this->correctedEntry?->uuid,
            /** @var 'anulacion'|'reemplazo'|null */
            'correction_kind' => $this->correction_kind,
            'correction_reason' => $this->correction_reason,
            // CA-23.1: la entrada corregida se presenta «corregida», con enlace a su corrección.
            'corrected_by_id' => $this->whenLoaded('correction', fn () => $this->correction?->uuid),
            'author' => [
                'id' => $this->author->uuid,
                'name' => $this->author->name,
                'cop' => $this->author_cop,
            ],
            'recorded_at' => $this->recorded_at,
        ];
    }
}
