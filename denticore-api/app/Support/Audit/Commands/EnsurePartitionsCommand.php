<?php

namespace App\Support\Audit\Commands;

use App\Support\Audit\AuditPartitions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando de fontanería (supuesto S-10, DI-17): mantiene creadas las particiones anuales del
 * año en curso y los dos siguientes. Corre con el rol propietario del esquema.
 */
class EnsurePartitionsCommand extends Command
{
    /**
     * Tablas particionadas por año.
     *
     * @var list<string>
     */
    private const TABLES = ['audit_logs', 'odontogram_entries'];

    protected $signature = 'partitions:ensure {--connection=pgsql_migrator}';

    protected $description = 'Crea las particiones anuales de las tablas particionadas (año en curso y dos siguientes)';

    public function handle(): int
    {
        $connection = DB::connection((string) $this->option('connection'));

        foreach (self::TABLES as $table) {
            foreach (AuditPartitions::ensure($connection, $table, (int) now()->format('Y')) as $partition) {
                $this->info("Partición creada: {$partition}");
            }
        }

        return self::SUCCESS;
    }
}
