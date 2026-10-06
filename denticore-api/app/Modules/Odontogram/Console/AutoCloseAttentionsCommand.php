<?php

namespace App\Modules\Odontogram\Console;

use App\Modules\Odontogram\Jobs\AutoCloseAttentionsJob;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Console\Command;

/**
 * `attentions:auto-close` (SDD §1.9; CUS-27, RF-096): por cada clínica activa o suspendida,
 * cierra las atenciones y los odontogramas iniciales pendientes cuando son las 23:59 en su
 * zona. Repetirlo no duplica efectos.
 */
class AutoCloseAttentionsCommand extends Command
{
    protected $signature = 'attentions:auto-close';

    protected $description = 'Cierra las atenciones abiertas a las 23:59 de cada clínica';

    public function handle(): int
    {
        Tenant::query()
            ->whereIn('status', ['activa', 'suspendida'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => AutoCloseAttentionsJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
