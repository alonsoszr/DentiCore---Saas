<?php

/*
 * Agenda y sala de espera (TASK-069; SDD §4.3.8; CUS-77; RF-153). La clínica está en America/Lima y
 * el reloj se fija el lunes 02/11/2026 a las 11:00.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-02 11:00', 'America/Lima')));

function agendaAt(Tenant $tenant, string $localDateTime, array $attributes = []): Appointment
{
    $start = Carbon::parse($localDateTime, 'America/Lima')->utc();

    return Appointment::factory()->create(['tenant_id' => $tenant->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), ...$attributes]);
}

it('lists the appointments of a local date range for any staff role, filtered by dentist', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $this->actingAsRole('dentist', $tenant);
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $monday = agendaAt($tenant, '2026-11-02 09:00', ['dentist_id' => $dentist->id]);
    $lateMonday = agendaAt($tenant, '2026-11-02 23:30');
    agendaAt($tenant, '2026-11-03 00:00');
    agendaAt($tenant, '2026-11-01 23:30');

    $this->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $monday->uuid)
        ->assertJsonPath('data.1.id', $lateMonday->uuid)
        ->assertJsonPath('data.0.type.color', '#1D4ED8');

    $this->getJson('/api/v1/appointments?from=2026-11-02&to=2026-11-08')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson("/api/v1/appointments?dentist_id={$dentist->uuid}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/appointments/{$monday->uuid}")->assertOk()->assertJsonPath('data.dentist.id', $dentist->uuid);
    $this->getJson('/api/v1/appointments?from=2026-11-01&to=2026-12-15')->assertUnprocessable()->assertJsonValidationErrors('to');
})->group('RF-153', 'CUS-77');

it('shows only the checked-in appointments of today in arrival order in the waiting room', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $this->actingAsRole('receptionist', $tenant);
    $second = agendaAt($tenant, '2026-11-02 10:30', ['status' => 'en_atencion', 'checked_in_at' => now()->subMinutes(5)]);
    $first = agendaAt($tenant, '2026-11-02 11:00', ['status' => 'en_atencion', 'checked_in_at' => now()->subMinutes(20)]);
    agendaAt($tenant, '2026-11-02 12:00');
    agendaAt($tenant, '2026-11-01 10:00', ['status' => 'en_atencion', 'checked_in_at' => now()->subDay()]);

    $this->getJson('/api/v1/waiting-room')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->uuid)
        ->assertJsonPath('data.1.id', $second->uuid);
})->group('RF-153', 'CUS-77');
