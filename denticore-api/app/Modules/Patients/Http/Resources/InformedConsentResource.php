<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\InformedConsent;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Consentimiento informado (CUS-83; RF-073, RF-074): estado, otorgante, canal, huella del texto
 * y revocación. La evidencia, la IP y el texto completo quedan en la constancia.
 *
 * @mixin InformedConsent
 */
class InformedConsentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'template_version' => $this->templateVersion->version,
            'signer' => $this->signer,
            'representative_id' => $this->legalRepresentative?->uuid,
            'channel' => $this->channel,
            'text_sha256' => $this->text_sha256,
            'signed_at' => $this->signed_at,
            'status' => $this->status,
            'used_at' => $this->used_at,
            'revoked_at' => $this->revoked_at,
            'revocation_reason' => $this->revocation_reason,
            'informed_by' => $this->informedBy === null ? null : [
                'id' => $this->informedBy->uuid,
                'name' => $this->informedBy->name,
            ],
        ];
    }
}
