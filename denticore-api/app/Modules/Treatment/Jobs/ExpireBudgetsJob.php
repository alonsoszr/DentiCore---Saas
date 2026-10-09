<?php

namespace App\Modules\Treatment\Jobs;

use App\Modules\Treatment\Actions\ExpireBudgetAction;
use App\Modules\Treatment\Models\Budget;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Vence los presupuestos emitidos de una clínica con `expires_at <= now()` (SDD §5.4.4; CUS-38,
 * RF-124, RN-35). Corre en el contexto de la clínica (TenantAwareJob); cada presupuesto se vence en
 * su propia transacción con ExpireBudgetAction.
 */
class ExpireBudgetsJob implements ShouldQueue
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

    public function handle(ExpireBudgetAction $expire): void
    {
        Budget::query()
            ->where('status', 'emitido')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(fn (Budget $budget) => $expire->execute($budget));
    }
}
