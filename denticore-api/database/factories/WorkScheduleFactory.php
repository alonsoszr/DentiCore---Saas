<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\WorkSchedule;

/**
 * Franja de lunes de 09:00 a 13:00, vigente desde el 2026-01-01, de un odontólogo sintético.
 *
 * @extends TenantScopedFactory<WorkSchedule>
 */
class WorkScheduleFactory extends TenantScopedFactory
{
    /**
     * @var class-string<WorkSchedule>
     */
    protected $model = WorkSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'dentist_id' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'dentist'])->id,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
        ];
    }
}
