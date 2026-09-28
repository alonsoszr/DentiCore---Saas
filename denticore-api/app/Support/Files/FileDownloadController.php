<?php

namespace App\Support\Files;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga con URL firmada de 10 minutos (SDD DI-16, DD-18; ruta de fontanería
 * `files.download`, fuera de SDD §4.3). La firma la emite FileStorage::temporaryUrl después
 * de la Policy; aquí se verifica (middleware `signed`), se entrega solo un archivo `limpio`
 * y se audita la descarga con `document.downloaded`.
 */
class FileDownloadController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function __invoke(Request $request, string $tenant, string $file): StreamedResponse
    {
        $clinic = Tenant::query()->where('uuid', $tenant)->firstOrFail();

        return TenantContext::run($clinic, function () use ($request, $file): StreamedResponse {
            $stored = StoredFile::query()->where('uuid', $file)->firstOrFail();

            abort_unless($stored->isDeliverable(), 404);

            $requester = $request->query('by')
                ? User::query()->where('uuid', $request->query('by'))->first()
                : null;

            $this->audit->record(AuditEvent::DocumentDownloaded, $stored, actor: $requester);

            return Storage::disk($stored->disk)->download($stored->path, $stored->original_name, [
                'Content-Type' => $stored->mime_type,
            ]);
        });
    }
}
