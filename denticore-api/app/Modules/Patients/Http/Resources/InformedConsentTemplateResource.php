<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\InformedConsentTemplate;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Plantilla de consentimiento informado (CUS-82, RF-072): título, estado, versión vigente y
 * procedimientos asociados (por uuid, RF-007).
 *
 * @mixin InformedConsentTemplate
 */
class InformedConsentTemplateResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'title' => $this->title,
            'is_active' => $this->is_active,
            'current_version' => $this->current_version,
            'body' => $this->currentVersion?->body,
            /** @var list<string> */
            'procedures' => $this->procedures->pluck('uuid')->all(),
        ];
    }
}
