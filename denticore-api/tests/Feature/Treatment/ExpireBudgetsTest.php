<?php

/*
 * Vencimiento de presupuestos (TASK-059; SDD §5.4.4, §1.9; CUS-38; RF-124, RN-35, RNF-020): la
 * tarea `budgets:expire` vence, por clínica, los emitidos cuya vigencia terminó a las 23:59 locales.
 */

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/**
 * Presupuesto emitido hoy por la recepción de una clínica nueva (vence el 05/11/2026 a las 23:59).
 *
 * @return array{tenant: Tenant, budget: string}
 */
function expiringBudget(): array
{
    $tenant = Tenant::factory()->create();
    test()->actingAsRole('receptionist', $tenant);
    $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'status' => 'propuesto']);
    PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id]);

    $budget = test()->postJson("/api/v1/treatment-plans/{$plan->uuid}/budgets", [], ['Idempotency-Key' => (string) Str::uuid()])->json('data.id');
    test()->postJson("/api/v1/budgets/{$budget}/issue", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    return ['tenant' => $tenant, 'budget' => $budget];
}

function budgetStatus(Tenant $tenant, string $uuid): string
{
    return TenantContext::run($tenant, fn () => Budget::query()->where('uuid', $uuid)->value('status'));
}

it('expires issued budgets at 23:59 clinic time', function () {
    ['tenant' => $tenant, 'budget' => $budget] = expiringBudget();
    ['tenant' => $otherTenant, 'budget' => $otherBudget] = expiringBudget();
    $draft = TenantContext::run($tenant, fn () => Budget::factory()->create(['tenant_id' => $tenant->id])->uuid);

    Carbon::setTestNow(Carbon::parse('2026-11-05 23:58', 'America/Lima'));
    $this->artisan('budgets:expire')->assertSuccessful();
    expect(budgetStatus($tenant, $budget))->toBe('emitido');

    Carbon::setTestNow(Carbon::parse('2026-11-05 23:59:59', 'America/Lima'));
    $this->artisan('budgets:expire')->assertSuccessful();
    $this->artisan('budgets:expire')->assertSuccessful();

    expect(budgetStatus($tenant, $budget))->toBe('vencido')
        ->and(budgetStatus($otherTenant, $otherBudget))->toBe('vencido')
        ->and(budgetStatus($tenant, $draft))->toBe('borrador')
        ->and(TenantContext::run($tenant, fn () => Budget::query()->where('uuid', $budget)->value('expired_at')))->not->toBeNull();

    // Una sola auditoría por presupuesto aunque la tarea corra dos veces.
    TenantContext::run($tenant, fn () => expect(AuditLog::query()->where('action', 'budget.expired')->where('resource_uuid', $budget)->count())->toBe(1));
})->group('T-094', 'RN-35', 'RF-124', 'RNF-020', 'CUS-38');
