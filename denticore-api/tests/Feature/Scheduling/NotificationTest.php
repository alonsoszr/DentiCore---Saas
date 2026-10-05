<?php

/*
 * Canal de correo y notificaciones (TASK-022; SDD §1.9, §2.10, §4.8; DD-10, DD-41, IE-04,
 * RF-155, RNF-049, RNF-078, RNF-110).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Jobs\SendNotificationJob;
use App\Modules\Scheduling\Mail\NotificationMail;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** Ejecuta el job del mensaje de outbox de una notificación, como lo haría el worker. */
function runNotificationJob(Notification $notification): void
{
    $message = OutboxMessage::query()->where('payload->notification', $notification->uuid)->sole();

    $job = new SendNotificationJob($message->uuid, $message->tenant_id);
    $message->tenant_id === null ? $job->handle() : TenantContext::run($message->tenant_id, fn () => $job->handle());
}

/** El correo se envía por SMTP a un puerto sin servicio (SMTP detenido). */
function smtpIsDown(): void
{
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.timeout' => 1,
    ]);
}

it('queues a pending notification and its outbox message without personal data in the payload', function () {
    $tenant = Tenant::factory()->create(['name' => 'Clínica Sonrisa']);
    $user = User::factory()->for($tenant)->create(['email' => 'rosa@sonrisa.test']);

    $notification = TenantContext::run($tenant, fn () => app(NotificationService::class)->sendEmail(
        NotificationEvent::InvitacionActivacion, $user, links: ['activate' => 'https://app.test/c/sonrisa/activar/abc'],
    ));

    expect($notification)
        ->tenant_id->toBe($tenant->id)
        ->channel->toBe('correo')
        ->status->toBe('pendiente')
        ->recipient_user_id->toBe($user->id)
        ->recipient_email_hash->toBe(hash('sha256', 'rosa@sonrisa.test'));

    expect($notification->payload)->toMatchArray([
        'template' => 'invitacion_activacion',
        'locale' => 'es-PE',
        'clinic' => ['name' => 'Clínica Sonrisa'],
    ]);
    expect(json_encode($notification->payload))->not->toContain('rosa@sonrisa.test')->not->toContain($user->name);

    expect(OutboxMessage::query()->where('type', 'notification.send')->sole())
        ->queue->toBe('notifications')
        ->payload->toBe(['notification' => $notification->uuid]);
})->group('RF-155', 'RNF-110', 'DD-10');

it('does not queue an email for a recipient without email', function () {
    $tenant = Tenant::factory()->create();

    $notification = TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create(['email' => null]);

        return app(NotificationService::class)->sendEmail(NotificationEvent::ConsentimientoConstancia, $patient);
    });

    expect($notification)->toBeNull()
        ->and(Notification::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(0);
})->group('RF-066');

it('sends the email once and marks the notification as sent', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create(['name' => 'Clínica Sonrisa']);

    $notification = TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create(['first_name' => 'Ñusta', 'last_name' => 'Quispe', 'email' => 'nusta@correo.test']);

        return app(NotificationService::class)->sendEmail(
            NotificationEvent::ConsentimientoConstancia, $patient, links: ['certificate' => 'https://app.test/constancia'],
        );
    });

    runNotificationJob($notification);
    runNotificationJob($notification);

    Mail::assertSent(NotificationMail::class, 1);
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('nusta@correo.test')
        && $mail->recipientName === 'Ñusta Quispe');

    expect($notification->fresh())
        ->status->toBe('enviada')
        ->attempts->toBe(1)
        ->sent_at->not->toBeNull();
})->group('RF-155', 'DD-41');

