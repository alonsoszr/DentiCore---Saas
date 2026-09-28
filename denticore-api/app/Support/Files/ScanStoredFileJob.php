<?php

namespace App\Support\Files;

use App\Support\Outbox\OutboxJob;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Escaneo antivirus de un archivo subido (SDD DI-16, RNF-103). Corre en la clínica del
 * archivo (TenantAwareJob vía OutboxJob) y deja `limpio` o `infectado`.
 */
class ScanStoredFileJob extends OutboxJob
{
    public int $tries = 3;

    protected function process(OutboxMessage $message): void
    {
        $file = StoredFile::query()->where('uuid', $message->payload['stored_file'] ?? null)->firstOrFail();

        $stream = Storage::disk($file->disk)->readStream($file->path);

        if (! is_resource($stream)) {
            throw new RuntimeException('No se pudo leer el archivo a escanear.');
        }

        try {
            $clean = app(VirusScanner::class)->isClean($stream);
        } finally {
            fclose($stream);
        }

        $file->forceFill(['scan_status' => $clean ? 'limpio' : 'infectado'])->save();
    }
}
