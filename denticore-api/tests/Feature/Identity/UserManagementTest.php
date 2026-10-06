<?php

/*
 * Gestión de usuarios de la clínica (TASK-030; CUS-11, SDD §3.7, §4.3.2; RF-042 a RF-047,
 * RN-05, RN-06, RN-08, RN-75, DD-03). T-020, T-022 y T-023.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use App\Support\Tokens\OneTimeToken;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/** Administrador de una clínica del plan indicado, autenticado con token completo. */
function adminOf(string $plan = 'pro'): User
{
    $tenant = Tenant::factory()->plan($plan)->create(['slug' => 'clinica-'.Str::lower(Str::random(6))]);
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'is_data_officer' => true]);
    test()->actingWithToken($admin);

    return $admin;
}

function createUser(array $payload): TestResponse
{
    return test()->postJson('/api/v1/users', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

it('creates a user with an invitation and without password', function () {
    $admin = adminOf();

    $response = createUser([
        'name' => 'Diego Ríos', 'email' => 'Diego@Sonrisa.TEST', 'role' => 'dentist',
        'cop_number' => '12345', 'specialty' => 'Ortodoncia', 'rne_number' => '6789',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pendiente_activacion')
        ->assertJsonPath('data.cop_number', '12345')
        ->assertJsonPath('data.specialty', 'Ortodoncia');

    $user = User::query()->where('uuid', $response->json('data.id'))->sole();
    expect($user)->tenant_id->toBe($admin->tenant_id)->password->toBeNull()->email->toBe('diego@sonrisa.test');
    expect(Notification::query()->where('event', 'invitacion_activacion')->where('recipient_user_id', $user->id)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'user.created')->count())->toBe(1);
})->group('RF-042', 'DD-22');

it('validates the role, the COP and the data officer flag', function (array $payload, string $field) {
    adminOf();

    createUser(['name' => 'Usuario Nuevo', 'email' => 'nuevo@sonrisa.test', ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'rol super_admin' => [['role' => 'super_admin'], 'role'],
    'odontólogo sin COP' => [['role' => 'dentist'], 'cop_number'],
    'oficial de datos que no es administrador' => [['role' => 'receptionist', 'is_data_officer' => true], 'is_data_officer'],
])->group('RN-75', 'RF-043', 'RF-047');

it('rejects creating or reactivating a dentist above the plan maximum', function () {
    $admin = adminOf('basic');
    User::factory()->for($admin->tenant)->count(2)->create(['role' => 'dentist']);
    $inactive = User::factory()->for($admin->tenant)->create(['role' => 'dentist', 'status' => 'inactivo']);

    createUser(['name' => 'Tercer Odontólogo', 'email' => 'tercero@sonrisa.test', 'role' => 'dentist', 'cop_number' => '33333'])
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-08');

    $this->postJson("/api/v1/users/{$inactive->uuid}/reactivate")
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-08');

    expect($inactive->fresh()->status)->toBe('inactivo');
})->group('RN-08', 'RF-046', 'RF-022');

it('returns 404 for a user of another clinic and revokes tokens on deactivation', function () {
    adminOf();
    $foreign = User::factory()->for(Tenant::factory())->create(['role' => 'receptionist']);

    $this->getJson("/api/v1/users/{$foreign->uuid}")->assertNotFound();
    $this->patchJson("/api/v1/users/{$foreign->uuid}", ['name' => 'Otro'])->assertNotFound();
    $this->postJson("/api/v1/users/{$foreign->uuid}/deactivate")->assertNotFound();

    $admin = adminOf();
    $receptionist = User::factory()->for($admin->tenant)->create(['role' => 'receptionist']);
    $token = $receptionist->createToken('api', ['full'])->plainTextToken;

    $this->postJson("/api/v1/users/{$receptionist->uuid}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactivo');

    expect(PersonalAccessToken::findToken($token))->toBeNull()
        ->and($receptionist->fresh()->deactivated_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', 'user.deactivated')->count())->toBe(1);
})->group('DD-03', 'RF-044');

it('prevents deactivating the last clinic_admin or data officer', function () {
    $admin = adminOf();

    // Último administrador activo (y último oficial de datos).
    $this->postJson("/api/v1/users/{$admin->uuid}/deactivate")->assertUnprocessable()->assertJsonPath('rule', 'RF-045');
    $this->patchJson("/api/v1/users/{$admin->uuid}", ['role' => 'receptionist'])->assertUnprocessable()->assertJsonPath('rule', 'RF-045');
    $this->patchJson("/api/v1/users/{$admin->uuid}", ['is_data_officer' => false])->assertUnprocessable()->assertJsonPath('rule', 'RF-045');

    // Con un segundo administrador que no es oficial, el primero sigue siendo el último oficial.
    $second = User::factory()->for($admin->tenant)->create(['role' => 'clinic_admin']);
    $this->postJson("/api/v1/users/{$admin->uuid}/deactivate")->assertUnprocessable()->assertJsonPath('rule', 'RF-045');

    // Cuando el segundo también es oficial, ya se puede desactivar al primero.
    $this->patchJson("/api/v1/users/{$second->uuid}", ['is_data_officer' => true])->assertOk()->assertJsonPath('data.is_data_officer', true);
    $this->postJson("/api/v1/users/{$admin->uuid}/deactivate")->assertOk();
    expect(AuditLog::query()->where('action', 'user.data_officer_changed')->count())->toBe(1);
})->group('RF-045', 'RF-047');

it('reactivates a user within the plan limit', function () {
    $admin = adminOf();
    $receptionist = User::factory()->for($admin->tenant)->create(['role' => 'receptionist', 'status' => 'inactivo']);

    $this->postJson("/api/v1/users/{$receptionist->uuid}/reactivate")->assertOk()->assertJsonPath('data.status', 'activo');
    expect(AuditLog::query()->where('action', 'user.reactivated')->count())->toBe(1);
})->group('RF-042');

it('resends the invitation of a pending user only', function () {
    $admin = adminOf();
    $pending = User::factory()->for($admin->tenant)->create(['role' => 'receptionist', 'password' => null]);
    $active = User::factory()->for($admin->tenant)->create(['role' => 'receptionist']);

    $this->postJson("/api/v1/users/{$pending->uuid}/invitation")->assertNoContent();
    $this->postJson("/api/v1/users/{$pending->uuid}/invitation")->assertNoContent();
    expect(OneTimeToken::query()->where('tokenable_id', $pending->id)->whereNull('invalidated_at')->count())->toBe(1);

    $this->postJson("/api/v1/users/{$active->uuid}/invitation")->assertStatus(409)->assertJsonPath('rule', 'RF-042');
})->group('RF-042');

it('lists the users of the clinic with filters and pagination', function () {
    $admin = adminOf();
    User::factory()->for($admin->tenant)->count(2)->create(['role' => 'dentist']);
    User::factory()->for($admin->tenant)->create(['role' => 'receptionist', 'status' => 'inactivo']);
    User::factory()->for(Tenant::factory())->create(['role' => 'dentist']);

    $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(4, 'data')->assertJsonStructure(['meta' => ['total']]);
    $this->getJson('/api/v1/users?role=dentist')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/users?status=inactivo')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/users?per_page=101')->assertUnprocessable();
})->group('RF-042', 'RF-010');

it('updates the profile fields and audits the role change', function () {
    $admin = adminOf();
    $receptionist = User::factory()->for($admin->tenant)->create(['role' => 'receptionist']);

    $this->getJson("/api/v1/users/{$receptionist->uuid}")->assertOk()->assertJsonPath('data.id', $receptionist->uuid);
    $this->patchJson("/api/v1/users/{$receptionist->uuid}", ['role' => 'dentist', 'cop_number' => '45678', 'specialty' => 'Endodoncia'])
        ->assertOk()
        ->assertJsonPath('data.role', 'dentist');

    expect(AuditLog::query()->where('action', 'user.role_changed')->count())->toBe(1);
})->group('RF-042', 'RF-043');

it('keeps the email unique per clinic but reusable across clinics', function () {
    $admin = adminOf();
    User::factory()->for($admin->tenant)->create(['email' => 'rosa@correo.test']);
    User::factory()->for(Tenant::factory())->create(['email' => 'luis@correo.test']);

    createUser(['name' => 'Rosa Quispe', 'email' => 'rosa@correo.test', 'role' => 'receptionist'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    createUser(['name' => 'Luis Paz', 'email' => 'luis@correo.test', 'role' => 'receptionist'])->assertCreated();
})->group('RN-05');
