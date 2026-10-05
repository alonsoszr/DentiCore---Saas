<?php

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Platform\Models\Tenant;
use App\Support\Files\FileStorage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Datos y parámetros de la clínica (CUS-04; RF-024 a RF-026). Los datos de contacto viven en
 * `tenants` y los parámetros en `clinic_settings` (SDD §2.3). Los cambios rigen solo para lo
 * que se emita después (RF-025).
 */
class ClinicSettingsService
{
    public const TENANT_FIELDS = ['name', 'address', 'phone', 'contact_email'];

    public const SETTINGS_FIELDS = [
        'prices_include_igv', 'discount_cap_pct', 'budget_validity_days', 'portal_cancel_hours',
        'self_booking_enabled', 'budget_terms',
    ];

    public function __construct(private FileStorage $files) {}

    public function current(): ClinicSetting
    {
        return ClinicSetting::query()->with('tenant')->sole();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): ClinicSetting
    {
        return DB::transaction(function () use ($data): ClinicSetting {
            $tenant = TenantContext::tenantOrFail();
            $tenant->fill(Arr::only($data, self::TENANT_FIELDS))->save();

            $settings = $this->current();
            $settings->fill(Arr::only($data, self::SETTINGS_FIELDS))->save();

            return $settings->load('tenant');
        });
    }

    /**
     * RF-024: logotipo PNG o JPG de hasta 1 MB; queda `pendiente` hasta que el antivirus lo
     * apruebe y solo entonces se entrega (DI-16).
     */
    public function replaceLogo(UploadedFile $logo, User $uploader): ClinicSetting
    {
        return DB::transaction(function () use ($logo, $uploader): ClinicSetting {
            $stored = $this->files->storeUpload($logo, $uploader);

            /** @var Tenant $tenant */
            $tenant = TenantContext::tenantOrFail();
            $tenant->forceFill(['logo_file_id' => $stored->id])->save();

            return $this->current();
        });
    }
}
