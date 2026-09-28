<?php

/*
 * Archivos, documentos generados y URL firmadas (TASK-016; SDD DI-16, DD-18, §5.15;
 * RNF-103, RNF-012).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Files\ClamAvScanner;
use App\Support\Files\DocumentRenderer;
use App\Support\Files\DocumentService;
use App\Support\Files\FileNotDeliverableException;
use App\Support\Files\FileStorage;
use App\Support\Files\GeneratedDocument;
use App\Support\Files\GenerateDocumentJob;
use App\Support\Files\VirusScanner;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\Outbox;

/** Renderizador de prueba para documentos `reporte`. */
class ProbeReportRenderer implements DocumentRenderer
{
    public static bool $fail = false;

    public function html(GeneratedDocument $document): string
    {
        if (self::$fail) {
            throw new RuntimeException('falla la plantilla');
        }

        return '<h1>Reporte de prueba</h1>';
    }

    public function fileName(GeneratedDocument $document): string
    {
        return "reporte-{$document->uuid}.pdf";
    }
}

/** Escáner simulado: marca como infectado el contenido que contiene "VIRUS". */
function fakeScanner(): void
{
    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function isClean($stream): bool
        {
            return ! str_contains((string) stream_get_contents($stream), 'VIRUS');
        }
    });
}

beforeEach(function () {
    Storage::fake('s3');
    ProbeReportRenderer::$fail = false;
    config(['documents.renderers' => ['reporte' => ProbeReportRenderer::class]]);
});

it('stores uploads under the clinic uuid and queues the antivirus scan', function () {
    $tenant = Tenant::factory()->create();

    $file = TenantContext::run($tenant, fn () => app(FileStorage::class)->storeUpload(
        UploadedFile::fake()->createWithContent('radiografia.png', 'contenido'),
    ));

    expect($file->path)->toStartWith("tenants/{$tenant->uuid}/".now()->format('Y').'/')
        ->and($file->scan_status)->toBe('pendiente')
        ->and($file->sha256)->toBe(hash('sha256', 'contenido'))
        ->and($file->tenant_id)->toBe($tenant->id);
    Storage::disk('s3')->assertExists($file->path);
    Outbox::assertRecorded(FileStorage::SCAN_MESSAGE, fn (OutboxMessage $message) => $message->payload['stored_file'] === $file->uuid, times: 1);
})->group('DD-18', 'RNF-101');

it('rejects files over 10 MB', function () {
    $tenant = Tenant::factory()->create();

    expect(fn () => TenantContext::run($tenant, fn () => app(FileStorage::class)->storeUpload(
        UploadedFile::fake()->create('grande.pdf', 10241),
    )))->toThrow(InvalidArgumentException::class);
})->group('RNF-103');

it('scans each upload inside its clinic and never delivers pending or infected files', function () {
    fakeScanner();
    $tenant = Tenant::factory()->create();
    $storage = app(FileStorage::class);

    [$clean, $infected] = TenantContext::run($tenant, fn () => [
        $storage->storeUpload(UploadedFile::fake()->createWithContent('a.pdf', 'limpio')),
        $storage->storeUpload(UploadedFile::fake()->createWithContent('b.pdf', 'VIRUS')),
    ]);

    // Pendiente: todavía no se entrega.
    expect(fn () => TenantContext::run($tenant, fn () => $storage->temporaryUrl($clean)))
        ->toThrow(FileNotDeliverableException::class);

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    [$clean, $infected] = TenantContext::run($tenant, fn () => [$clean->fresh(), $infected->fresh()]);

    expect($clean->scan_status)->toBe('limpio')
        ->and($infected->scan_status)->toBe('infectado')
        ->and(fn () => TenantContext::run($tenant, fn () => $storage->temporaryUrl($infected)))
        ->toThrow(FileNotDeliverableException::class);
})->group('RNF-103', 'RNF-101');

it('serves a clean file through a signed URL that expires after 10 minutes and audits the download', function () {
    fakeScanner();
    $tenant = Tenant::factory()->create();
    $storage = app(FileStorage::class);
    $user = $this->actingAsRole('receptionist', $tenant);

    $file = TenantContext::run($tenant, fn () => $storage->storeUpload(UploadedFile::fake()->createWithContent('a.pdf', 'limpio')));
    $this->artisan('outbox:dispatch', ['--once' => true]);

    $url = TenantContext::run($tenant, fn () => $storage->temporaryUrl($file->fresh(), $user));

    $this->get($url)->assertOk()->assertDownload('a.pdf');

    $download = AuditLog::query()->where('action', 'document.downloaded')->sole();
    expect($download)->tenant_id->toBe($tenant->id)->user_id->toBe($user->id)->resource_uuid->toBe($file->uuid);

    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
})->group('DD-18', 'DI-16', 'RNF-130');

it('does not serve a pending file even with a forged signature', function () {
    $tenant = Tenant::factory()->create();
    $file = TenantContext::run($tenant, fn () => app(FileStorage::class)->storeUpload(UploadedFile::fake()->createWithContent('a.pdf', 'x')));

    $url = URL::temporarySignedRoute('files.download', now()->addMinutes(10), ['tenant' => $tenant->uuid, 'file' => $file->uuid]);

    $this->get($url)->assertNotFound();
})->group('RNF-103');

it('queues document generation on the documents queue', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create();

    TenantContext::run($tenant, fn () => app(DocumentService::class)->request($patient, 'reporte'));
    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    Queue::assertPushedOn('documents', GenerateDocumentJob::class, fn (GenerateDocumentJob $job) => $job->tenantId === $tenant->id);
})->group('DD-18');

it('generates a PDF and links it to the document', function () {
    $tenant = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create();

    $document = TenantContext::run($tenant, fn () => app(DocumentService::class)->request($patient, 'reporte'));
    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    [$document, $file] = TenantContext::run($tenant, function () use ($document) {
        $fresh = $document->fresh();

        return [$fresh, $fresh->storedFile];
    });

    expect($document->status)->toBe('listo')
        ->and($document->completed_at)->not->toBeNull()
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->scan_status)->toBe('limpio')
        ->and(Storage::disk('s3')->get($file->path))->toStartWith('%PDF');
})->group('DD-18', 'RNF-012');

it('marks a document as failed after 3 failed attempts', function () {
    ProbeReportRenderer::$fail = true;
    $tenant = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create();

    $document = TenantContext::run($tenant, fn () => app(DocumentService::class)->request($patient, 'reporte'));
    $message = OutboxMessage::query()->sole();

    foreach ([1, 2, 3] as $attempt) {
        $job = new GenerateDocumentJob($message->uuid, $tenant->id);

        expect(fn () => TenantContext::run($tenant, fn () => $job->handle()))->toThrow(RuntimeException::class);
    }

    $document = TenantContext::run($tenant, fn () => $document->fresh());
    expect($document)->status->toBe('fallido')->attempts->toBe(3)->error->toBe('falla la plantilla');
})->group('RNF-012');

it('detects the EICAR test file with the local ClamAV', function () {
    $scanner = new ClamAvScanner((string) config('services.clamav.host'), (int) config('services.clamav.port'), 10);
    $eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $eicar);
    rewind($stream);
    $clean = fopen('php://memory', 'r+');
    fwrite($clean, 'contenido normal');
    rewind($clean);

    expect($scanner->isClean($stream))->toBeFalse()
        ->and($scanner->isClean($clean))->toBeTrue();
})->skip(fn () => @fsockopen((string) config('services.clamav.host'), (int) config('services.clamav.port'), $code, $error, 1) === false, 'ClamAV no está disponible en este entorno')
    ->group('RNF-103');
