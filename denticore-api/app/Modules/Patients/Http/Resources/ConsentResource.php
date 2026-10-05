<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Services\ConsentGate;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Consentimiento de datos (CUS-17; RF-065, RF-067): finalidades, otorgante, canal, huella del
 * texto y estado. `outdated` marca una versión anterior de la plantilla (RN-15). La evidencia,
 * la IP y el texto completo quedan en la constancia.
 *
 * @mixin Consent
 */
class ConsentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'template_version' => $this->consent_template_version,
            'purpose_care' => $this->purpose_care,
            'purpose_notifications' => $this->purpose_notifications,
            'purpose_ai' => $this->purpose_ai,
            'purpose_risk' => $this->purpose_risk,
            'purpose_surveys' => $this->purpose_surveys,
            /** @var 'titular'|'representante' */
            'granted_by' => $this->granted_by,
            'representative_id' => $this->legalRepresentative?->uuid,
            /** @var 'presencial'|'portal'|'papel' */
            'channel' => $this->channel,
            'text_sha256' => $this->text_sha256,
            'granted_at' => $this->granted_at,
            /** @var 'vigente'|'revocado'|'sustituido' */
            'status' => $this->status,
            'superseded_at' => $this->superseded_at,
            'revoked_at' => $this->revoked_at,
            'outdated' => $this->consent_template_version < app(ConsentGate::class)->currentTemplateVersion(),
        ];
    }
}
