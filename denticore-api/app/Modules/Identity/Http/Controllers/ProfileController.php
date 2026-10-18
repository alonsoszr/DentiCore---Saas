<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Services\ProfileService;
use App\Support\Http\Controller;
use Illuminate\Http\Response;

/**
 * Perfil propio (SDD §4.3.2; CUS-78, RF-049). Cada usuario actúa solo sobre su cuenta.
 */
class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profiles,
        private AuthService $auth,
    ) {}

    /**
     * Cambia el nombre propio.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string} $data */
        $data = $request->validated();

        return UserResource::make($this->auth->profile($this->profiles->updateName($user, $data['name'])));
    }

    /**
     * Cambia la contraseña propia indicando la actual (RF-040, RF-049). La sesión actual sigue
     * abierta y las demás se cierran.
     */
    public function changePassword(ChangePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{password: string} $data */
        $data = $request->validated();
        // Con Sanctum::actingAs (pruebas) el token es un doble sin clave: entonces se cierran todas.
        $tokenId = $user->currentAccessToken()->getKey();

        $this->profiles->changePassword($user, $data['password'], is_int($tokenId) ? $tokenId : null);

        return response()->noContent();
    }
}
