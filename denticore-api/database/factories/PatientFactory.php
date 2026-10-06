<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;

/**
 * @extends TenantScopedFactory<Patient>
 */
class PatientFactory extends TenantScopedFactory
{
    /**
     * @var class-string<Patient>
     */
    protected $model = Patient::class;

    /**
     * tenant_id debe ir primero: el cast TenantEncrypted lo necesita para cifrar el documento y
     * el teléfono con la clave de esa clínica. El modelo deriva los índices ciegos y la HC.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'document_type' => 'dni',
            'document_number' => fake()->unique()->numerify('########'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->date(max: '-1 year'),
            'sex' => fake()->randomElement(['femenino', 'masculino']),
            'phone' => fake()->numerify('9########'),
            'email' => fake()->safeEmail(),
            'medical_history' => null,
            // RN-67: el personal de la clínica que registró la ficha.
            'created_by' => fn (array $attributes) => User::query()
                ->where('tenant_id', $attributes['tenant_id'])
                ->whereIn('role', ['clinic_admin', 'receptionist', 'dentist'])
                ->value('id')
                ?? User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'receptionist'])->id,
        ];
    }
}
