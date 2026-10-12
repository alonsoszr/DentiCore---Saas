<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Jobs\SendRemindersJob;
use Illuminate\Console\Command;

/**
 * `appointments:send-reminders` (SDD §1.9; CUS-52, RF-156, RN-52): por cada clínica activa, encola
 * el recordatorio de las citas que empiezan en unas 24 horas. Corre cada 5 minutos; repetirlo no
 * duplica envíos. Una clínica suspendida no envía recordatorios.
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Encola los recordatorios de las citas que empiezan en unas 24 horas';

    public function handle(): int
    {
        Tenant::query()
            ->where('status', 'activa')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $tenantId) => SendRemindersJob::dispatchSync($tenantId));

        return self::SUCCESS;
    }
}
