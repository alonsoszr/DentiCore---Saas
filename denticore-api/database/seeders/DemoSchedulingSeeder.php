<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Agenda de demostración de la «Clínica Demo» (solo entorno local; datos sintéticos, RES-08): dos
 * tipos de cita y el horario laboral del odontólogo de demostración. El horario cubre todo el día,
 * todos los días, para poder reservar y registrar la llegada a cualquier hora en las pruebas
 * manuales y en la E2E de agenda. Es idempotente: se puede correr sobre una base ya sembrada con
 * `php artisan db:seed --class=DemoSchedulingSeeder`.
 */
class DemoSchedulingSeeder extends Seeder
{
    /**
     * Nombre => [duración en minutos, color, visible en el portal].
     *
     * @var array<string, array{0: int, 1: string, 2: bool}>
     */
    private const TYPES = [
        'Consulta' => [30, '#0369A1', true],
        'Control' => [20, '#0F766E', false],
    ];

    /**
     * Franjas del odontólogo de demostración: [día ISO, inicio, fin].
     *
     * @var list<array{0: int, 1: string, 2: string}>
     */
    private const SLOTS = [
        [1, '00:00', '23:59'], [2, '00:00', '23:59'], [3, '00:00', '23:59'], [4, '00:00', '23:59'],
        [5, '00:00', '23:59'], [6, '00:00', '23:59'], [7, '00:00', '23:59'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Los datos de demostración solo se cargan en entorno local.');
        }

        $clinic = Tenant::query()->where('slug', 'clinica-demo')->first();
        $dentist = User::query()->where('email', 'dentista@clinica-demo.test')->first();

        if ($clinic === null || $dentist === null) {
            return;
        }

        TenantContext::run($clinic, function () use ($clinic, $dentist): void {
            foreach (self::TYPES as $name => [$duration, $color, $portal]) {
                AppointmentType::query()->firstOrNew(['name' => $name])->forceFill([
                    'tenant_id' => $clinic->id,
                    'default_duration_min' => $duration,
                    'color' => $color,
                    'portal_visible' => $portal,
                    'is_active' => true,
                ])->save();
            }

            if (WorkSchedule::query()->where('dentist_id', $dentist->id)->exists()) {
                return;
            }

            foreach (self::SLOTS as [$weekday, $start, $end]) {
                (new WorkSchedule)->forceFill([
                    'tenant_id' => $clinic->id,
                    'dentist_id' => $dentist->id,
                    'weekday' => $weekday,
                    'start_time' => $start,
                    'end_time' => $end,
                    'valid_from' => '2026-01-01',
                ])->save();
            }
        });
    }
}
