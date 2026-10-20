<?php

namespace App\Modules\Platform\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Files\GeneratedDocument;

/** Exportación masiva solo por administradores de la propia clínica (SDD §3.4; RN-72). */
class ClinicExportPolicy
{
    public function create(User $user, Tenant $tenant): bool
    {
        return $user->role === 'clinic_admin' && $user->tenant_id === $tenant->id && $tenant->allowsClinicExport();
    }

    public function view(User $user, Tenant $tenant, GeneratedDocument $document): bool
    {
        return $this->create($user, $tenant)
            && $document->tenant_id === $tenant->id
            && $document->documentable_type === $tenant->getMorphClass()
            && $document->documentable_id === $tenant->id
            && $document->kind === 'exportacion_clinica';
    }
}
