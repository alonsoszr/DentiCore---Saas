<?php

/*
 * Búsqueda de pacientes (TASK-033; CUS-13; RF-010, RF-054, RN-68, DI-14, RNF-007, RNF-190).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientSearchRepository;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\RucValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function searchPatients(string $query): TestResponse
{
    return test()->getJson('/api/v1/patients?'.$query);
}

it('finds patients by name without accents or case and excludes inactive archives by default', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    TenantContext::run($tenant, function () use ($tenant) {
        Patient::factory()->for($tenant)->create(['first_name' => 'José', 'last_name' => 'Núñez']);
        Patient::factory()->for($tenant)->create(['first_name' => 'Ana', 'last_name' => 'Pérez']);
        Patient::factory()->for($tenant)->create(['first_name' => 'Luis', 'last_name' => 'Nuñez Paz'])
            ->forceFill(['archive_status' => 'pasivo'])->save();
    });

    $found = searchPatients('q=nunez')->assertOk();
    expect($found->json('data.*.last_name'))->toBe(['Núñez']);

    expect(searchPatients('q=PEREZ')->json('data.*.first_name'))->toBe(['Ana']);

    // RF-054: el filtro explícito incluye las fichas en archivo pasivo.
    expect(searchPatients('q=nunez&archive_status=pasivo')->json('data.*.first_name'))->toBe(['Luis']);
})->group('RF-054', 'DI-14', 'RNF-191');

it('orders by last name with the es-PE collation', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    TenantContext::run($tenant, function () use ($tenant) {
        foreach (['Ortiz', 'Ñahui', 'Nuñez', 'Álvarez'] as $lastName) {
            Patient::factory()->for($tenant)->create(['last_name' => $lastName]);
        }
    });

    expect(searchPatients('per_page=10')->json('data.*.last_name'))->toBe(['Álvarez', 'Nuñez', 'Ñahui', 'Ortiz']);
})->group('RNF-190');

it('limits per_page to 100', function () {
    $this->actingAsRole('receptionist', Tenant::factory()->create());

    searchPatients('per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    searchPatients('per_page=100')->assertOk();
})->group('RF-010');

it('looks a patient up by document type and number through the blind index', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create(['document_number' => '45678912']));
    $foreign = Tenant::factory()->create();
    TenantContext::run($foreign, fn () => Patient::factory()->for($foreign)->create(['document_number' => '11112222']));

    $this->getJson('/api/v1/patients/lookup?document_type=dni&document_number=45678912')
        ->assertOk()
        ->assertJsonPath('data.id', $patient->uuid);

    // No existe en la clínica (aunque exista en otra): 404.
    $this->getJson('/api/v1/patients/lookup?document_type=dni&document_number=11112222')->assertNotFound();
    $this->getJson('/api/v1/patients/lookup?document_type=dni')->assertUnprocessable();
})->group('RF-056', 'RF-054', 'RN-09');

it('searches 20 000 synthetic patients through the trigram index filtering the clinic explicitly', function () {
    // La conexión de plataforma omite la RLS (BYPASSRLS). Para no confirmar ni borrar 20 000
    // filas, la prueba usa una transacción del propietario del esquema que se revierte al final:
    // inserta los datos, actualiza las estadísticas y, solo dentro de ella, quita FORCE ROW LEVEL
    // SECURITY para que el propietario lea sin RLS igual que `denticore_platform`.
    $owner = DB::connection('pgsql_migrator');
    $owner->beginTransaction();

    try {
        $newTenant = function (string $name, string $rucBody) use ($owner): int {
            $tenantId = $owner->table('tenants')->insertGetId([
                'uuid' => (string) Str::uuid(), 'name' => $name, 'legal_name' => "{$name} S.A.C.",
                'ruc' => $rucBody.RucValidator::checkDigit($rucBody), 'address' => 'Av. Sintética 123, Lima',
                'slug' => 'clinica-volumen-'.Str::lower(Str::random(8)),
                'subscription_plan_id' => $owner->table('subscription_plans')->where('code', 'pro')->value('id'),
                'status' => 'activa', 'created_at' => now(),
            ]);
            // RN-67: quien registra las fichas.
            $owner->table('users')->insert([
                'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => 'Recepción', 'role' => 'receptionist',
                'email' => "recepcion{$tenantId}@volumen.test", 'status' => 'activo', 'created_at' => now(),
            ]);

            return $tenantId;
        };
        $tenantId = $newTenant('Clínica Volumen', '2099999901');
        $otherTenantId = $newTenant('Clínica Vecina', '2099999902');
        $insert = fn (int $tenant, string $searchName, int $first, int $last) => $owner->unprepared(<<<SQL
            SELECT set_config('app.tenant_id', '{$tenant}', true);
            INSERT INTO patients (uuid, tenant_id, first_name, last_name, birth_date, sex, phone, search_name,
                                  document_type, document_number, document_hash, clinical_record_number,
                                  clinical_record_hash, archive_status, created_by, created_at)
            SELECT gen_random_uuid(), {$tenant}, 'Nombre' || g, 'Apellido' || g, DATE '1990-01-01', 'femenino', 'v1:x',
                   {$searchName}, 'dni', 'v1:x', encode(sha256(('h-' || g)::bytea), 'hex'), 'v1:x',
                   encode(sha256(('c-' || g)::bytea), 'hex'), 'activo',
                   (SELECT id FROM users WHERE tenant_id = {$tenant} LIMIT 1), now()
              FROM generate_series({$first}, {$last}) AS g;
            SQL);

        $insert($tenantId, "'nombre' || g || ' apellido' || g", 1, 20000);
        $insert($tenantId, "'jose nunez'", 20001, 20001);
        $insert($otherTenantId, "'jose nunez'", 20001, 20001);
        $owner->unprepared('ANALYZE patients; ALTER TABLE patients NO FORCE ROW LEVEL SECURITY;');

        $search = new PatientSearchRepository($owner);
        $query = $search->query($tenantId, ['q' => 'Núñez']);
        $plan = collect($owner->select('EXPLAIN '.$query->toRawSql()))->pluck('QUERY PLAN')->implode("\n");

        expect($plan)->toContain('patients_search_name_trgm_idx')
            ->and($plan)->not->toContain('Seq Scan on patients')
            // Sin RLS, solo el filtro explícito separa las clínicas.
            ->and($query->count())->toBe(1)
            ->and($search->query($otherTenantId, ['q' => 'nunez'])->count())->toBe(1);
    } finally {
        $owner->rollBack();
    }
})->group('RNF-007', 'RNF-030', 'DI-14', 'DD-40');

it('binds the search to the platform connection', function () {
    // Las pruebas de feature leen por la conexión de la prueba (tests/Concerns/RefreshDatabase).
    $this->app->forgetInstance(PatientSearchRepository::class);
    $connection = (fn () => $this->connection)->call(app(PatientSearchRepository::class));

    expect($connection->getName())->toBe('pgsql_platform');
})->group('DD-40');
