<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\LegalRepresentative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista previa del consentimiento (CUS-17 pasos 2–3; CA-17.1): texto de la versión vigente, quién
 * lo otorga y las finalidades que la clínica puede ofrecer. La usan el personal y el portal.
 *
 * @property array{template_version: int, text: string, text_sha256: string, granted_by: string, representative: LegalRepresentative|null, available_purposes: list<string>} $resource
 */
class ConsentPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $representative = $this->resource['representative'];

        return [
            /** @var int */
            'template_version' => $this->resource['template_version'],
            'text' => $this->resource['text'],
            'text_sha256' => $this->resource['text_sha256'],
            /** @var 'titular'|'representante' */
            'granted_by' => $this->resource['granted_by'],
            'representative' => $representative === null ? null : [
                'id' => $representative->uuid,
                'first_name' => $representative->first_name,
                'last_name' => $representative->last_name,
                /** @var 'madre'|'padre'|'tutor'|'curador'|'otro' */
                'relationship' => $representative->relationship,
            ],
            /** @var list<'purpose_care'|'purpose_notifications'|'purpose_ai'|'purpose_risk'|'purpose_surveys'> */
            'available_purposes' => $this->resource['available_purposes'],
        ];
    }
}
