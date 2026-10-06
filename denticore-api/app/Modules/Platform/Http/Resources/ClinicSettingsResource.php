<?php

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\Models\ClinicSetting;
use App\Support\Files\FileStorage;
use App\Support\Files\StoredFile;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Datos y parámetros de la clínica (CUS-04; RF-024 a RF-027). El logotipo se entrega con URL
 * firmada de 10 minutos solo cuando el antivirus lo aprobó (DI-16).
 *
 * @mixin ClinicSetting
 */
class ClinicSettingsResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        $tenant = $this->tenant;
        $logo = $tenant->logo_file_id === null ? null : StoredFile::query()->find($tenant->logo_file_id);

        return [
            'name' => $tenant->name,
            'address' => $tenant->address,
            'phone' => $tenant->phone,
            'contact_email' => $tenant->contact_email,
            /** @var array{id: string, status: string, url: string|null}|null */
            'logo' => $logo === null ? null : [
                'id' => $logo->uuid,
                'status' => $logo->scan_status,
                'url' => $logo->isDeliverable() ? app(FileStorage::class)->temporaryUrl($logo, $request->user()) : null,
            ],
            'prices_include_igv' => $this->prices_include_igv,
            'discount_cap_pct' => $this->discount_cap_pct,
            'budget_validity_days' => $this->budget_validity_days,
            'portal_cancel_hours' => $this->portal_cancel_hours,
            'self_booking_enabled' => $this->self_booking_enabled,
            'ai_enabled' => $this->ai_enabled,
            'budget_terms' => $this->budget_terms,
        ];
    }
}
