<?php

namespace App\Modules\Patients\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Ids de la búsqueda de pacientes (CUS-13; RF-054, DI-14, RNF-007).
 *
 * Con RLS forzada, PostgreSQL no puede usar el índice de trigramas de `search_name` porque
 * `ILIKE` no es *leakproof*: el filtro se evalúa después de la política y la búsqueda recorre
 * la tabla entera. Por eso esta consulta corre con la conexión de plataforma (`pgsql_platform`,
 * BYPASSRLS) y filtra `tenant_id` explícito, como permite SDD §1.6.3 a los repositorios que lo
 * reciben. Solo devuelve ids: las fichas se cargan después con la conexión de la API, bajo RLS y
 * el Global Scope (DD-40).
 */
final class PatientSearchRepository
{
    /** RF-054, RN-68: estados excluidos de la búsqueda salvo filtro explícito. */
    public const HIDDEN_ARCHIVE_STATUSES = ['pasivo', 'bloqueado', 'fusionado'];

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * Nombre o apellido parcial sobre `search_name` (minúsculas, sin tildes) y orden por
     * apellidos y nombres con la colación `es-PE-x-icu` (RNF-190).
     *
     * @param  array{q?: string|null, archive_status?: string|null}  $filters
     */
    public function query(int $tenantId, array $filters): Builder
    {
        $term = isset($filters['q']) ? PatientIdentity::searchName((string) $filters['q'], '') : '';

        return $this->connection->table('patients')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->when($term !== '', fn (Builder $query) => $query->where('search_name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))
            ->when(
                $filters['archive_status'] ?? null,
                fn (Builder $query, string $status) => $query->where('archive_status', $status),
                fn (Builder $query) => $query->whereNotIn('archive_status', self::HIDDEN_ARCHIVE_STATUSES),
            )
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }
}
