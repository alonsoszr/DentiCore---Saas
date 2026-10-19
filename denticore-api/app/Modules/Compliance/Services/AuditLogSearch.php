<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Time\ClinicClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consulta de la bitácora (SDD §2.12; CUS-66, RF-187, RN-04, RNF-024). `audit_logs` no tiene RLS:
 * el alcance se fija aquí. El Administrador de Clínica ve solo su clínica; el Súper Administrador,
 * todas y los eventos de plataforma (`tenant_id` nulo), o una clínica con el filtro `clinic_id`.
 *
 * Los filtros por fecha usan la fecha local de la clínica (o de Lima en la plataforma) y, con el
 * usuario, el índice `(tenant_id, user_id, created_at)` de cada partición.
 */
class AuditLogSearch
{
    /**
     * @param  int|null  $tenantId  Clínica a la que se limita la consulta; null en la plataforma.
     * @param  array{from?: string|null, to?: string|null, user_id?: string|null, action?: string|null, resource_type?: string|null, resource_id?: string|null, clinic_id?: string|null}  $filters
     * @return Builder<AuditLog>
     */
    public function query(?int $tenantId, array $filters): Builder
    {
        $clock = ClinicClock::for($tenantId === null ? null : Tenant::query()->find($tenantId));
        $tenantId ??= isset($filters['clinic_id']) ? (int) (Tenant::query()->where('uuid', $filters['clinic_id'])->value('id') ?? 0) : null;

        return AuditLog::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $clock->startOfLocalDay($from)))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', $clock->endOfLocalDay($to)))
            ->when($filters['user_id'] ?? null, fn (Builder $query, string $uuid) => $query->where('user_id', $this->userId($uuid, $tenantId)))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['resource_type'] ?? null, fn (Builder $query, string $type) => $query->where('resource_type', $type))
            ->when($filters['resource_id'] ?? null, fn (Builder $query, string $uuid) => $query->where('resource_uuid', $uuid))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Carga el usuario y la clínica de cada fila con dos consultas, sin unir tablas a la consulta
     * principal (así conserva su plan sobre las particiones).
     *
     * @param  iterable<AuditLog>  $logs
     */
    public function withActors(iterable $logs, bool $withClinic): void
    {
        $logs = collect($logs);
        /** @var Collection<int, User> $users */
        $users = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique()->values())->get(['id', 'uuid', 'name'])->keyBy('id');
        $tenants = $withClinic
            ? Tenant::query()->whereIn('id', $logs->pluck('tenant_id')->filter()->unique()->values())->get(['id', 'uuid', 'name'])->keyBy('id')
            : collect();

        foreach ($logs as $log) {
            $log->setRelation('user', $users->get($log->getAttribute('user_id')));

            if ($withClinic) {
                $log->setRelation('clinic', $tenants->get($log->getAttribute('tenant_id')));
            }
        }
    }

    /**
     * Id del usuario por su uuid, dentro de la clínica si la hay; un uuid ajeno no encuentra filas.
     */
    private function userId(string $uuid, ?int $tenantId): int
    {
        return (int) (User::query()
            ->where('uuid', $uuid)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->value('id') ?? 0);
    }
}
