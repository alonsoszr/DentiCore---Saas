<?php

namespace App\Support\Files;

use App\Modules\Identity\Models\User;
use App\Support\Outbox\OutboxQueue;
use App\Support\Outbox\OutboxWriter;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de documentos generados (SDD DD-18, DI-16): crea el documento `pendiente` y el
 * mensaje de outbox en la transacción del Service que lo pide; la cola `documents` lo genera.
 */
class DocumentService
{
    public const GENERATE_MESSAGE = 'document.generate';

    public function __construct(private OutboxWriter $outbox) {}

    public function request(Model $documentable, string $kind, ?User $requester = null): GeneratedDocument
    {
        $document = GeneratedDocument::query()->create([
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'kind' => $kind,
            'status' => 'pendiente',
            'requested_by' => $requester?->id,
        ]);

        $this->outbox->record(self::GENERATE_MESSAGE, ['document' => $document->uuid], OutboxQueue::Documents);

        return $document;
    }
}