it('marks the notification as failed after 3 attempts with SMTP down and keeps the business operation', function () {
    smtpIsDown();
    $user = User::factory()->superAdmin()->create();

    // Operación de negocio confirmada que emite el correo.
    $notification = DB::transaction(function () use ($user) {
        $user->forceFill(['name' => 'Nombre actualizado'])->save();

        return app(NotificationService::class)->sendEmail(NotificationEvent::CuentaBloqueada, $user);
    });

    foreach (range(1, 3) as $attempt) {
        expect(fn () => runNotificationJob($notification))->toThrow(Exception::class);
        expect($notification->fresh()->attempts)->toBe($attempt);
    }

    expect($notification->fresh())
        ->status->toBe('fallida')
        ->attempts->toBe(3)
        ->last_error->not->toBeNull();
    expect($user->fresh()->name)->toBe('Nombre actualizado');
})->group('IE-04', 'DD-10', 'RNF-078');

it('retries with exponential backoff and sends on the third attempt', function () {
    $user = User::factory()->superAdmin()->create();
    $notification = app(NotificationService::class)->sendEmail(NotificationEvent::CuentaBloqueada, $user);

    expect((new SendNotificationJob('x', null))->backoff)->toBe([60, 240, 960])
        ->and((new SendNotificationJob('x', null))->tries)->toBe(3);

    smtpIsDown();
    foreach (range(1, 2) as $attempt) {
        expect(fn () => runNotificationJob($notification))->toThrow(Exception::class);
    }

    Mail::fake();
    runNotificationJob($notification);

    Mail::assertSent(NotificationMail::class, 1);
    expect($notification->fresh())->status->toBe('enviada')->attempts->toBe(3);
})->group('RF-155', 'IE-04');

it('renders every E1 template in HTML and plain text with the clinic name', function (NotificationEvent $event, array $data, array $links) {
    $tenant = Tenant::factory()->create(['name' => 'Clínica Sonrisa']);
    $user = User::factory()->for($tenant)->create();

    $notification = TenantContext::run($tenant, fn () => app(NotificationService::class)->sendEmail($event, $user, $data, $links));
    $mail = new NotificationMail($notification, 'Rosa Quispe');

    $mail->assertSeeInHtml('Clínica Sonrisa')
        ->assertSeeInHtml('Rosa Quispe')
        ->assertSeeInText('Rosa Quispe');

    foreach ([...array_values($data), ...array_values($links)] as $value) {
        $mail->assertSeeInHtml($value)->assertSeeInText($value);
    }
})->with([
    'invitación' => [NotificationEvent::InvitacionActivacion, [], ['activate' => 'https://app.test/c/sonrisa/activar/t1']],
    'restablecimiento' => [NotificationEvent::RestablecimientoContrasena, [], ['reset' => 'https://app.test/c/sonrisa/restablecer/t2']],
    'cuenta bloqueada' => [NotificationEvent::CuentaBloqueada, [], []],
    'clínica suspendida' => [NotificationEvent::ClinicaSuspendida, ['reason' => 'Falta de pago'], []],
    'clínica reactivada' => [NotificationEvent::ClinicaReactivada, ['reason' => 'Pago regularizado'], []],
    'constancia de consentimiento' => [NotificationEvent::ConsentimientoConstancia, [], ['certificate' => 'https://app.test/constancia']],
])->group('RNF-049', 'RF-155');

it('delivers exactly one email to Mailpit for a committed event', function () {
    $mailpit = 'http://127.0.0.1:8025/api/v1';

    try {
        Http::timeout(2)->get("{$mailpit}/info")->throw();
    } catch (Throwable) {
        $this->markTestSkipped('Mailpit no está disponible (docker compose up -d mailpit).');
    }

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1025]);
    $email = 'invitado-'.uniqid().'@clinica.test';
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => $email]);

    $notification = DB::transaction(fn () => TenantContext::run($tenant, fn () => app(NotificationService::class)->sendEmail(
        NotificationEvent::InvitacionActivacion, $user, links: ['activate' => 'https://app.test/activar/t'],
    )));
    runNotificationJob($notification);

    $found = Http::get("{$mailpit}/search", ['query' => "to:{$email}"])->json('messages');
    expect($found)->toHaveCount(1)
        ->and($found[0]['Subject'])->toContain('Activa tu cuenta');
})->group('RF-155', 'DD-10');
