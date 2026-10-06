<?php

/*
 * Alta de clínica con invitación (TASK-023; CUS-01, SRS §11.1; RF-013 a RF-016, DD-17, DD-22,
 * RN-04, RN-05). T-001 a T-004.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeToken;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Outbox;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clinicPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Clínica Sonrisa',
        'legal_name' => 'Sonrisa Odontología S.A.C.',
        'ruc' => '20600000013',
        'slug' => 'clinica-sonrisa',
        'address' => 'Av. Arequipa 1234, Lima',
        'subscription_plan' => 'pro',
        'admin' => ['name' => 'Ana Administradora', 'email' => 'Ana@Sonrisa.TEST'],
    ], $overrides);
}

function registerClinic(array $payload): TestResponse
{
    return test()->postJson('/api/v1/platform/tenants', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

it('creates the clinic with settings, key v1 and a pending admin and queues the invitation', function () {
    $this->actingAsRole('super_admin');
    $this->travelTo('2026-10-05 10:00:00');

    $response = registerClinic(clinicPayload());

    $response->assertCreated()
        ->assertJsonPath('data.slug', 'clinica-sonrisa')
        ->assertJsonPath('data.status', 'activa')
        ->assertJsonPath('data.plan.code', 'pro');

    $tenant = Tenant::query()->where('slug', 'clinica-sonrisa')->sole();
    expect($tenant)->legal_name->toBe('Sonrisa Odontología S.A.C.')->ruc->toBe('20600000013')->status->toBe('activa');

    TenantContext::run($tenant, function () use ($tenant) {
        expect(ClinicSetting::query()->count())->toBe(1)
            ->and($tenant->encryptionKey()->sole())->version->toBe(1)->status->toBe('activa')
            ->and(DB::table('document_sequences')->count())->toBe(2);
    });

    $admin = User::query()->where('tenant_id', $tenant->id)->sole();
    expect($admin)
        ->role->toBe('clinic_admin')
        ->status->toBe('pendiente_activacion')
        ->is_data_officer->toBeTrue()
        ->password->toBeNull()
        ->email->toBe('ana@sonrisa.test');

    $invitation = OneTimeToken::query()->sole();
    expect($invitation)
        ->purpose->toBe(TokenPurpose::Invitation)
        ->tokenable_id->toBe($admin->id)
        ->and($invitation->expires_at->toDateTimeString())->toBe('2026-10-08 10:00:00');

    Outbox::assertRecorded('notification.send', times: 1);
    expect(Notification::query()->sole())
        ->event->value->toBe('invitacion_activacion')
        ->recipient_user_id->toBe($admin->id)
        ->tenant_id->toBe($tenant->id);
    expect(Notification::query()->sole()->payload['links']['activate'])
        ->toStartWith(config('app.spa_url').'/c/clinica-sonrisa/activar/');
})->group('CA-01.1', 'RF-015', 'RN-05', 'RF-016');

it('rejects a duplicated access code without creating anything', function () {
    Tenant::factory()->create(['slug' => 'clinica-sonrisa']);
    $this->actingAsRole('super_admin');
    $before = [Tenant::query()->count(), User::query()->count()];

    registerClinic(clinicPayload())->assertUnprocessable()->assertJsonValidationErrors(['slug']);

    expect([Tenant::query()->count(), User::query()->count()])->toBe($before)
        ->and(OneTimeToken::query()->count())->toBe(0);
})->group('CA-01.2', 'RF-013');

it('rolls back the whole registration when key generation fails', function () {
    $this->actingAsRole('super_admin');
    $this->mock(TenantEncryption::class)
        ->shouldReceive('generateKeyFor')
        ->andThrow(new RuntimeException('KMS no disponible'));

    registerClinic(clinicPayload())->assertServerError();

    expect(Tenant::query()->where('slug', 'clinica-sonrisa')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'ana@sonrisa.test')->exists())->toBeFalse()
        ->and(Notification::query()->count())->toBe(0);
    Outbox::assertNotRecorded('notification.send');
})->group('CA-01.3', 'RF-012', 'RF-015');

it('forbids clinic registration to every role except super_admin', function (string $role) {
    $this->actingAsRole($role);
    $before = Tenant::query()->count();

    registerClinic(clinicPayload())->assertForbidden();

    expect(Tenant::query()->count())->toBe($before);
})->with(['clinic_admin', 'dentist', 'receptionist', 'patient'])->group('CA-01.4', 'RN-04', 'RF-004');

it('responds 422 on the ruc field for an invalid check digit or a duplicated RUC', function (string $ruc) {
    Tenant::factory()->create(['ruc' => '20600000013']);
    $this->actingAsRole('super_admin');

    registerClinic(clinicPayload(['ruc' => $ruc, 'slug' => 'otra-clinica']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ruc']);
})->with([
    'dígito verificador inválido' => '20600000014',
    'prefijo distinto de 10 o 20' => '30600000013',
    'RUC ya registrado' => '20600000013',
])->group('RF-014');

it('validates the fields of SRS §11.1', function (array $overrides, string $field) {
    $this->actingAsRole('super_admin');

    registerClinic(clinicPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'nombre de 2 caracteres' => [['name' => 'AB'], 'name'],
    'razón social de 2 caracteres' => [['legal_name' => 'AB'], 'legal_name'],
    'código con mayúsculas' => [['slug' => 'Clinica'], 'slug'],
    'código con guion final' => [['slug' => 'clinica-'], 'slug'],
    'dirección de 4 caracteres' => [['address' => 'Lima'], 'address'],
    'plan inexistente' => [['subscription_plan' => 'gold'], 'subscription_plan'],
    'correo del administrador inválido' => [['admin' => ['email' => 'no-es-correo']], 'admin.email'],
    'nombre del administrador vacío' => [['admin' => ['name' => '']], 'admin.name'],
])->group('RF-013');

it('exposes the uuid and no numeric id and audits tenant.created', function () {
    $this->actingAsRole('super_admin');

    $response = registerClinic(clinicPayload(['tenant_id' => 999, 'status' => 'suspendida']));

    $tenant = Tenant::query()->where('slug', 'clinica-sonrisa')->sole();
    $response->assertCreated()->assertJsonPath('data.id', $tenant->uuid)->assertJsonPath('data.status', 'activa');
    expect(json_encode($response->json()))->not->toMatch('/"id":\s*\d+/');

    expect(AuditLog::query()->where('action', 'tenant.created')->sole())
        ->resource_uuid->toBe($tenant->uuid);
})->group('RF-007', 'RN-67');
