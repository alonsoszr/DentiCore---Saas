<?php

namespace App\Modules\Compliance\Console;

use App\Modules\Compliance\Jobs\ApplyRetentionPolicyJob;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Console\Command;

/** Archivo diario a las 02:00, sin eliminación clínica (SDD §1.9, §5.12; RF-190). */
class ApplyRetentionPolicyCommand extends Command
{
    protected $signature = 'retention:apply';

    protected $description = 'Aplica los plazos de archivo pasivo y aptitud para eliminación de las historias clínicas';

    public function handle(): int
    {
        Tenant::query()
            ->whereIn('status', ['activa', 'suspendida'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => ApplyRetentionPolicyJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
