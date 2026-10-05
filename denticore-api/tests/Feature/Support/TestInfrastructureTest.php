<?php

/*
 * Infraestructura de pruebas (TASK-017; SDD §6.2): fábricas con clínica explícita,
 * actingAsRole, conexión concurrente, aserciones del outbox y servicios externos.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Outbox\OutboxQueue;
use App\Support\Outbox\OutboxWriter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalServices;
use Tests\Support\Outbox;

it('creates a clinic with its first administrator', function () {
    $tenant = Tenant::factory()->withAdmin(['email' => 'admin@clinica.test'])->create();

    expect(User::query()->where('tenant_id', $tenant->id)->sole())
        ->role->toBe('clinic_admin')
        ->email->toBe('admin@clinica.test');
})->group('RES-08');

it('authenticates a user of the requested role with a full token', function () {
    $tenant = Tenant::factory()->create();
    $dentist = $this->actingAsRole('dentist', $tenant);

    expect($dentist->tenant_id)->toBe($tenant->id)
        ->and($dentist->currentAccessToken()->can('full'))->toBeTrue();
    $this->getJson('/api/v1/patients')->assertOk();

    $superAdmin = $this->actingAsRole('super_admin');
    expect($superAdmin->tenant_id)->toBeNull();
    $this->getJson('/api/v1/platform/tenants')->assertOk();
});

it('runs transactions in parallel on two independent connections', function () {
    DB::select('select pg_advisory_xact_lock(424242)');

    $concurrent = DB::connection('pgsql_concurrent');
    $acquired = $concurrent->transaction(
        fn () => $concurrent->selectOne('select pg_try_advisory_xact_lock(424242) as acquired')->acquired,
    );

    expect($concurrent->selectOne('select pg_backend_pid() as pid')->pid)
        ->not->toBe(DB::selectOne('select pg_backend_pid() as pid')->pid)
        ->and($acquired)->toBeFalse();
})->group('RF-011');

it('asserts the messages recorded in the outbox', function () {
    app(OutboxWriter::class)->record('test.recorded', ['n' => 1], OutboxQueue::Notifications);

    Outbox::assertRecorded('test.recorded');
    Outbox::assertRecorded('test.recorded', fn ($message) => $message->payload === ['n' => 1], times: 1);
    Outbox::assertNotRecorded('test.other');
});

it('simulates external services without waiting on the real clock', function () {
    ExternalServices::respondsWith('ml.test/*', ['status' => 'ok']);
    expect(Http::get('https://ml.test/v1/health')->json('status'))->toBe('ok');

    ExternalServices::timesOut('ia.test/*');
    expect(fn () => Http::get('https://ia.test/v1/chat'))->toThrow(ConnectionException::class);
});
