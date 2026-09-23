<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * technical_specs.md §5.1 (POST /auth/login), extendido con tenant_slug para
     * desambiguar el email cuando no es global (ver §3.3: UNIQUE(tenant_id, email)).
     * Sin tenant_slug se interpreta como login de super_admin (tenant_id NULL).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $tenantSlug = $request->input('tenant_slug');

        $usersQuery = User::query();

        if ($tenantSlug) {
            $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
            $usersQuery->where('tenant_id', $tenant->id);
        } else {
            $usersQuery->whereNull('tenant_id');
        }

        $user = $usersQuery->where('email', $request->string('email'))->first();

        if (! $user || ! $user->is_active || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user->loadMissing(['tenant', 'patient'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->loadMissing(['tenant', 'patient']));
    }
}
