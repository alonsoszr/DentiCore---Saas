<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Http\Requests\AuditLogFilterRequest;
use App\Modules\Compliance\Http\Requests\ExportAuditLogRequest;
use App\Modules\Compliance\Http\Resources\AuditLogResource;
use App\Modules\Compliance\Services\AuditLogExporter;
use App\Modules\Compliance\Services\AuditLogSearch;
use App\Modules\Identity\Models\User;
use App\Support\Http\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bitácora de la propia clínica para su Administrador (SDD §4.3.11; CUS-66, RF-187): consulta
 * paginada con filtros y exportación CSV del rango pedido.
 */
class AuditLogController extends Controller
{
    public function __construct(
        private AuditLogSearch $search,
        private AuditLogExporter $exporter,
    ) {}

    public function index(AuditLogFilterRequest $request): AnonymousResourceCollection
    {
        /** @var array{from?: string|null, to?: string|null, user_id?: string|null, action?: string|null, resource_type?: string|null, resource_id?: string|null, per_page?: int|null} $filters */
        $filters = $request->validated();
        $page = $this->search->query(TenantContext::idOrFail(), $filters)->paginate($filters['per_page'] ?? 50);
        $this->search->withActors($page->items(), false);

        return AuditLogResource::collection($page);
    }

    public function export(ExportAuditLogRequest $request): StreamedResponse
    {
        /** @var array{from: string, to: string, user_id?: string|null, action?: string|null, resource_type?: string|null, resource_id?: string|null} $filters */
        $filters = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        return $this->exporter->export(TenantContext::tenantOrFail(), $filters, $actor);
    }
}
