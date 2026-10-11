<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\ScheduleBlock;
use Illuminate\Support\Carbon;

/**
 * Bloqueo de capacitación de toda la clínica, mañana de 14:00 a 16:00 UTC.
 *
 * @extends TenantScopedFactory<ScheduleBlock>
 */
class ScheduleBlockFactory extends TenantScopedFactory
{
    /**
     * @var class-string<ScheduleBlock>
     */
    protected $model = ScheduleBlock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::tomorrow()->setTime(14, 0);

        return [
            'tenant_id' => Tenant::factory(),
            'dentist_id' => null,
            'period' => '['.$start->toIso8601String().','.$start->copy()->addHours(2)->toIso8601String().')',
            'kind' => 'capacitacion',
            'reason' => 'Capacitación del personal',
            'created_by' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'clinic_admin'])->id,
        ];
    }
}
