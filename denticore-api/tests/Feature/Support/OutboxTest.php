<?php

/*
 * Outbox, colas y tareas programadas (TASK-010; SDD §1.9; DD-41, RNF-078, RNF-087).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Jobs\SendNotificationJob;
use App\Modules\Scheduling\Mail\NotificationMail;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Outbox\OutboxJob;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxQueue;
use App\Support\Outbox\OutboxWriter;
use App\Support\Scheduling\ScheduledTaskLedger;
use App\Support\Scheduling\ScheduledTaskRun;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/** Job de prueba: registra cada efecto aplicado y la clínica en la que corrió. */
class RecordEffectJob extends OutboxJob
{
    /** @var list<array{payload: array<string, mixed>, tenant: int|null, patients: int}> */
    public static array $effects = [];

    protected function process(OutboxMessage $message): void
    {
        self::$effects[] = [
            'payload' => $message->payload,
            'tenant' => TenantContext::id(),
            'patients' => Patient::query()->count(),
        ];
    }
}

beforeEach(function () {
    RecordEffectJob::$effects = [];
    config(['outbox.handlers' => ['test.effect' => RecordEffectJob::class]]);
});

it('keeps a committed message and never stores the message of a rolled back transaction', function () {
    $writer = app(OutboxWriter::class);

    DB::transaction(fn () => $writer->record('test.effect', ['n' => 1], OutboxQueue::Notifications));

    try {
        DB::transaction(function () use ($writer) {
            $writer->record('test.effect', ['n' => 2], OutboxQueue::Notifications);
            throw new RuntimeException('falla la operación de negocio');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessage::query()->pluck('payload')->all())->toBe([['n' => 1]]);
})->group('DD-41', 'RNF-078');

it('publishes a committed message exactly once', function () {
    Queue::fake();
    app(OutboxWriter::class)->record('test.effect', ['n' => 1], OutboxQueue::Critical);

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();
    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    Queue::assertPushedOn('critical', RecordEffectJob::class);
    Queue::assertPushed(RecordEffectJob::class, 1);
    expect(OutboxMessage::query()->sole()->dispatched_at)->not->toBeNull();
})->group('DD-41', 'RNF-078');

it('keeps messages in PostgreSQL while Redis is down and publishes them when it comes back', function () {
    app(OutboxWriter::class)->record('test.effect', ['n' => 1], OutboxQueue::Notifications);

    // Redis caído: la cola apunta a un puerto sin servicio.
    config(['queue.default' => 'redis', 'database.redis.default.port' => 1, 'database.redis.default.timeout' => 0.2]);
    Redis::purge();

    expect(app(OutboxDispatcher::class)->dispatchBatch())->toBe(0)
        ->and(OutboxMessage::query()->sole()->dispatched_at)->toBeNull();

    // Redis vuelve.
    Queue::fake();

    expect(app(OutboxDispatcher::class)->dispatchBatch())->toBe(1);
    Queue::assertPushed(RecordEffectJob::class, 1);
    expect(OutboxMessage::query()->sole()->dispatched_at)->not->toBeNull();
})->group('RNF-077', 'RNF-078');

it('does not let a message without a registered job block the others', function () {
    Queue::fake();
    app(OutboxWriter::class)->record('unknown.type', ['n' => 0], OutboxQueue::Notifications);
    app(OutboxWriter::class)->record('test.effect', ['n' => 1], OutboxQueue::Notifications);

    expect(app(OutboxDispatcher::class)->dispatchBatch())->toBe(1);

    $unknown = OutboxMessage::query()->where('type', 'unknown.type')->sole();
    expect($unknown->dispatched_at)->toBeNull()
        ->and($unknown->last_error)->toContain('unknown.type');
})->group('DD-41');

it('does not repeat the effect when the same job runs twice', function () {
    $message = app(OutboxWriter::class)->record('test.effect', ['n' => 1], OutboxQueue::Notifications);

    (new RecordEffectJob($message->uuid, null))->handle();
    (new RecordEffectJob($message->uuid, null))->handle();

    expect(RecordEffectJob::$effects)->toHaveCount(1)
        ->and($message->fresh()->consumed_at)->not->toBeNull();
})->group('DD-41');

it('runs the job of a clinic message inside that clinic', function () {
    $tenant = Tenant::factory()->create();
    Patient::factory()->for($tenant)->count(2)->create();
    Patient::factory()->for(Tenant::factory())->create();

    TenantContext::run($tenant, fn () => app(OutboxWriter::class)->record('test.effect', ['n' => 1], OutboxQueue::Notifications));

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    expect(RecordEffectJob::$effects)->toBe([['payload' => ['n' => 1], 'tenant' => $tenant->id, 'patients' => 2]])
        ->and(OutboxMessage::query()->sole()->tenant_id)->toBe($tenant->id)
        ->and(TenantContext::id())->toBeNull();
})->group('RNF-101');

it('does not publish messages scheduled for later', function () {
    Queue::fake();
    app(OutboxWriter::class)->record('test.effect', ['n' => 1], OutboxQueue::Notifications, now()->addHour());

    expect(app(OutboxDispatcher::class)->dispatchBatch())->toBe(0);

    $this->travel(61)->minutes();
    expect(app(OutboxDispatcher::class)->dispatchBatch())->toBe(1);
});

it('prunes messages dispatched more than 7 days ago', function () {
    Queue::fake();
    app(OutboxWriter::class)->record('test.effect', ['n' => 1], OutboxQueue::Notifications);
    app(OutboxDispatcher::class)->dispatchBatch();
    app(OutboxWriter::class)->record('test.effect', ['n' => 2], OutboxQueue::Notifications);

    $this->travel(8)->days();
    $this->artisan('outbox:prune')->assertSuccessful();

    expect(OutboxMessage::query()->pluck('payload')->all())->toBe([['n' => 2]]);
})->group('DD-45');

it('processes a missed scheduled run from its last success without duplicating effects', function () {
    $ledger = app(ScheduledTaskLedger::class);
    $processed = [];
    $events = collect([
        CarbonImmutable::parse('2026-10-05 08:01'),
        CarbonImmutable::parse('2026-10-05 08:07'),
        CarbonImmutable::parse('2026-10-05 08:12'),
        CarbonImmutable::parse('2026-10-05 08:16'),
    ]);
    $task = function (?CarbonImmutable $since, CarbonImmutable $until) use ($events, &$processed) {
        foreach ($events as $event) {
            if (($since === null || $event->greaterThan($since)) && $event->lessThanOrEqualTo($until)) {
                $processed[] = $event->format('H:i');
            }
        }
    };

    $this->travelTo('2026-10-05 08:05');
    $ledger->run('test:task', null, $task);

    // 08:10: la ejecución falla y no avanza la marca.
    $this->travelTo('2026-10-05 08:10');
    expect(fn () => $ledger->run('test:task', null, fn () => throw new RuntimeException('caída')))
        ->toThrow(RuntimeException::class);

    // 08:15: procesa desde la última ejecución exitosa (08:05).
    $this->travelTo('2026-10-05 08:15');
    $ledger->run('test:task', null, $task);

    expect($processed)->toBe(['08:01', '08:07', '08:12'])
        ->and(ScheduledTaskRun::query()->sole()->watermark->format('H:i'))->toBe('08:15');
})->group('RNF-087', 'RNF-130');

it('emits no notification for a rolled back transaction and loses none for a committed one', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $users = User::factory()->for($tenant)->count(2)->create();
    $notifications = app(NotificationService::class);

    // Transacción revertida: ni notificación ni mensaje.
    try {
        DB::transaction(function () use ($tenant, $users, $notifications) {
            TenantContext::run($tenant, fn () => $notifications->sendEmail(NotificationEvent::CuentaBloqueada, $users[0]));
            throw new RuntimeException('falla la operación de negocio');
        });
    } catch (RuntimeException) {
    }

    // Transacción confirmada.
    DB::transaction(fn () => TenantContext::run($tenant, fn () => $notifications->sendEmail(NotificationEvent::CuentaBloqueada, $users[1])));

    expect(Notification::query()->pluck('recipient_user_id')->all())->toBe([$users[1]->id]);

    // El despachador real publica el mensaje y el worker (cola síncrona) lo envía.
    config(['outbox.handlers' => ['notification.send' => SendNotificationJob::class], 'queue.default' => 'sync']);
    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    Mail::assertSent(NotificationMail::class, 1);
    Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($users[1]->email));
    expect(Notification::query()->sole()->status)->toBe('enviada');
})->group('DD-41', 'RNF-078');
