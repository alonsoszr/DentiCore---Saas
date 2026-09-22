<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * technical_specs.md §5.2: gestión de usuarios de la propia clínica, exclusiva de
 * clinic_admin (EnsureRole:clinic_admin en la ruta).
 */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->listForTenant(app('currentTenant')));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create(app('currentTenant'), $request->validated());

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, string $user): UserResource
    {
        $target = $this->users->findForTenant(app('currentTenant'), $user);

        return UserResource::make($this->users->update($target, $request->validated()));
    }
}
