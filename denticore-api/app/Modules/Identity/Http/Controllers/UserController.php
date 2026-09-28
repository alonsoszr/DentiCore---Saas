<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\UserService;
use App\Support\Http\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Usuarios de la propia clínica (CUS-11), exclusivos de clinic_admin (`role:clinic_admin`).
 */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->listForTenant(TenantContext::tenantOrFail()));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create(TenantContext::tenantOrFail(), $request->validated());

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, string $user): UserResource
    {
        $target = $this->users->findForTenant(TenantContext::tenantOrFail(), $user);

        return UserResource::make($this->users->update($target, $request->validated()));
    }
}
