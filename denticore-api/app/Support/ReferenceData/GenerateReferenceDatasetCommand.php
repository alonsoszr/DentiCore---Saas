<?php

namespace App\Support\ReferenceData;

use Illuminate\Console\Command;

final class GenerateReferenceDatasetCommand extends Command
{
    protected $signature = 'cdr:generate {--seed=42 : Semilla entera de 0 a 999999}';

    protected $description = 'Carga el conjunto sintético de referencia (TASK-072) en staging';

    public function handle(ReferenceDatasetGenerator $generator): int
    {
        if (! app()->environment('staging')) {
            $this->error('El conjunto de referencia solo se genera en staging.');

            return self::FAILURE;
        }

        $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999999]]);
        if ($seed === false) {
            $this->error('La semilla debe ser un entero de 0 a 999999.');

            return self::FAILURE;
        }

        $password = getenv('CDR_STAFF_PASSWORD') ?: $this->secret('Contraseña del personal sintético');
        if (! is_string($password) || strlen($password) < 10 || strlen($password) > 72) {
            $this->error('Indica una contraseña de entre 10 y 72 bytes para las cuentas sintéticas.');

            return self::FAILURE;
        }

        $plan = new ReferenceDatasetPlan($seed);
        $generator->generate($plan, $password);

        $this->table(['Tabla', 'Filas'], array_map(
            static fn (string $table, int $count): array => [$table, $count],
            array_keys($plan->totals()),
            array_values($plan->totals()),
        ));

        return self::SUCCESS;
    }
}
