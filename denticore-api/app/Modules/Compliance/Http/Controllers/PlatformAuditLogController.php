<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Http\Requests\AuditLogFilterRequest;
use App\Modules\Compliance\Http\Requests\ExportAuditLogRequest;
use App\Modules\Compliance\Http\Resources\PlatformAuditLogResource;
use App\Modules\Compliance\Services\AuditLogExporter;
use App\Modules\Compliance\Services\AuditLogSearch;
use App\Modules\Identity\Models\User;
use App\Support\Http\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bitácora de la plataforma para el Súper Administrador (SDD §4.3.11; CUS-66, RF-187, RN-04):
 * eventos de plataforma y metadatos de todas las clínicas, sin el paciente afectado.
 */
class PlatformAuditLogController extends Controller
{
    public function __construct(
        private AuditLogSearch $search,
        private AuditLogExporter $exporter,
    ) {}

    public function index(AuditLogFilterRequest $request): AnonymousResourceCollection
    {
        /** @var array{from?: string|null, to?: string|null, user_id?: string|null, action?: string|null, resource_type?: string|null, resource_id?: string|null, clinic_id?: string|null, per_page?: int|null} $filters */
        $filters = $request->validated();
        $page = $this->search->query(null, $filters)->paginate($filters['per_page'] ?? 50);
        $this->search->withActors($page->items(), true);

        return PlatformAuditLogResource::collection($page);
    }

    public function export(ExportAuditLogRequest $request): StreamedResponse
    {
        /** @var array{from: string, to: string, user_id?: string|null, action?: string|null, resource_type?: string|null, resource_id?: string|null, clinic_id?: string|null} $filters */
        $filters = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        return $this->exporter->export(null, $filters, $actor);
    }
}
