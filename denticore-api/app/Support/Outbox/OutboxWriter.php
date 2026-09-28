<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\TenantContext;
use DateTimeInterface;

/**
 * Registra un efecto asíncrono en `outbox_messages` (SDD §1.9, DD-41). Se invoca dentro
 * de la transacción del Service: si la transacción se revierte, el mensaje no existe; si
 * se confirma, el despachador lo publica aunque Redis esté caído en ese momento.
 */
class OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload  Sin datos clínicos ni de identificación en claro.
     */
    public function record(string $type, array $payload, OutboxQueue $queue, ?DateTimeInterface $availableAt = null): OutboxMessage
    {
        return OutboxMessage::query()->create([
            'tenant_id' => TenantContext::id(),
            'type' => $type,
            'queue' => $queue->value,
            'payload' => $payload,
            'available_at' => $availableAt ?? now(),
        ]);
    }
}
