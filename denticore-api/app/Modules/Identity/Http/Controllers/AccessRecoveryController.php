<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AccessRecoveryService;
use App\Modules\Identity\Services\UserService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use App\Support\Tenancy\TenantContext;

/**
 * Restablecer el acceso de un usuario de la clínica (SDD §4.3.2; CUS-79, RF-052).
 */
class AccessRecoveryController extends Controller
{
    public function __construct(
        private UserService $users,
        private AccessRecoveryService $recovery,
    ) {}

    #[ProblemResponse(409, 'La cuenta no está bloqueada (RF-052)')]
    public function unlock(string $user): UserResource
    {
        return UserResource::make($this->recovery->unlock($this->users->findForTenant(TenantContext::tenantOrFail(), $user)));
    }

    public function resetTwoFactor(string $user): UserResource
    {
        $target = $this->users->findForTenant(TenantContext::tenantOrFail(), $user);

        return UserResource::make($this->recovery->resetTwoFactor($target, 'administrador'));
    }
}
