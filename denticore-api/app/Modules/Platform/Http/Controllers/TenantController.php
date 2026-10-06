<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Requests\StoreTenantRequest;
use App\Modules\Platform\Http\Requests\UpdateTenantRequest;
use App\Modules\Platform\Http\Resources\TenantResource;
use App\Modules\Platform\Services\TenantService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Clínicas de la plataforma (M01; SDD §4.3.1, CUS-01), exclusivas de super_admin (grupo PLAT).
 */
class TenantController extends Controller
{
    public function __construct(private TenantService $tenants) {}

    /**
     * RF-018 y RF-010: listado paginado con búsqueda y filtros.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['activa', 'suspendida', 'cancelada', 'eliminada'])],
            'plan' => ['nullable', Rule::exists('subscription_plans', 'code')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return TenantResource::collection($this->tenants->paginate($filters));
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        /** @var array{name: string, legal_name: string, ruc: string, slug: string, address: string, subscription_plan: string, admin: array{name: string, email: string}} $data */
        $data = $request->validated();
        $tenant = $this->tenants->create($data);

        return TenantResource::make($tenant)
            ->withAdmin($this->tenants->firstAdmin($tenant))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant): TenantResource
    {
        $clinic = $this->tenants->find($tenant);

        return TenantResource::make($clinic)->withAdmin($this->tenants->firstAdmin($clinic));
    }

    public function update(UpdateTenantRequest $request, string $tenant): TenantResource
    {
        /** @var array{name?: string, legal_name?: string, ruc?: string, address?: string} $data */
        $data = $request->validated();
        $clinic = $this->tenants->update($this->tenants->find($tenant), $data);

        return TenantResource::make($clinic)->withAdmin($this->tenants->firstAdmin($clinic));
    }

    /**
     * FA-1 de CUS-01 (RF-016): nuevo enlace de 72 h; el anterior deja de servir.
     */
    public function resendInvitation(string $tenant): Response
    {
        $this->tenants->resendInvitation($this->tenants->find($tenant));

        return response()->noContent();
    }
}
