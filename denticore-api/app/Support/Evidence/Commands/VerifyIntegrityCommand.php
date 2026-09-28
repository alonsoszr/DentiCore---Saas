<?php

namespace App\Support\Evidence\Commands;

use App\Support\Evidence\HashChainVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verificación diaria de integridad (SDD §1.9 `integrity:verify`, 04:00; RNF-089). Recorre las
 * cadenas de hashes registradas en config/integrity.php con el rol de plataforma; cada módulo
 * agrega sus invariantes. Una violación se registra y el comando termina con error (la alerta
 * de M13 se agrega con `performance_alerts` en MS-14).
 */
class VerifyIntegrityCommand extends Command
{
    protected $signature = 'integrity:verify {--connection=pgsql_platform}';

    protected $description = 'Verifica las cadenas de hashes y las invariantes de integridad';

    public function handle(HashChainVerifier $verifier): int
    {
        $connection = DB::connection((string) $this->option('connection'));
        $violations = 0;

        foreach (config('integrity.chains', []) as $chain) {
            $broken = $verifier->brokenRows($connection, $chain['table'], $chain['scope'], $chain['exclude'] ?? null);

            if ($broken === []) {
                $this->info("{$chain['table']}: cadena íntegra");

                continue;
            }

            $violations += count($broken);
            $this->error("{$chain['table']}: filas alteradas ".implode(', ', $broken));
            Log::critical('integrity.violation', ['table' => $chain['table'], 'rows' => $broken]);
        }

        return $violations === 0 ? self::SUCCESS : self::FAILURE;
    }
}
