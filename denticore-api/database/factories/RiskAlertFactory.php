<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Models\RiskPrediction;
use App\Support\Tenancy\TenantContext;

/**
 * Alerta abierta de una predicción de nivel alto, dirigida al odontólogo que la pidió (RN-61).
 *
 * @extends TenantScopedFactory<RiskAlert>
 */
class RiskAlertFactory extends TenantScopedFactory
{
    /**
     * @var class-string<RiskAlert>
     */
    protected $model = RiskAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'risk_prediction_id' => fn (array $attributes) => RiskPrediction::factory()->high()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'prediction_level' => 'alto',
            'patient_id' => fn (array $attributes) => $this->prediction($attributes)->patient_id,
            'dentist_id' => fn (array $attributes) => $this->prediction($attributes)->requested_by,
            'status' => 'abierta',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function prediction(array $attributes): RiskPrediction
    {
        return TenantContext::run(
            (int) $attributes['tenant_id'],
            fn () => RiskPrediction::query()->findOrFail($attributes['risk_prediction_id']),
        );
    }
}
