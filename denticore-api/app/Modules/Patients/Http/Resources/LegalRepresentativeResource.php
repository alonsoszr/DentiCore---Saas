<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\LegalRepresentative;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Representante legal (CUS-16; RF-060). El documento y el teléfono se entregan descifrados al
 * personal de la clínica (SDD §3.5).
 *
 * @mixin LegalRepresentative
 */
class LegalRepresentativeResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'relationship' => $this->relationship,
            'phone' => $this->phone,
            'email' => $this->email,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'ended_reason' => $this->ended_reason,
            'is_current' => $this->isCurrent(),
        ];
    }
}
