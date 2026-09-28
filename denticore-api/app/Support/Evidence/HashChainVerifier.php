<?php

namespace App\Support\Evidence;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Verificación de las cadenas de hashes (SDD §1.7, §1.9; DD-46, DI-11, RNF-089, RNF-114).
 *
 * Recalcula en PostgreSQL, con la misma expresión que `fn_hash_chain`, el hash de cada fila y
 * comprueba que `prev_hash` sea el hash de la fila anterior de su cadena. Devuelve el id de
 * cada fila rota (contenido alterado o eslabón que no enlaza).
 */
class HashChainVerifier
{
    /**
     * @return list<int>
     */
    public function brokenRows(ConnectionInterface $connection, string $table, string $scopeColumn, ?string $excludedColumn = null): array
    {
        foreach ([$table, $scopeColumn, $excludedColumn ?? 'hash'] as $identifier) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
                throw new InvalidArgumentException("Identificador no válido: {$identifier}");
            }
        }

        $excluded = $excludedColumn === null ? "''" : "'{$excludedColumn}'";

        $rows = $connection->select(<<<SQL
            SELECT id FROM (
                SELECT t.id, t.hash, t.prev_hash,
                       lag(t.hash) OVER (PARTITION BY t.{$scopeColumn} ORDER BY t.id) AS previous_hash,
                       encode(sha256(convert_to(t.prev_hash
                           || (to_jsonb(t) - 'hash' - 'prev_hash' - {$excluded})::text, 'UTF8')), 'hex') AS recomputed
                  FROM {$table} t
            ) chain
            WHERE hash <> recomputed OR prev_hash <> coalesce(previous_hash, repeat('0', 64))
            ORDER BY id
            SQL);

        return array_map(fn (object $row): int => (int) $row->id, $rows);
    }
}
