<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Jobs\MarkNoShowsJob;
use Illuminate\Console\Command;

/**
 * `appointments:mark-no-shows` (SDD §1.9, §5.7; CUS-51, RN-51): por cada clínica activa o
 * suspendida, marca como inasistencia las citas sin check-in 30 minutos después de su fin. Corre
 * cada 5 minutos (RNF-020); repetirlo no duplica efectos.
 */
class MarkNoShowsCommand extends Command
{
    protected $signature = 'appointments:mark-no-shows';

    protected $description = 'Marca las inasistencias de las citas sin check-in 30 minutos después de su fin';

    public function handle(): int
    {
        Tenant::query()
            ->whereIn('status', ['activa', 'suspendida'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => MarkNoShowsJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
