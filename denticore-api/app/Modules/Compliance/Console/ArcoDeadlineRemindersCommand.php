<?php

namespace App\Modules\Compliance\Console;

use App\Modules\Compliance\Jobs\ArcoDeadlineRemindersJob;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Console\Command;

/**
 * `arco:deadline-reminders` (SDD §1.9; CUS-64 FA-2, RF-185): por cada clínica activa o suspendida,
 * avisa de las solicitudes ARCO a 2 días hábiles de su plazo. Corre a diario a las 08:00; repetirlo
 * no duplica avisos.
 */
class ArcoDeadlineRemindersCommand extends Command
{
    protected $signature = 'arco:deadline-reminders';

    protected $description = 'Avisa al Oficial y a los administradores de las solicitudes ARCO próximas a vencer';

    public function handle(): int
    {
        Tenant::query()
            ->whereIn('status', ['activa', 'suspendida'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => ArcoDeadlineRemindersJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
