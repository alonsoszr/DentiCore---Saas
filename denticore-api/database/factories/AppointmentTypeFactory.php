<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\AppointmentType;

/**
 * Tipo de cita sintético de 30 minutos (RES-08).
 *
 * @extends TenantScopedFactory<AppointmentType>
 */
class AppointmentTypeFactory extends TenantScopedFactory
{
    /**
     * @var class-string<AppointmentType>
     */
    protected $model = AppointmentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Consulta '.fake()->unique()->numerify('####'),
            'default_duration_min' => 30,
            'color' => '#1D4ED8',
            'portal_visible' => false,
            'is_active' => true,
        ];
    }
}
