<?php

namespace App\Modules\Risk\Console;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Jobs\ExpirePredictionsJob;
use Illuminate\Console\Command;

/**
 * `risk:expire-predictions` (SDD §1.9; RN-60): por cada clínica activa o suspendida, vence las
 * predicciones vigentes de más de 12 meses. Corre a diario a las 00:30; repetirlo no tiene efecto.
 */
class ExpirePredictionsCommand extends Command
{
    protected $signature = 'risk:expire-predictions';

    protected $description = 'Vence las predicciones de riesgo vigentes de más de 12 meses';

    public function handle(): int
    {
        Tenant::query()
            ->whereIn('status', ['activa', 'suspendida'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => ExpirePredictionsJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
