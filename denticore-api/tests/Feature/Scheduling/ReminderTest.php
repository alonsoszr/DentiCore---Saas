<?php

/*
 * Recordatorios de cita (TASK-070; SDD §1.9, §4.8; CUS-52; RF-156, RN-52, RN-80; T-122): un solo
 * correo 24 h antes y solo con la finalidad (b) «notificaciones».
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-02 09:00')));

/** Paciente con correo y consentimiento; `$notifications` indica la finalidad (b). */
function reminderPatient(Tenant $tenant, bool $notifications = true): Patient
{
    return TenantContext::run($tenant, function () use ($tenant, $notifications) {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', 'email' => Str::random(8).'@example.test']);
        app(ConsentService::class)->grant($patient, [
            'channel' => 'presencial', 'confirmation_document_number' => $patient->document_number, 'purpose_notifications' => $notifications,
        ], User::factory()->for($tenant)->create(['role' => 'receptionist']), '127.0.0.1');

        return $patient;
    });
}

function reminderAppointment(Tenant $tenant, Patient $patient, string $startsAt, array $attributes = []): Appointment
{
    return Appointment::factory()->create([
        'tenant_id' => $tenant->id, 'patient_id' => $patient->id,
        'starts_at' => Carbon::parse($startsAt), 'ends_at' => Carbon::parse($startsAt)->addMinutes(30), ...$attributes,
    ]);
}

function reminders(Tenant $tenant): int
{
    return Notification::query()->where('tenant_id', $tenant->id)->where('event', 'cita_recordatorio')->count();
}

it('sends exactly one reminder 24 hours before and only with the notifications purpose', function () {
    $tenant = Tenant::factory()->create();
    $within = reminderAppointment($tenant, reminderPatient($tenant), '2026-11-03 08:45');
    reminderAppointment($tenant, reminderPatient($tenant, notifications: false), '2026-11-03 08:50');
    reminderAppointment($tenant, reminderPatient($tenant), '2026-11-03 09:15');
    reminderAppointment($tenant, reminderPatient($tenant), '2026-11-03 08:40', ['status' => 'cancelada', 'cancel_reason' => 'paciente']);

    $this->artisan('appointments:send-reminders')->assertSuccessful();
    $this->artisan('appointments:send-reminders')->assertSuccessful();

    $sent = Notification::query()->where('tenant_id', $tenant->id)->where('event', 'cita_recordatorio')->get();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->dedupe_key)->toBe("recordatorio:{$within->uuid}")
        ->and($sent[0]->channel)->toBe('correo')
        ->and($sent[0]->payload['links']['confirm'])->toContain('/confirmar/');

    // La cita del 03/11 a las 09:15 empezaba en 24 h 15 min; a las 09:20 entra en la ventana.
    $this->travelTo(Carbon::parse('2026-11-02 09:20'));
    $this->artisan('appointments:send-reminders')->assertSuccessful();
    expect(reminders($tenant))->toBe(2);
})->group('T-122', 'RN-52', 'RF-156', 'CUS-52');

it('omits the confirmation link of a confirmed appointment and skips suspended clinics', function () {
    $tenant = Tenant::factory()->create();
    reminderAppointment($tenant, reminderPatient($tenant), '2026-11-03 08:45', ['status' => 'confirmada', 'confirmed_at' => now()]);
    $suspended = Tenant::factory()->create();
    reminderAppointment($suspended, reminderPatient($suspended), '2026-11-03 08:45');
    $suspended->forceFill(['status' => 'suspendida'])->save();

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    $sent = Notification::query()->where('tenant_id', $tenant->id)->where('event', 'cita_recordatorio')->sole();
    expect($sent->payload['links'])->toBe([])
        ->and(reminders($suspended))->toBe(0);
})->group('RF-156', 'RN-07');
