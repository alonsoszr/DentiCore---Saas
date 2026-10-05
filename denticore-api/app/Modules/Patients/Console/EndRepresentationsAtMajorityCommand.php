<?php

namespace App\Modules\Patients\Console;

use App\Modules\Patients\Jobs\EndRepresentationsAtMajorityJob;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Console\Command;

/**
 * `representations:end-at-majority` (SDD §1.9; RF-061, RN-13): por cada clínica activa o
 * suspendida, termina las representaciones de los pacientes que ya cumplieron 18 años según la
 * fecha de la clínica. Repetirlo no duplica efectos.
 */
class EndRepresentationsAtMajorityCommand extends Command
{
    protected $signature = 'representations:end-at-majority';

    protected $description = 'Termina las representaciones legales de los pacientes que cumplen 18 años';

    public function handle(): int
    {
        Tenant::query()
            ->whereIn('status', ['activa', 'suspendida'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => EndRepresentationsAtMajorityJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
