<?php

namespace App\Modules\Portal\Http\Resources;

use App\Modules\Patients\Models\Patient;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Sujeto del portal (SDD §3.3; DI-13): solo los datos que el portal necesita para mostrar de quién
 * es la información, sin datos clínicos. El SDD no define la respuesta de `GET /portal/subjects`;
 * se registra como vacío de MS-06.
 *
 * @mixin Patient
 */
class PortalSubjectResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'age_years' => $this->ageYears(),
            'is_minor' => $this->isMinorOn(),
            'relationship' => $this->user_id === $request->user()?->id ? 'titular' : 'representado',
        ];
    }
}
