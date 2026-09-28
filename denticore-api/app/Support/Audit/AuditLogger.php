<?php

namespace App\Support\Audit;

use App\Modules\Identity\Models\User;
use App\Support\Http\CorrelationId;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Bitácora de auditoría (SDD §5.14; CUS-65, RN-67, RF-186). Se invoca en los Services dentro
 * de la misma transacción de la operación. Cada fila guarda usuario, clínica, acción, tipo y
 * uuid del recurso, IP, agente de usuario y nombres de campos cambiados; nunca valores
 * clínicos ni de identificación.
 */
class AuditLogger
{
    /**
     * Claves que nunca pueden ir en `metadata` (datos de identificación o clínicos; ver
     * también el procesador de registros de SDD §1.7).
     */
    private const FORBIDDEN_META_KEYS = '/^(document|phone|email|address|note|token|password|first_name|last_name|name|birth|medical|diagnos|allerg)/i';

    /**
     * @param  list<string>  $changedFields  Solo nombres de campos.
     * @param  array<string, scalar|null>  $meta  Sin datos clínicos ni de identificación.
     * @param  User|null  $actor  Usuario que actúa cuando aún no hay sesión (p. ej. el login).
     */
    public function record(
        AuditEvent $event,
        ?Model $resource = null,
        array $changedFields = [],
        array $meta = [],
        ?User $actor = null,
    ): void {
        $this->guardMetadata($meta);

        $actor ??= auth()->user() instanceof User ? auth()->user() : null;
        // Solo hay datos de red en una solicitud HTTP enrutada (no en jobs ni comandos).
        $request = request()->route() !== null ? request() : null;

        DB::table('audit_logs')->insert([
            'tenant_id' => TenantContext::id(),
            'user_id' => $actor?->id,
            'actor_role' => $actor->role ?? 'system',
            'action' => $event->value,
            'resource_type' => $resource ? Str::snake(class_basename($resource)) : null,
            'resource_uuid' => $resource?->getAttribute('uuid'),
            'patient_uuid' => $resource && method_exists($resource, 'auditPatientUuid') ? $resource->auditPatientUuid() : null,
            'changed_fields' => $changedFields === [] ? null : '{'.implode(',', $changedFields).'}',
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 297) : null,
            'correlation_id' => CorrelationId::current(),
            'metadata' => $meta === [] ? null : json_encode($meta),
            // Los calcula el disparador fn_hash_chain (DI-11).
            'prev_hash' => '',
            'hash' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function guardMetadata(array $meta): void
    {
        foreach (array_keys($meta) as $key) {
            if (preg_match(self::FORBIDDEN_META_KEYS, (string) $key)) {
                throw new LogicException("La bitácora no admite el dato «{$key}» en metadata (RN-67).");
            }
        }
    }
}
