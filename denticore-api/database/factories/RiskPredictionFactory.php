<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\ModelVersion;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Predicción vigente de nivel bajo (p = 0,20) sobre un conjunto de variables bloqueado, con los
 * umbrales por defecto 0,30/0,60 (RN-59). El estado `high()` la deja en nivel alto (p = 0,72).
 *
 * @extends TenantScopedFactory<RiskPrediction>
 */
class RiskPredictionFactory extends TenantScopedFactory
{
    /**
     * @var class-string<RiskPrediction>
     */
    protected $model = RiskPrediction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'risk_variable_set_id' => fn (array $attributes) => RiskVariableSet::factory()
                ->create(['tenant_id' => $attributes['tenant_id'], 'locked_at' => now()])->id,
            'patient_id' => fn (array $attributes) => TenantContext::run(
                (int) $attributes['tenant_id'],
                fn () => RiskVariableSet::query()->whereKey($attributes['risk_variable_set_id'])->value('patient_id'),
            ),
            'model_version_id' => fn () => ModelVersion::factory()->create()->id,
            'probability' => '0.2000',
            'level' => 'bajo',
            'confidence' => '0.8000',
            'threshold_medium' => '0.300',
            'threshold_high' => '0.600',
            'base_value' => '-0.847298',
            'explanation_global' => [['feature' => 'active_lesions', 'mean_abs_shap' => 0.377]],
            'explanation_individual' => [['feature' => 'active_lesions', 'value' => '2', 'shap' => 0.521]],
            'status' => 'vigente',
            'requested_by' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'dentist'])->id,
            'predicted_at' => fn () => Carbon::now()->utc(),
            'expires_at' => fn (array $attributes) => Carbon::parse($attributes['predicted_at'])->utc()->addMonthsNoOverflow(12),
            'request_ref' => fn () => (string) Str::uuid(),
            'latency_ms' => 120,
        ];
    }

    public function high(): static
    {
        return $this->state(fn () => ['probability' => '0.7200', 'level' => 'alto', 'confidence' => '0.7200']);
    }
}
