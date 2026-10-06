<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Platform\Models\Tenant;
use App\Support\Files\FileStorage;
use App\Support\Files\StoredFile;
use App\Support\Http\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Perfil público de una clínica para sus pantallas de acceso (SDD §4.3.2; RF-032, DD-29):
 * nombre, código y logotipo. La clínica la resuelve `tenant.slug` (404 si no existe o está
 * eliminada). No expone RUC ni datos internos.
 */
class PublicClinicController extends Controller
{
    public function show(FileStorage $files): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = TenantContext::tenantOrFail();
        $logo = $tenant->logo_file_id === null ? null : StoredFile::query()->find($tenant->logo_file_id);

        return response()->json(['data' => [
            'id' => $tenant->uuid,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'logo_url' => $logo !== null && $logo->isDeliverable() ? $files->temporaryUrl($logo) : null,
        ]]);
    }
}
