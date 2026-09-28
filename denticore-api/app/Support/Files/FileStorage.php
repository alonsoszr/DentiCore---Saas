<?php

namespace App\Support\Files;

use App\Modules\Identity\Models\User;
use App\Support\Outbox\OutboxQueue;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Punto único de archivos (SDD DI-16, DD-18, RNF-103): guarda en el disco `s3` con la ruta
 * `tenants/{tenant_uuid}/{yyyy}/{uuid}`, encola el escaneo antivirus de lo subido por
 * usuarios y emite la URL firmada de 10 minutos, solo para archivos `limpio`.
 */
class FileStorage
{
    public const DISK = 's3';

    public const URL_MINUTES = 10;

    public const SCAN_MESSAGE = 'stored_file.scan';

    public function __construct(private OutboxWriter $outbox) {}

    /**
     * Archivo subido por un usuario: queda `pendiente` hasta que el antivirus lo revise.
     */
    public function storeUpload(UploadedFile $file, ?User $uploader = null): StoredFile
    {
        if ($file->getSize() > StoredFile::MAX_BYTES) {
            throw new InvalidArgumentException('El archivo supera el máximo de 10 MB.');
        }

        $contents = (string) file_get_contents($file->getRealPath());

        return DB::transaction(function () use ($file, $contents, $uploader): StoredFile {
            $stored = $this->put(
                $contents,
                (string) $file->getClientOriginalName(),
                // Tipo detectado por el contenido, no por la extensión (RNF-103).
                (string) $file->getMimeType(),
                'pendiente',
                $uploader,
            );

            $this->outbox->record(self::SCAN_MESSAGE, ['stored_file' => $stored->uuid], OutboxQueue::Documents);

            return $stored;
        });
    }

    /**
     * Documento generado por el sistema (PDF, exportación): no viene de un usuario, así que
     * no pasa por el antivirus.
     */
    public function storeGenerated(string $contents, string $originalName, string $mimeType): StoredFile
    {
        return $this->put($contents, $originalName, $mimeType, 'limpio', null);
    }

    /**
     * URL firmada de 10 minutos. Se emite después de que la Policy autorizó al solicitante.
     *
     * @throws FileNotDeliverableException
     */
    public function temporaryUrl(StoredFile $file, ?User $requester = null): string
    {
        if (! $file->isDeliverable()) {
            throw new FileNotDeliverableException($file->scan_status);
        }

        return URL::temporarySignedRoute('files.download', now()->addMinutes(self::URL_MINUTES), array_filter([
            'tenant' => TenantContext::tenantOrFail()->uuid,
            'file' => $file->uuid,
            'by' => $requester?->uuid,
        ]));
    }

    private function put(string $contents, string $originalName, string $mimeType, string $scanStatus, ?User $uploader): StoredFile
    {
        if (strlen($contents) > StoredFile::MAX_BYTES) {
            throw new InvalidArgumentException('El archivo supera el máximo de 10 MB.');
        }

        $uuid = (string) Str::uuid();
        $path = sprintf('tenants/%s/%s/%s', TenantContext::tenantOrFail()->uuid, now()->format('Y'), $uuid);

        Storage::disk(self::DISK)->put($path, $contents);

        $stored = new StoredFile([
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => Str::limit($originalName, 197),
            'mime_type' => Str::limit($mimeType, 77),
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'scan_status' => $scanStatus,
            'uploaded_by' => $uploader?->id,
        ]);
        $stored->uuid = $uuid;
        $stored->save();

        return $stored;
    }
}
