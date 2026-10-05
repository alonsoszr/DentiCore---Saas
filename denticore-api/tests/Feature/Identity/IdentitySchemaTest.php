<?php

/*
 * Esquema de identidad (TASK-026; SDD §2.4; DI-03, RN-75, RF-043, RF-047, RNF-132).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Inserta un usuario con SQL para probar las restricciones de la tabla, no las del modelo. */
function insertUser(array $columns): void
{
    DB::transaction(fn () => DB::table('users')->insert([
        'name' => 'Usuario de prueba',
        'email' => 'usuario-'.uniqid().'@clinica.test',
        'password' => 'x',
        'status' => 'activo',
        'created_at' => now(),
        ...$columns,
    ]));
}

it('rejects a dentist without COP number', function () {
    $tenant = Tenant::factory()->create();

    expect(fn () => insertUser(['tenant_id' => $tenant->id, 'role' => 'dentist']))
        ->toThrow(QueryException::class, 'users_dentist_cop_check');

    insertUser(['tenant_id' => $tenant->id, 'role' => 'dentist', 'cop_number' => '12345']);
    expect(DB::table('users')->where('cop_number', '12345')->exists())->toBeTrue();
})->group('RN-75', 'RF-043');

it('rejects the data officer flag on a role other than clinic_admin', function (string $role) {
    $tenant = Tenant::factory()->create();

    expect(fn () => insertUser(['tenant_id' => $tenant->id, 'role' => $role, 'cop_number' => '54321', 'is_data_officer' => true]))
        ->toThrow(QueryException::class, 'users_data_officer_check');
})->with(['dentist', 'receptionist', 'patient'])->group('RF-047');

it('keeps COP numbers unique within a clinic only', function () {
    [$first, $second] = Tenant::factory()->count(2)->create();

    insertUser(['tenant_id' => $first->id, 'role' => 'dentist', 'cop_number' => '11111']);
    insertUser(['tenant_id' => $second->id, 'role' => 'dentist', 'cop_number' => '11111']);

    expect(fn () => insertUser(['tenant_id' => $first->id, 'role' => 'dentist', 'cop_number' => '11111']))
        ->toThrow(QueryException::class, 'unique');
})->group('RN-75');

it('ties the super_admin role to a null clinic', function (string $role, bool $withTenant) {
    $tenant = Tenant::factory()->create();

    expect(fn () => insertUser(['tenant_id' => $withTenant ? $tenant->id : null, 'role' => $role]))
        ->toThrow(QueryException::class, 'users_platform_role_check');
})->with([
    'super_admin con clínica' => ['super_admin', true],
    'clinic_admin sin clínica' => ['clinic_admin', false],
])->group('RN-04', 'RN-05');

it('accepts only the statuses of SRS §5.5.7 and allows a pending user without password', function () {
    $tenant = Tenant::factory()->create();

    insertUser(['tenant_id' => $tenant->id, 'role' => 'receptionist', 'password' => null, 'status' => 'pendiente_activacion']);

    expect(fn () => insertUser(['tenant_id' => $tenant->id, 'role' => 'receptionist', 'status' => 'active']))
        ->toThrow(QueryException::class, 'users_status_check');
})->group('DD-22', 'CA-01.1');

it('keeps status and the legacy is_active flag in sync while both columns exist', function () {
    $user = User::factory()->create(['role' => 'receptionist']);
    expect($user->fresh())->status->toBe('activo')->is_active->toBeTrue();

    $user->update(['is_active' => false]);
    expect($user->fresh())->status->toBe('inactivo')->is_active->toBeFalse();

    $user->update(['status' => 'activo']);
    expect($user->fresh())->status->toBe('activo')->is_active->toBeTrue();
})->group('RNF-132');

it('stores the email in lowercase', function () {
    $user = User::factory()->create(['email' => 'Rosa.Quispe@Clinica.TEST']);

    expect($user->fresh()->email)->toBe('rosa.quispe@clinica.test');
})->group('RN-05');

it('creates the password history, recovery codes and extended token columns', function () {
    expect(DB::getSchemaBuilder()->getColumnListing('user_password_histories'))
        ->toContain('user_id', 'password_hash')
        ->and(DB::getSchemaBuilder()->getColumnListing('two_factor_recovery_codes'))
        ->toContain('user_id', 'code_hash', 'used_at')
        ->and(DB::getSchemaBuilder()->getColumnListing('personal_access_tokens'))
        ->toContain('tenant_id', 'ip_address', 'user_agent', 'device_label');
})->group('RF-040', 'RF-038', 'RF-050');
