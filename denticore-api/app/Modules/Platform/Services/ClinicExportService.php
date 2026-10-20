<?php

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Policies\ClinicExportPolicy;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Files\GeneratedDocument;
use App\Support\Files\StoredFile;
use App\Support\Outbox\OutboxQueue;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Solicitud y acceso a exportaciones (TASK-107; RF-020, RN-07, RN-72). ZIP: TASK-139. */
class ClinicExportService
{
    public const KIND = 'exportacion_clinica';

    /** TASK-139 registrará su generador; mientras tanto el outbox conserva el mensaje pendiente. */
    public const GENERATE_MESSAGE = 'clinic.export';

    public function __construct(private ClinicExportPolicy $policy, private OutboxWriter $outbox, private AuditLogger $audit) {}

    public function request(User $requester): GeneratedDocument
    {
        $tenant = TenantContext::tenantOrFail();
        abort_unless($this->policy->create($requester, $tenant), 403);

        return DB::transaction(function () use ($tenant, $requester): GeneratedDocument {
            $document = GeneratedDocument::query()->create([
                'documentable_type' => $tenant->getMorphClass(), 'documentable_id' => $tenant->id,
                'kind' => self::KIND, 'status' => 'pendiente', 'requested_by' => $requester->id,
            ]);
            $this->outbox->record(self::GENERATE_MESSAGE, ['document' => $document->uuid], OutboxQueue::Heavy);
            $this->audit->record(AuditEvent::ExportRequested, $tenant, meta: ['kind' => self::KIND], actor: $requester);

            return $document;
        });
    }

    public function view(GeneratedDocument $document, User $requester): GeneratedDocument
    {
        abort_unless($this->policy->view($requester, TenantContext::tenantOrFail(), $document), 404);

        return $document->load('storedFile');
    }

    /** Cierra el acceso por URLs firmadas antiguas, también al cruzar el día 90 (RN-07). */
    public function authorizeFileDownload(Tenant $tenant, StoredFile $file, ?User $requester): void
    {
        $export = GeneratedDocument::query()->where('stored_file_id', $file->id)->where('kind', self::KIND)->first();

        if (in_array($tenant->status, ['cancelada', 'eliminada'], true) || $export !== null) {
            abort_unless($export !== null && $requester !== null && $requester->status === 'activo'
                && $this->policy->view($requester, $tenant, $export), 403);
        }

        if ($export !== null) {
            DB::transaction(fn () => $this->audit->record(AuditEvent::ExportDownloaded, $tenant, meta: ['kind' => self::KIND], actor: $requester));
        }
    }
}
