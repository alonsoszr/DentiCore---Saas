<?php

/*
 * Inasistencias (TASK-069; SDD §1.9, §5.7; CUS-51, RN-51, RNF-020; T-121): la tarea, que corre cada
 * 5 minutos, marca las citas sin check-in entre 30 y 35 minutos después de su fin.
 */

use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/** Cita de 10:00 a 10:30 (UTC) del 02/11/2026. */
function noShowCandidate(Tenant $tenant, array $attributes = []): Appointment
{
    return Appointment::factory()->create([
        'tenant_id' => $tenant->id,
        'starts_at' => Carbon::parse('2026-11-02 10:00'), 'ends_at' => Carbon::parse('2026-11-02 10:30'),
        ...$attributes,
    ]);
}

it('marks a no-show between 30 and 35 minutes after the end', function () {
    $tenant = Tenant::factory()->create();
    $scheduled = noShowCandidate($tenant);
    $confirmed = noShowCandidate($tenant, ['status' => 'confirmada', 'confirmed_at' => now()]);
    $status = fn (Appointment $appointment) => TenantContext::run($tenant, fn () => $appointment->fresh()->status);

    $this->travelTo(Carbon::parse('2026-11-02 10:59:59'));
    $this->artisan('appointments:mark-no-shows')->assertSuccessful();
    expect($status($scheduled))->toBe('programada');

    // La tarea corre cada 5 minutos: la siguiente ejecución cae entre los minutos 30 y 35.
    $this->travelTo(Carbon::parse('2026-11-02 11:03'));
    $this->artisan('appointments:mark-no-shows')->assertSuccessful();

    expect($status($scheduled))->toBe('inasistencia')
        ->and($status($confirmed))->toBe('inasistencia')
        ->and(TenantContext::run($tenant, fn () => $scheduled->fresh()->no_show_marked_at))->not->toBeNull()
        ->and(TenantContext::run($tenant, fn () => AuditLog::query()->where('action', 'appointment.no_show')->count()))->toBe(2);

    // Repetirla no duplica efectos.
    $this->artisan('appointments:mark-no-shows')->assertSuccessful();
    expect(TenantContext::run($tenant, fn () => AuditLog::query()->where('action', 'appointment.no_show')->count()))->toBe(2);
})->group('T-121', 'RN-51', 'RNF-020', 'CUS-51');

it('leaves checked-in, cancelled and attended appointments untouched', function () {
    $tenant = Tenant::factory()->create();
    $inAttention = noShowCandidate($tenant, ['status' => 'en_atencion', 'checked_in_at' => now()]);
    $cancelled = noShowCandidate($tenant, ['status' => 'cancelada', 'cancel_reason' => 'paciente']);
    $attended = noShowCandidate($tenant, ['status' => 'atendida']);

    $this->travelTo(Carbon::parse('2026-11-02 12:00'));
    $this->artisan('appointments:mark-no-shows')->assertSuccessful();

    TenantContext::run($tenant, function () use ($inAttention, $cancelled, $attended) {
        expect($inAttention->fresh()->status)->toBe('en_atencion')
            ->and($cancelled->fresh()->status)->toBe('cancelada')
            ->and($attended->fresh()->status)->toBe('atendida');
    });
})->group('RN-51', 'CUS-51');
