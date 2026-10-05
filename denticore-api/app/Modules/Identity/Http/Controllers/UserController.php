<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Usuarios de la propia clínica (SDD §4.3.2; CUS-11), exclusivos de clinic_admin (grupo STAFF).
 */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var array{role?: string|null, status?: string|null, per_page?: int|null} $filters */
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(User::TENANT_ROLES)],
            'status' => ['nullable', Rule::in(['pendiente_activacion', 'activo', 'bloqueado_temporal', 'inactivo'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return UserResource::collection($this->users->paginate(TenantContext::tenantOrFail(), $filters));
    }

    #[ProblemResponse(422, 'Se alcanzó el máximo de odontólogos del plan (RN-08)')]
    public function store(StoreUserRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, role: string, cop_number?: string|null, specialty?: string|null, rne_number?: string|null, is_data_officer?: bool} $data */
        $data = $request->validated();
        $user = $this->users->create(TenantContext::tenantOrFail(), $data);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(string $user): UserResource
    {
        return UserResource::make($this->users->findForTenant(TenantContext::tenantOrFail(), $user));
    }

    #[ProblemResponse(422, 'Último administrador u oficial de datos (RF-045) o máximo de odontólogos (RN-08)')]
    public function update(UpdateUserRequest $request, string $user): UserResource
    {
        /** @var array{name?: string, email?: string, role?: string, cop_number?: string|null, specialty?: string|null, rne_number?: string|null, is_data_officer?: bool} $data */
        $data = $request->validated();
        $target = $this->users->findForTenant(TenantContext::tenantOrFail(), $user);

        return UserResource::make($this->users->update($target, $data));
    }

    #[ProblemResponse(422, 'Último administrador u oficial de datos (RF-045)')]
    public function deactivate(string $user): UserResource
    {
        return UserResource::make($this->users->deactivate($this->users->findForTenant(TenantContext::tenantOrFail(), $user)));
    }

    #[ProblemResponse(422, 'Se alcanzó el máximo de odontólogos del plan (RN-08)')]
    public function reactivate(string $user): UserResource
    {
        return UserResource::make($this->users->reactivate($this->users->findForTenant(TenantContext::tenantOrFail(), $user)));
    }

    #[ProblemResponse(409, 'El usuario ya activó su cuenta (RF-042)')]
    public function resendInvitation(string $user): Response
    {
        $this->users->resendInvitation($this->users->findForTenant(TenantContext::tenantOrFail(), $user));

        return response()->noContent();
    }
}
