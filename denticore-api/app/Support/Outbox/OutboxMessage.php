<?php

namespace App\Support\Outbox;

use App\Support\Database\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje del outbox (SDD §2.12, DD-41). tenant_id nulable: está entre las excepciones de
 * BelongsToTenant de SDD §1.6.3 y solo lo leen el despachador y los *jobs*.
 *
 * @property int|null $tenant_id
 * @property array<string, mixed> $payload
 */
#[Fillable(['tenant_id', 'type', 'queue', 'payload', 'available_at'])]
class OutboxMessage extends Model
{
    use HasUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
