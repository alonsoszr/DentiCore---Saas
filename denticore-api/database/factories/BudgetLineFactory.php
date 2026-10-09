<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Models\PlanItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Línea de un presupuesto en borrador, copiada de un ítem nuevo de su plan (RF-118).
 *
 * @extends TenantScopedFactory<BudgetLine>
 */
class BudgetLineFactory extends TenantScopedFactory
{
    /**
     * @var class-string<BudgetLine>
     */
    protected $model = BudgetLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'budget_id' => fn (array $attributes) => Budget::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'plan_item_id' => fn (array $attributes) => PlanItem::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'treatment_plan_id' => TenantContext::run(
                    $attributes['tenant_id'],
                    fn () => DB::table('budgets')->where('id', $attributes['budget_id'])->value('treatment_plan_id'),
                ),
            ])->id,
            'procedure_id' => fn (array $attributes) => TenantContext::run(
                $attributes['tenant_id'],
                fn () => DB::table('plan_items')->where('id', $attributes['plan_item_id'])->value('procedure_id'),
            ),
            'description' => 'Procedimiento de prueba',
            'tooth' => 16,
            'surfaces' => [],
            'unit_price' => '150.00',
            'quantity' => 1,
            'discount_pct' => '0.00',
            'discount_reason' => null,
            'subtotal' => '150.00',
        ];
    }
}
