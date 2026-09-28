<?php

namespace App\Support\Outbox\Commands;

use App\Support\Outbox\OutboxMessage;
use Illuminate\Console\Command;

/**
 * Borra los mensajes despachados hace más de 7 días (SDD §1.9, `outbox:prune` horario).
 */
class PruneOutboxCommand extends Command
{
    protected $signature = 'outbox:prune';

    protected $description = 'Elimina los mensajes del outbox despachados hace más de 7 días';

    public function handle(): int
    {
        $deleted = OutboxMessage::query()
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', now()->subDays((int) config('outbox.prune_after_days')))
            ->delete();

        $this->info("Mensajes eliminados: {$deleted}");

        return self::SUCCESS;
    }
}
