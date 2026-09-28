<?php

namespace App\Support\Files;

use App\Support\Outbox\OutboxJob;
use App\Support\Outbox\OutboxMessage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Genera un documento PDF en la cola `documents` (SDD §1.9, DD-18; RNF-012), con 3 intentos.
 * El contenido lo produce el renderizador registrado para su tipo en config/documents.php.
 */
class GenerateDocumentJob extends OutboxJob
{
    public int $tries = GeneratedDocument::MAX_ATTEMPTS;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function handle(): void
    {
        try {
            parent::handle();
        } catch (Throwable $exception) {
            $this->recordFailedAttempt($exception);

            throw $exception;
        }
    }

    protected function process(OutboxMessage $message): void
    {
        $document = $this->document($message);
        $document->forceFill(['status' => 'generando'])->save();

        $renderer = $this->rendererFor($document->kind);
        $pdf = Pdf::loadHTML($renderer->html($document))->output();

        $file = app(FileStorage::class)->storeGenerated($pdf, $renderer->fileName($document), 'application/pdf');

        $document->forceFill([
            'status' => 'listo',
            'stored_file_id' => $file->id,
            'completed_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * Cada intento fallido queda registrado fuera de la transacción revertida; al tercero el
     * documento pasa a `fallido`.
     */
    private function recordFailedAttempt(Throwable $exception): void
    {
        $message = OutboxMessage::query()->where('uuid', $this->messageUuid)->first();
        $document = $message ? GeneratedDocument::query()->where('uuid', $message->payload['document'] ?? null)->first() : null;

        if ($document === null) {
            return;
        }

        $attempts = $document->attempts + 1;

        $document->forceFill([
            'attempts' => $attempts,
            'status' => $attempts >= GeneratedDocument::MAX_ATTEMPTS ? 'fallido' : 'pendiente',
            'error' => Str::limit($exception->getMessage(), 297),
        ])->save();
    }

    private function document(OutboxMessage $message): GeneratedDocument
    {
        return GeneratedDocument::query()->where('uuid', $message->payload['document'] ?? null)->firstOrFail();
    }

    private function rendererFor(string $kind): DocumentRenderer
    {
        $renderer = config('documents.renderers', [])[$kind] ?? null;

        if (! is_string($renderer) || ! is_subclass_of($renderer, DocumentRenderer::class)) {
            throw new RuntimeException("No hay un renderizador registrado para documentos de tipo «{$kind}».");
        }

        return app($renderer);
    }
}
