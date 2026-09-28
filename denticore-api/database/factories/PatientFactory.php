<?php

namespace Database\Factories;

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
     * tenant_id debe ir primero: el cast TenantEncrypted lo necesita para cifrar
     * document_id y phone con la clave de esa clínica.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'document_id' => fake()->unique()->numerify('########'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->date(max: '-1 year'),
            'phone' => fake()->numerify('9########'),
            'email' => fake()->safeEmail(),
            'medical_history' => null,
        ];
    }
}
