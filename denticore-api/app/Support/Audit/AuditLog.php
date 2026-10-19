<?php

namespace App\Support\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la bitácora (SDD §2.12 `audit_logs`). Solo lectura: la escribe AuditLogger y la BD
 * rechaza UPDATE y DELETE. tenant_id nulable: excepción de BelongsToTenant (SDD §1.6.3).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property string|null $actor_role
 * @property string $action
 * @property string|null $resource_type
 * @property string|null $resource_uuid
 * @property string|null $patient_uuid
 * @property list<string> $changed_fields
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $correlation_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * `changed_fields` es text[] de PostgreSQL ("{a,b}").
     *
     * @return Attribute<list<string>, never>
     */
    protected function changedFields(): Attribute
    {
        return Attribute::get(fn (?string $value): array => $value === null || $value === '{}'
            ? []
            : array_map(fn (string $field): string => trim($field, '"'), explode(',', trim($value, '{}'))));
    }
}
