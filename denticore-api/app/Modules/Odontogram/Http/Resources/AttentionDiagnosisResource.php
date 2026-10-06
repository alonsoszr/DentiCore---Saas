<?php

namespace App\Modules\Odontogram\Http\Resources;

use App\Modules\Odontogram\Models\AttentionDiagnosis;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Diagnóstico CIE-10 de la atención (CUS-80, CUS-81; RF-084, RF-085).
 *
 * @mixin AttentionDiagnosis
 */
class AttentionDiagnosisResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'code' => $this->cie10_code,
            'description' => $this->cie10->description,
            /** @var 'presuntivo'|'definitivo' */
            'type' => $this->type,
            /** @var 'nota'|'adenda' */
            'origin' => $this->origin,
            'created_at' => $this->created_at,
        ];
    }
}
