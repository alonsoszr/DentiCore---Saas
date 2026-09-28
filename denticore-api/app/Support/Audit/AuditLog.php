<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la bitácora (SDD §2.12 `audit_logs`). Solo lectura: la escribe AuditLogger y la BD
 * rechaza UPDATE y DELETE. tenant_id nulable: excepción de BelongsToTenant (SDD §1.6.3).
 *
 * @property list<string> $changed_fields
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
