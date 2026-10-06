<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sesión (CUS-06, CUS-10; SDD §1.8, §4.5). Sin `tenant_slug` el login es de super_admin.
 */
class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    /**
     * Respuesta de SDD §4.5: `token`, `abilities`, `requires_2fa`, `requires_2fa_setup`,
     * `expires_at` y, solo con un token completo, `user`.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $session = $this->auth->login(
            $tenant instanceof Tenant ? $tenant : null,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'token' => $session['token'],
            'abilities' => $session['abilities'],
            'requires_2fa' => $session['abilities'] === ['2fa:pending'],
            'requires_2fa_setup' => $session['abilities'] === ['2fa:setup'],
            'expires_at' => $session['expires_at'],
            'user' => $session['user'] === null ? null : UserResource::make($session['user']),
        ]);
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->auth->logout($user);

        return response()->noContent();
    }

    /**
     * RF-036: cualquier solicitud autenticada renueva la actividad; esta existe para que la SPA
     * la renueve sin pedir datos (aviso de inactividad, RNF-065).
     */
    public function keepalive(): Response
    {
        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($this->auth->profile($user));
    }
}
