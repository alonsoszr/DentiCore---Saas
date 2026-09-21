<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * technical_specs.md §5.1: gestión de clínicas, exclusiva de super_admin
 * (EnsureRole:super_admin en la ruta).
 */
class TenantController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TenantResource::collection(Tenant::query()->latest()->get());
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = Tenant::create($request->validated());

        return TenantResource::make($tenant)->response()->setStatusCode(201);
    }
}
