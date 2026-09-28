<?php

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Clave de idempotencia guardada (SDD §2.12 `idempotency_keys`, DD-45). Sin tenant_id: la
 * clave es por sujeto (usuario o token público); excepción de BelongsToTenant (§1.6.3).
 *
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 */
#[Fillable(['subject', 'key', 'request_hash', 'method', 'route_name', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyKey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
