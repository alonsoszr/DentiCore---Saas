<?php

namespace App\Modules\Treatment\Http\Resources;

use App\Modules\Treatment\Models\Procedure;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Procedimiento del catálogo de la clínica (CUS-32; RF-107, RN-26, RN-39, RN-76): precio, marcas
 * de pieza, superficie y consentimiento informado, hallazgo resultante NTS 188 y estado activo.
 *
 * @mixin Procedure
 */
class ProcedureResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'price' => $this->price,
            'requires_tooth' => $this->requires_tooth,
            'requires_surface' => $this->requires_surface,
            'requires_informed_consent' => $this->requires_informed_consent,
            'resulting_finding' => $this->resultingFinding === null ? null : [
                'code' => $this->resultingFinding->code,
                'name' => $this->resultingFinding->name,
                'acronym' => $this->resultingFinding->acronym,
            ],
            'resulting_state' => $this->resultingFindingState === null ? null : [
                'code' => $this->resultingFindingState->code,
                'name' => $this->resultingFindingState->name,
                /** @var 'azul'|'rojo' */
                'color' => $this->resultingFindingState->color,
                'acronym' => $this->resultingFindingState->acronym,
            ],
            'is_active' => $this->is_active,
        ];
    }
}
