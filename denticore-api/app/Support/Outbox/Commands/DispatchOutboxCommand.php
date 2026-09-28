<?php

namespace App\Support\Outbox\Commands;

use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Despachador del outbox (SDD §1.2, §1.9): proceso largo que publica lotes de 100 mensajes.
 * `--once` procesa un solo lote (pruebas y ejecución manual).
 */
class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--once : Procesa un solo lote y termina} {--sleep=1 : Segundos de espera cuando no hay mensajes}';

    protected $description = 'Publica en las colas los mensajes confirmados del outbox';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        do {
            $published = $dispatcher->dispatchBatch();

            if ($this->option('once')) {
                $this->info("Mensajes publicados: {$published}");

                return self::SUCCESS;
            }

            if ($published === 0) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (true);
    }
}
