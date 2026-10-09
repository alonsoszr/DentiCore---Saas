<?php

namespace App\Modules\Treatment\Console;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Jobs\ExpireBudgetsJob;
use Illuminate\Console\Command;

/**
 * `budgets:expire` (SDD §1.9, §5.4.4; CUS-38, RF-124, RN-35): por cada clínica activa o
 * suspendida, vence los presupuestos emitidos cuya vigencia terminó a las 23:59 de su zona.
 * Corre cada 5 minutos (RNF-020); repetirlo no duplica efectos.
 */
class ExpireBudgetsCommand extends Command
{
    protected $signature = 'budgets:expire';

    protected $description = 'Vence los presupuestos emitidos cuya vigencia terminó en cada clínica';

    public function handle(): int
    {
        Tenant::query()
            ->whereIn('status', ['activa', 'suspendida'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => ExpireBudgetsJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
