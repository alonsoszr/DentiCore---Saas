<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;

/**
 * Atención abierta de un paciente y un odontólogo sintéticos de la misma clínica (RES-08).
 *
 * @extends TenantScopedFactory<Attention>
 */
class AttentionFactory extends TenantScopedFactory
{
    /**
     * @var class-string<Attention>
     */
    protected $model = Attention::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'dentist_id' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'dentist'])->id,
            'status' => 'abierta',
            'is_first_attention' => false,
            'opened_at' => now(),
        ];
    }
}
