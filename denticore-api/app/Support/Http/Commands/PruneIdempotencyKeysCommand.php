<?php

namespace App\Support\Http\Commands;

use App\Support\Http\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Borra las claves de idempotencia vencidas (24 h; SDD §1.9, `idempotency:prune` horario).
 */
class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Elimina las claves de idempotencia vencidas';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()->where('expires_at', '<=', now())->delete();

        $this->info("Claves eliminadas: {$deleted}");

        return self::SUCCESS;
    }
}
