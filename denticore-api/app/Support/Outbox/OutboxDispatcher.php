<?php

namespace App\Support\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publica en la cola los mensajes confirmados del outbox (SDD §1.9, DD-41, RNF-078).
 *
 * Toma lotes con `FOR UPDATE SKIP LOCKED` (varios despachadores no toman el mismo mensaje),
 * publica el *job* registrado para su tipo y marca `dispatched_at`. Si la cola (Redis) no
 * responde, la transacción se revierte y los mensajes esperan en PostgreSQL.
 */
class OutboxDispatcher
{
    public const BATCH_SIZE = 100;

    /**
     * @return int Mensajes publicados en este lote.
     */
    public function dispatchBatch(): int
    {
        try {
            return DB::transaction(function (): int {
                $messages = OutboxMessage::query()
                    ->whereNull('dispatched_at')
                    ->where('available_at', '<=', now())
                    ->orderBy('id')
                    ->limit(self::BATCH_SIZE)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->get();

                $published = 0;

                foreach ($messages as $message) {
                    $job = $this->jobClassFor($message->type);

                    // Un tipo sin job no bloquea al resto: queda pendiente con el error anotado.
                    if ($job === null) {
                        $message->forceFill([
                            'attempts' => $message->attempts + 1,
                            'last_error' => "Sin job registrado para el tipo {$message->type}",
                        ])->save();

                        continue;
                    }

                    dispatch(new $job($message->uuid, $message->tenant_id))->onQueue($message->queue);

                    $message->forceFill(['dispatched_at' => now(), 'attempts' => $message->attempts + 1])->save();
                    $published++;
                }

                return $published;
            });
        } catch (Throwable $exception) {
            Log::warning('outbox.dispatch_failed', ['error' => $exception->getMessage()]);

            return 0;
        }
    }

    /**
     * @return class-string<OutboxJob>|null
     */
    private function jobClassFor(string $type): ?string
    {
        // Los tipos llevan puntos (p. ej. "notification.send"): se busca la clave literal.
        $job = config('outbox.handlers', [])[$type] ?? null;

        return is_string($job) && is_subclass_of($job, OutboxJob::class) ? $job : null;
    }
}
