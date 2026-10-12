<?php

namespace App\Modules\Risk\Jobs;

use App\Modules\Risk\Models\RiskPrediction;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Marca como `vencida` la predicción vigente de una clínica cuyos 12 meses terminaron (SDD §1.9,
 * §2.11; RN-60). Corre en el contexto de la clínica (TenantAwareJob); solo cambia `status`, la
 * única columna que el disparador de `risk_predictions` permite modificar.
 */
class ExpirePredictionsJob implements ShouldQueue
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

    public function handle(): void
    {
        RiskPrediction::query()
            ->where('status', 'vigente')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'vencida']);
    }
}
