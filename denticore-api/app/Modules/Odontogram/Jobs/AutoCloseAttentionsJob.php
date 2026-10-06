<?php

namespace App\Modules\Odontogram\Jobs;

use App\Modules\Odontogram\Services\AttentionService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Cierra las atenciones abiertas de una clínica a las 23:59 de su zona (SDD §4.4; CUS-27,
 * RF-096, RN-20, RN-77). Corre en el contexto de la clínica (TenantAwareJob).
 */
class AutoCloseAttentionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public ?int $tenantId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(AttentionService $attentions): void
    {
        $attentions->autoClose(Tenant::query()->findOrFail($this->tenantId));
    }
}
