<?php

/*
 * Esquema de agenda (TASK-064; SDD §2.10; DD-09, DI-09, RN-46, RN-74, CUS-50): las restricciones
 * de la base de datos sostienen las reglas aunque falle la validación de la aplicación.
 */

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Código SQLSTATE del error de `$statement` en el contexto de la clínica, o null si no falla. */
function schedulingSqlState(int $tenantId, Closure $statement): ?string
{
    return TenantContext::run($tenantId, function () use ($statement) {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return (string) $exception->getCode();
        }

        return null;
    });
}

it('rejects two overlapping active appointments of the same dentist', function () {
    $first = Appointment::factory()->create(['starts_at' => Carbon::parse('2026-11-02 14:00:00'), 'ends_at' => Carbon::parse('2026-11-02 14:30:00')]);

    $state = schedulingSqlState($first->tenant_id, fn () => Appointment::factory()->create([
        'tenant_id' => $first->tenant_id,
        'dentist_id' => $first->dentist_id,
        'starts_at' => Carbon::parse('2026-11-02 14:15:00'),
        'ends_at' => Carbon::parse('2026-11-02 14:45:00'),
    ]));

    expect($state)->toBe('23P01');
})->group('DD-09', 'RN-46');

it('rejects two overlapping active appointments of the same patient with different dentists', function () {
    $first = Appointment::factory()->create(['starts_at' => Carbon::parse('2026-11-02 14:00:00'), 'ends_at' => Carbon::parse('2026-11-02 14:30:00')]);

    $state = schedulingSqlState($first->tenant_id, fn () => Appointment::factory()->create([
        'tenant_id' => $first->tenant_id,
        'patient_id' => $first->patient_id,
        'starts_at' => Carbon::parse('2026-11-02 14:00:00'),
        'ends_at' => Carbon::parse('2026-11-02 14:30:00'),
    ]));

    expect($state)->toBe('23P01');
})->group('RN-74');

it('does not block the slot of a cancelled appointment', function () {
    $cancelled = Appointment::factory()->create([
        'starts_at' => Carbon::parse('2026-11-02 14:00:00'),
        'ends_at' => Carbon::parse('2026-11-02 14:30:00'),
        'status' => 'cancelada',
        'cancel_reason' => 'paciente',
        'cancelled_at' => now(),
    ]);

    $state = schedulingSqlState($cancelled->tenant_id, fn () => Appointment::factory()->create([
        'tenant_id' => $cancelled->tenant_id,
        'dentist_id' => $cancelled->dentist_id,
        'patient_id' => $cancelled->patient_id,
        'starts_at' => Carbon::parse('2026-11-02 14:00:00'),
        'ends_at' => Carbon::parse('2026-11-02 14:30:00'),
    ]));

    expect($state)->toBeNull();
})->group('DD-09', 'CA-47.5');

it('requires a cancel reason and a duration of 10 to 240 minutes in steps of 5', function () {
    $appointment = Appointment::factory()->create();
    $rows = fn () => DB::table('appointments')->where('id', $appointment->id);

    expect(schedulingSqlState($appointment->tenant_id, fn () => $rows()->update(['status' => 'cancelada'])))->toBe('23514')
        ->and(schedulingSqlState($appointment->tenant_id, fn () => $rows()->update(['ends_at' => $appointment->starts_at->copy()->addMinutes(12)])))->toBe('23514')
        ->and(schedulingSqlState($appointment->tenant_id, fn () => $rows()->update(['ends_at' => $appointment->starts_at->copy()->addMinutes(5)])))->toBe('23514');
})->group('RN-47', 'RF-149');

it('rejects two overlapping work schedule slots of the same dentist on the same weekday', function () {
    $slot = WorkSchedule::factory()->create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00']);
    $create = fn (array $attributes) => fn () => WorkSchedule::factory()->create(['tenant_id' => $slot->tenant_id, 'dentist_id' => $slot->dentist_id, ...$attributes]);

    expect(schedulingSqlState($slot->tenant_id, $create(['weekday' => 1, 'start_time' => '12:00', 'end_time' => '15:00'])))->toBe('23P01')
        // Contigua ([) no se solapa), otro día o vigencia que no se cruza: válidas.
        ->and(schedulingSqlState($slot->tenant_id, $create(['weekday' => 1, 'start_time' => '13:00', 'end_time' => '17:00'])))->toBeNull()
        ->and(schedulingSqlState($slot->tenant_id, $create(['weekday' => 2, 'start_time' => '09:00', 'end_time' => '13:00'])))->toBeNull();
})->group('RF-142', 'DI-09', 'T-124');

it('rejects two attentions linked to the same appointment', function () {
    $appointment = Appointment::factory()->create();
    $attention = fn () => Attention::factory()->create([
        'tenant_id' => $appointment->tenant_id,
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
        'status' => 'cerrada',
    ]);

    expect(schedulingSqlState($appointment->tenant_id, $attention))->toBeNull()
        ->and(schedulingSqlState($appointment->tenant_id, $attention))->toBe('23505');
})->group('CUS-50');

it('isolates the scheduling tables of each clinic under RLS', function () {
    $appointment = Appointment::factory()->create();
    $other = Tenant::factory()->create();

    TenantContext::run($other, function () {
        foreach (['appointments', 'appointment_types', 'work_schedules', 'schedule_blocks'] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
    });
    TenantContext::run($appointment->tenant_id, fn () => expect(DB::table('appointments')->count())->toBe(1));
})->group('DD-40', 'RNF-102');
