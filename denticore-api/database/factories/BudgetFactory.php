<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Presupuesto en borrador del plan, del mismo paciente (RN-28).
 *
 * @extends TenantScopedFactory<Budget>
 */
class BudgetFactory extends TenantScopedFactory
{
    /**
     * @var class-string<Budget>
     */
    protected $model = Budget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'treatment_plan_id' => fn (array $attributes) => TreatmentPlan::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'patient_id' => fn (array $attributes) => TenantContext::run(
                $attributes['tenant_id'],
                fn () => DB::table('treatment_plans')->where('id', $attributes['treatment_plan_id'])->value('patient_id'),
            ),
            'status' => 'borrador',
            'prices_include_igv' => true,
            'igv_rate' => '0.1800',
            'subtotal' => '0.00',
            'discount_total' => '0.00',
            'base_amount' => '0.00',
            'igv_amount' => '0.00',
            'total' => '0.00',
        ];
    }
}
