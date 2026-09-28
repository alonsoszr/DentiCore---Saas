<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AuthService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesión (CUS-06, CUS-10; SDD §1.8). Sin `tenant_slug` el login es de super_admin.
 */
class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $session = $this->auth->login(
            $request->input('tenant_slug'),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json([
            'token' => $session['token'],
            'user' => UserResource::make($session['user']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($this->auth->profile($request->user()));
    }
}
