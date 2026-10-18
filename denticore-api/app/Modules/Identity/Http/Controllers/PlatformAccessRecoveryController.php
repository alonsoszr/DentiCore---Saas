<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AccessRecoveryService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;

/**
 * Restablecer el acceso de un Administrador de Clínica desde la plataforma (SDD §4.3.2; CUS-79,
 * RF-052, RN-04, DD-36).
 */
class PlatformAccessRecoveryController extends Controller
{
    public function __construct(private AccessRecoveryService $recovery) {}

    #[ProblemResponse(403, 'El usuario no es Administrador de Clínica (RN-04)')]
    #[ProblemResponse(409, 'La cuenta no está bloqueada (RF-052)')]
    public function unlock(string $user): UserResource
    {
        return UserResource::make($this->recovery->unlock($this->recovery->clinicAdmin($user)));
    }

    #[ProblemResponse(403, 'El usuario no es Administrador de Clínica (RN-04)')]
    public function resetTwoFactor(string $user): UserResource
    {
        return UserResource::make($this->recovery->resetTwoFactor($this->recovery->clinicAdmin($user), 'plataforma'));
    }
}
