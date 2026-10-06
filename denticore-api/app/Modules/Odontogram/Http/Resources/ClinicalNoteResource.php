<?php

namespace App\Modules\Odontogram\Http\Resources;

use App\Modules\Odontogram\Models\ClinicalNote;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Nota de atención estructurada (CUS-80; RF-084): secciones, estado y firma.
 *
 * @mixin ClinicalNote
 */
class ClinicalNoteResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'chief_complaint' => $this->chief_complaint,
            'current_illness' => $this->current_illness,
            'extraoral_exam' => $this->extraoral_exam,
            'intraoral_exam' => $this->intraoral_exam,
            'indications' => $this->indications,
            /** @var 'borrador'|'firmada' */
            'status' => $this->status,
            'signed_at' => $this->signed_at,
        ];
    }
}
