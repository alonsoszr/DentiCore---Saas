<?php

namespace Database\Factories;

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Odontograma inicial abierto en la primera atención del paciente (RN-20).
 *
 * @extends TenantScopedFactory<InitialOdontogram>
 */
class InitialOdontogramFactory extends TenantScopedFactory
{
    /**
     * @var class-string<InitialOdontogram>
     */
    protected $model = InitialOdontogram::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'attention_id' => fn (array $attributes) => Attention::factory()->create(['tenant_id' => $attributes['tenant_id'], 'is_first_attention' => true])->id,
            'patient_id' => fn (array $attributes) => TenantContext::run(
                $attributes['tenant_id'],
                fn () => Attention::query()->whereKey($attributes['attention_id'])->value('patient_id'),
            ),
            'status' => 'abierto',
        ];
    }
}
