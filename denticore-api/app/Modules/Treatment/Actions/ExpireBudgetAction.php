<?php

namespace App\Modules\Treatment\Actions;

use App\Modules\Treatment\Models\Budget;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Vence un presupuesto emitido cuya vigencia terminó (SDD §5.4.3 paso 3, §5.4.4; CUS-38; RN-35,
 * RF-124). Corre en su propia transacción para que el vencimiento quede confirmado aunque la
 * decisión que lo detectó responda 409. Repetirlo no duplica efectos.
 */
class ExpireBudgetAction
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @return bool Si el presupuesto pasó a `vencido` en esta llamada.
     */
    public function execute(Budget $budget): bool
    {
        return DB::transaction(function () use ($budget): bool {
            $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->first();

            if ($budget === null || $budget->status !== 'emitido' || $budget->expires_at === null || $budget->expires_at->isAfter(now())) {
                return false;
            }

            $budget->forceFill(['status' => 'vencido', 'expired_at' => now()])->save();
            $this->audit->record(AuditEvent::BudgetExpired, $budget, ['status']);

            return true;
        });
    }
}
