<?php

/*
 * Aviso del plazo de las solicitudes ARCO (TASK-104; CUS-64 FA-2; SDD §1.9, §4.8; RF-185, RN-69,
 * DD-26). T-146.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Mail\NotificationMail;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\ArcoFixtures;
use Tests\Support\ClinicalFixtures;

it('notifies officer and admins 2 business days before the deadline', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $officer = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'is_data_officer' => true, 'email' => 'oficial@clinica.test']);
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'email' => 'admin@clinica.test']);
    User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'status' => 'inactivo']);
    User::factory()->for($tenant)->create(['role' => 'receptionist']);
    $patient = ClinicalFixtures::patient($tenant);

    // Recibida el lunes 2 → vence el lunes 16; el aviso sale el jueves 12 (faltan el 13 y el 16).
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/Lima'));
    $due = ArcoFixtures::request($tenant, $patient);
    $resolved = ArcoFixtures::request($tenant, $patient);
    // Recibida el martes 3 → vence el martes 17: todavía no toca.
    $this->travelTo(Carbon::parse('2026-11-03 10:00', 'America/Lima'));
    $later = ArcoFixtures::request($tenant, $patient);
    TenantContext::run($tenant, fn () => $resolved->forceFill([
        'status' => 'denegada', 'resolved_at' => now(), 'within_deadline' => true,
        'response' => ArcoFixtures::response(), 'denial_reason' => 'Solicitud duplicada de la misma persona.',
    ])->save());

    $this->travelTo(Carbon::parse('2026-11-12 08:00', 'America/Lima'));
    $this->artisan('arco:deadline-reminders')->assertSuccessful();
    $this->artisan('arco:deadline-reminders')->assertSuccessful();

    TenantContext::run($tenant, function () use ($officer, $admin, $due, $resolved, $later) {
        $sent = Notification::query()->where('event', 'arco_plazo_proximo')->get();

        // CA-64.5: in_app y correo al Oficial y a cada administrador activo, una sola vez.
        expect($sent)->toHaveCount(4)
            ->and($sent->pluck('recipient_user_id')->unique()->sort()->values()->all())->toBe(collect([$officer->id, $admin->id])->sort()->values()->all())
            ->and($sent->pluck('channel')->countBy()->all())->toBe(['in_app' => 2, 'correo' => 2])
            ->and($sent->first()->payload['data'])->toEqual(['type' => 'acceso', 'due_date' => '2026-11-16', 'request' => $due->uuid])
            ->and($due->fresh()->reminder_sent_at)->not->toBeNull()
            ->and($resolved->fresh()->reminder_sent_at)->toBeNull()
            ->and($later->fresh()->reminder_sent_at)->toBeNull();
    });

    // El viernes 13 le toca a la solicitud que vence el martes 17 (faltan el 16 y el 17).
    $this->travelTo(Carbon::parse('2026-11-13 08:00', 'America/Lima'));
    $this->artisan('arco:deadline-reminders')->assertSuccessful();
    expect(TenantContext::run($tenant, fn () => $later->fresh()->reminder_sent_at))->not->toBeNull()
        ->and(TenantContext::run($tenant, fn () => Notification::query()->where('event', 'arco_plazo_proximo')->count()))->toBe(8);
})->group('T-146', 'CA-64.5', 'RN-69', 'RF-185', 'DD-26');

it('renders the ARCO emails in HTML and plain text', function (string $event, array $data, array $links, string $expected) {
    $notification = (new Notification)->forceFill(['event' => $event, 'payload' => [
        'template' => $event, 'locale' => 'es-PE', 'clinic' => ['name' => 'Clínica Sonrisa'], 'data' => $data, 'links' => $links,
    ]]);
    $mail = new NotificationMail($notification, 'Rosa Quispe');

    // RNF-049: HTML y texto plano; RNF-110: sin datos de identificación en la plantilla.
    expect($mail->render())->toContain($expected)->toContain('Clínica Sonrisa')
        ->and(view("mail.notifications.{$event}-text", ['recipientName' => 'Rosa Quispe', 'clinicName' => 'Clínica Sonrisa', 'data' => $data, 'links' => $links])->render())
        ->toContain($expected);
})->with([
    'respuesta' => ['respuesta_arco', ['type' => 'acceso', 'status' => 'atendida'], ['portal' => 'https://app.test/c/sonrisa/portal/arco'], 'quedó atendida'],
    'plazo' => ['arco_plazo_proximo', ['type' => 'acceso', 'due_date' => '2026-11-16', 'request' => 'b9'], ['request' => 'https://app.test/arco/b9'], 'vence el 16/11/2026'],
])->group('RF-183', 'RF-185', 'RNF-049');
