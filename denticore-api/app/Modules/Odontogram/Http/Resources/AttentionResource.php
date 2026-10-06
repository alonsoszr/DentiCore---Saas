<?php

namespace App\Modules\Odontogram\Http\Resources;

use App\Modules\Odontogram\Models\Attention;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Atención (CUS-21, CUS-25, CUS-26; RF-082, RF-094): estado, odontólogo a cargo y firma con su
 * número de COP (RN-75). El detalle incluye la nota, los diagnósticos y las adendas (RF-084, RF-097).
 *
 * @mixin Attention
 */
class AttentionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'patient_id' => $this->patient->uuid,
            'dentist' => [
                'id' => $this->dentist->uuid,
                'name' => $this->dentist->name,
                'cop' => $this->dentist->cop_number,
            ],
            /** @var 'abierta'|'cerrada'|'cerrada_incompleta' */
            'status' => $this->status,
            'is_first_attention' => $this->is_first_attention,
            'opened_at' => $this->opened_at,
            'clinical_started_at' => $this->clinical_started_at,
            'closed_at' => $this->closed_at,
            'closed_by_system' => $this->closed_by_system,
            'signer' => $this->signer === null ? null : [
                'id' => $this->signer->uuid,
                'name' => $this->signer->name,
                'cop' => $this->signer_cop,
            ],
            'signed_at' => $this->signed_at,
            'note' => ClinicalNoteResource::make($this->whenLoaded('note')),
            'diagnoses' => AttentionDiagnosisResource::collection($this->whenLoaded('diagnoses')),
            'addenda' => AttentionAddendumResource::collection($this->whenLoaded('addenda')),
        ];
    }
}
