<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Base de los *jobs* publicados por el outbox (SDD §1.9). Son idempotentes por el uuid del
 * mensaje: al terminar marcan `consumed_at` y, si ya estaba marcado, no repiten el efecto.
 * Los mensajes de una clínica corren dentro de su contexto (TenantAwareJob).
 */
abstract class OutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $messageUuid, public ?int $tenantId) {}

    /**
     * Efecto del mensaje. Corre en la misma transacción que marca `consumed_at`.
     */
    abstract protected function process(OutboxMessage $message): void;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return $this->tenantId === null ? [] : [new TenantAwareJob];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $message = OutboxMessage::query()->where('uuid', $this->messageUuid)->lockForUpdate()->first();

            if ($message === null || $message->consumed_at !== null) {
                return;
            }

            $this->process($message);

            $message->forceFill(['consumed_at' => now()])->save();
        });
    }
}
