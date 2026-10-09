<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Ítem propuesto en la siguiente posición del plan, con un procedimiento de la misma clínica.
 *
 * @extends TenantScopedFactory<PlanItem>
 */
class PlanItemFactory extends TenantScopedFactory
{
    /**
     * @var class-string<PlanItem>
     */
    protected $model = PlanItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'treatment_plan_id' => fn (array $attributes) => TreatmentPlan::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'position' => fn (array $attributes) => TenantContext::run(
                $attributes['tenant_id'],
                fn () => (int) DB::table('plan_items')->where('treatment_plan_id', $attributes['treatment_plan_id'])->max('position') + 1,
            ),
            'procedure_id' => fn (array $attributes) => Procedure::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'tooth' => 16,
            'surfaces' => [],
            'quantity' => 1,
            'performed_quantity' => 0,
            'status' => 'propuesto',
            'origin' => 'manual',
        ];
    }
}
