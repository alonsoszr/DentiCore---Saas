<?php

/*
 * Disponibilidad (TASK-066; SDD §4.3.8, §5.7; CUS-46; RF-146, RN-46 a RN-48, RN-74, RNF-015).
 * La clínica está en America/Lima y el reloj se fija el domingo 01/11/2026.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\AvailabilityCalculator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-01 12:00:00', 'America/Lima')));

/** Instante del lunes 02/11/2026 a la hora local indicada, en UTC. */
function monday(string $time): Carbon
{
    return Carbon::parse("2026-11-02 {$time}", 'America/Lima')->utc();
}

/** @return array{tenant: Tenant, dentist: User, type: AppointmentType} */
function availabilityScenario(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    test()->actingAsRole('receptionist', $tenant);
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist', 'name' => 'Dra. Ana']);
    WorkSchedule::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '11:00']);

    return ['tenant' => $tenant, 'dentist' => $dentist, 'type' => AppointmentType::factory()->create(['tenant_id' => $tenant->id, 'default_duration_min' => 30])];
}

it('groups the free starts by dentist without blocks or active appointments', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'type' => $type] = availabilityScenario();
    Appointment::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'starts_at' => monday('09:00'), 'ends_at' => monday('09:30')]);
    ScheduleBlock::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => null, 'period' => '['.monday('10:30')->toIso8601String().','.monday('11:00')->toIso8601String().')']);

    $this->getJson("/api/v1/availability?appointment_type_id={$type->uuid}&from=2026-11-02&to=2026-11-02")
        ->assertOk()
        ->assertJsonPath('data.0.dentist.id', $dentist->uuid)
        ->assertJsonPath('data.0.duration_min', 30)
        ->assertJsonPath('data.0.slots', [
            monday('09:30')->toIso8601String(), monday('09:45')->toIso8601String(), monday('10:00')->toIso8601String(),
        ]);
})->group('RF-146', 'CUS-46', 'RN-46', 'RN-47');

it('excludes the active appointments of the patient with another dentist and accepts an adjusted duration', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = availabilityScenario();
    $patient = Patient::factory()->for($tenant)->create();
    Appointment::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'starts_at' => monday('10:00'), 'ends_at' => monday('11:00')]);

    $this->getJson("/api/v1/availability?duration_min=60&dentist_id={$dentist->uuid}&patient_id={$patient->uuid}&from=2026-11-02&to=2026-11-02")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.duration_min', 60)
        ->assertJsonPath('data.0.slots', [monday('09:00')->toIso8601String()]);
})->group('RN-74', 'RF-146');

it('rejects an inactive type, invalid durations and invalid date ranges', function (array $query, string $field) {
    ['tenant' => $tenant, 'type' => $type] = availabilityScenario();
    $inactive = AppointmentType::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    $params = ['appointment_type_id' => $type->uuid, 'from' => '2026-11-02', 'to' => '2026-11-02', ...$query];
    if (($params['appointment_type_id'] ?? null) === 'inactive') {
        $params['appointment_type_id'] = $inactive->uuid;
    }

    $this->getJson('/api/v1/availability?'.http_build_query(array_filter($params, fn ($value) => $value !== null)))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'inactive type' => [['appointment_type_id' => 'inactive'], 'appointment_type_id'],
    '12 minutes' => [['appointment_type_id' => null, 'duration_min' => 12], 'duration_min'],
    'past date' => [['from' => '2026-10-31'], 'from'],
    'to before from' => [['to' => '2026-11-01', 'from' => '2026-11-02'], 'to'],
    'more than 31 days' => [['to' => '2026-12-31'], 'to'],
])->group('RN-47', 'RN-48');

it('runs one query per table', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = availabilityScenario();
    $colleague = User::factory()->for($tenant)->create(['role' => 'dentist']);

    $queries = TenantContext::run($tenant, function () use ($dentist, $colleague) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AvailabilityCalculator::class)->slots(collect([$dentist, $colleague]), 30, '2026-11-02', '2026-11-08');

        return collect(DB::getQueryLog())->pluck('query');
    });

    foreach (['work_schedules', 'schedule_blocks', 'appointments'] as $table) {
        expect($queries->filter(fn (string $sql) => str_contains($sql, "from \"{$table}\""))->count())->toBe(1, "Consultas a {$table}");
    }
})->group('RNF-015', 'RF-146');
