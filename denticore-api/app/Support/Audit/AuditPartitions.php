<?php

namespace App\Support\Audit;

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Connection;

/**
 * Particiones anuales de las tablas particionadas por fecha (SDD DI-17, supuesto S-10 del
 * plan): se crean para el año en curso y los dos siguientes, y un comando programado las
 * extiende. La crea el rol propietario del esquema (conexión `pgsql_migrator`). Si la tabla
 * padre tiene RLS, cada partición nueva la recibe también (`odontogram_entries`).
 */
final class AuditPartitions
{
    public const YEARS_AHEAD = 2;

    /**
     * @return list<string> Particiones creadas.
     */
    public static function ensure(Connection $connection, string $table, int $fromYear): array
    {
        $created = [];
        $rowLevelSecurity = (bool) $connection->selectOne('select relrowsecurity as enabled from pg_class where oid = to_regclass(?)', [$table])?->enabled;

        foreach (range($fromYear, $fromYear + self::YEARS_AHEAD) as $year) {
            $partition = "{$table}_y{$year}";

            $exists = $connection->selectOne('select to_regclass(?) is not null as present', [$partition])->present;

            if ($exists) {
                continue;
            }

            $connection->statement(sprintf(
                "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%d-01-01 00:00:00+00') TO ('%d-01-01 00:00:00+00')",
                $partition,
                $table,
                $year,
                $year + 1,
            ));

            if ($rowLevelSecurity) {
                RowLevelSecurity::enable($partition, $connection);
            }

            $created[] = $partition;
        }

        return $created;
    }
}
