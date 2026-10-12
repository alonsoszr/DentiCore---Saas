<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Scheduling\Models\Notification;
use Illuminate\Console\Command;

/**
 * `notifications:prune-inapp` (SDD §1.9; CUS-53, RF-158): elimina los avisos in-app de más de 90
 * días. `notifications` no tiene RLS (tenant_id puede ser nulo), así que corre para todas las
 * clínicas a la vez. Los correos se conservan como evidencia del envío.
 */
class PruneInAppNotificationsCommand extends Command
{
    public const RETENTION_DAYS = 90;

    protected $signature = 'notifications:prune-inapp';

    protected $description = 'Elimina los avisos in-app de más de 90 días';

    public function handle(): int
    {
        $deleted = Notification::query()
            ->where('channel', 'in_app')
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();

        $this->info("Avisos in-app eliminados: {$deleted}.");

        return self::SUCCESS;
    }
}
