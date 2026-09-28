<?php

/*
 * T-017 (SDD §2.13, §6.3.1; DD-40, DI-10, RNF-102): la RLS es la segunda barrera del
 * aislamiento, independiente del Global Scope.
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('runs the test suite under the app database role without BYPASSRLS', function () {
    $role = DB::selectOne('select current_user as name, rolbypassrls as bypass from pg_roles where rolname = current_user');

    expect($role->name)->toBe('denticore_app')
        ->and($role->bypass)->toBeFalse();
})->group('DD-40', 'RNF-102');

it('returns no rows of another clinic from a raw query under the app database role', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    Patient::factory()->for($tenantA)->count(2)->create();
    Patient::factory()->for($tenantB)->create();

    $rawCount = fn (): int => DB::selectOne('select count(*) as total from patients')->total;

    // Sin app.tenant_id: consulta cruda (sin Global Scope) y 0 filas.
    expect($rawCount())->toBe(0);

    // Con la clínica A: la consulta cruda solo ve sus filas, aunque filtre por la otra clínica.
    TenantContext::run($tenantA, function () use ($rawCount, $tenantB) {
        expect($rawCount())->toBe(2)
            ->and(DB::selectOne('select count(*) as total from patients where tenant_id = ?', [$tenantB->id])->total)->toBe(0);
    });
})->group('DD-40', 'RNF-102');

it('rejects writing a row for another clinic', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $patientB = Patient::factory()->for($tenantB)->create();

    TenantContext::run($tenantA, function () use ($patientB, $tenantB) {
        $updated = DB::update("update patients set first_name = 'X' where id = ?", [$patientB->id]);
        expect($updated)->toBe(0);

        expect(fn () => DB::transaction(fn () => DB::insert(
            "insert into patients (uuid, tenant_id, document_id, document_id_hash, first_name, last_name, birth_date) values (gen_random_uuid(), ?, 'x', repeat('0', 64), 'A', 'B', '2000-01-01')",
            [$tenantB->id],
        )))->toThrow(QueryException::class);
    });
})->group('DD-40', 'RNF-102');

it('enables and forces RLS on every table with a non nullable tenant_id', function () {
    $tables = collect(DB::select(<<<'SQL'
        select c.table_name as name, t.relrowsecurity as enabled, t.relforcerowsecurity as forced,
               exists (select 1 from pg_policies p where p.tablename = c.table_name and p.policyname = 'tenant_isolation') as has_policy
          from information_schema.columns c
          join pg_class t on t.relname = c.table_name and t.relkind in ('r', 'p')
          join pg_namespace n on n.oid = t.relnamespace and n.nspname = 'public'
         where c.table_schema = 'public' and c.column_name = 'tenant_id' and c.is_nullable = 'NO'
        SQL));

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        expect($table->enabled && $table->forced && $table->has_policy)
            ->toBeTrue("La tabla {$table->name} tiene tenant_id NOT NULL y no tiene la RLS habilitada y forzada");
    }
})->group('DD-40', 'DI-19');
