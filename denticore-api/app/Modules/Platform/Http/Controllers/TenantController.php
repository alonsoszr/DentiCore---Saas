<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Requests\StoreTenantRequest;
use App\Modules\Platform\Http\Resources\TenantResource;
use App\Modules\Platform\Services\TenantService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Clínicas de la plataforma (M01), exclusivas de super_admin (`role:super_admin`).
 */
class TenantController extends Controller
{
    public function __construct(private TenantService $tenants) {}

    public function index(): AnonymousResourceCollection
    {
        return TenantResource::collection($this->tenants->list());
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = $this->tenants->create(
            $request->safe()->except('admin'),
            $request->validated('admin'),
        );

        return TenantResource::make($tenant)->response()->setStatusCode(201);
    }
}
