<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Http\Requests\UpdateClinicSettingsRequest;
use App\Modules\Platform\Http\Resources\ClinicSettingsResource;
use App\Modules\Platform\Services\ClinicSettingsService;
use App\Support\Http\Controller;
use Illuminate\Http\Request;

/**
 * Parámetros de la clínica (SDD §4.3.1; CUS-04), solo para `clinic_admin` (grupo STAFF).
 */
class ClinicSettingsController extends Controller
{
    public function __construct(private ClinicSettingsService $settings) {}

    public function show(): ClinicSettingsResource
    {
        return ClinicSettingsResource::make($this->settings->current());
    }

    public function update(UpdateClinicSettingsRequest $request): ClinicSettingsResource
    {
        return ClinicSettingsResource::make($this->settings->update($request->validated()));
    }

    /**
     * RF-024: PNG o JPG de hasta 1 MB.
     */
    public function uploadLogo(Request $request): ClinicSettingsResource
    {
        $request->validate(
            ['logo' => ['required', 'file', 'mimetypes:image/png,image/jpeg', 'max:1024']],
            [],
            ['logo' => 'logotipo'],
        );

        /** @var User $user */
        $user = $request->user();

        return ClinicSettingsResource::make($this->settings->replaceLogo($request->file('logo'), $user));
    }
}
