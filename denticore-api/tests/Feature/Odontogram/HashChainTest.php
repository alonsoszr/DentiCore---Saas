<?php

/*
 * Cadena de hashes del odontograma por paciente (TASK-045; SDD §1.9, §2.6; DD-46, DD-37, DI-11,
 * RNF-089).
 */

use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Platform\Services\RucValidator;
use App\Support\Evidence\HashChainVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('chains each entry to the previous one of the same patient', function () {
    $first = OdontogramEntry::factory()->create();
    $second = OdontogramEntry::factory()->create(['tenant_id' => $first->tenant_id, 'attention_id' => $first->attention_id, 'tooth' => 26]);
    $otherPatient = OdontogramEntry::factory()->create(['tenant_id' => $first->tenant_id]);

    TenantContext::run($first->tenant_id, function () use ($first, $second, $otherPatient) {
        $rows = DB::table('odontogram_entries')->get()->keyBy('id');

        expect($rows[$first->id]->prev_hash)->toBe(str_repeat('0', 64))
            ->and($rows[$second->id]->prev_hash)->toBe($rows[$first->id]->hash)
            ->and($rows[$otherPatient->id]->prev_hash)->toBe(str_repeat('0', 64))
            ->and(app(HashChainVerifier::class)->brokenRows(DB::connection(), 'odontogram_entries', 'chain_patient_id', 'patient_id'))
            ->toBe([]);
    });
})->group('DD-46', 'DI-11');

it('detects any altered odontogram entry in the daily chain verification', function () {
    // La alteración la hace el propietario del esquema desactivando el disparador de
    // inmutabilidad, como lo haría un acceso indebido a la BD. Todo ocurre en una transacción
    // del propietario que se revierte al final; el comando corre sobre esa misma conexión.
    $owner = DB::connection('pgsql_migrator');
    $owner->beginTransaction();

    try {
        $tenantId = $owner->table('tenants')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Clínica Cadena', 'legal_name' => 'Clínica Cadena S.A.C.',
            'ruc' => '2099999903'.RucValidator::checkDigit('2099999903'), 'address' => 'Av. Sintética 456, Lima',
            'slug' => 'clinica-cadena-'.Str::lower(Str::random(8)),
            'subscription_plan_id' => $owner->table('subscription_plans')->where('code', 'pro')->value('id'),
            'status' => 'activa', 'created_at' => now(),
        ]);
        $owner->select("select set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
        $dentistId = $owner->table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => 'Odontóloga Sintética', 'role' => 'dentist',
            'email' => "odontologa{$tenantId}@cadena.test", 'cop_number' => '54321', 'status' => 'activo', 'created_at' => now(),
        ]);
        $newPatient = fn (string $seed) => $owner->table('patients')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'first_name' => 'Paciente', 'last_name' => $seed,
            'birth_date' => '1990-01-01', 'sex' => 'femenino', 'phone' => 'v1:x', 'search_name' => "paciente {$seed}",
            'document_type' => 'dni', 'document_number' => 'v1:x', 'document_hash' => hash('sha256', "d-{$seed}"),
            'clinical_record_number' => 'v1:x', 'clinical_record_hash' => hash('sha256', "c-{$seed}"),
            'archive_status' => 'activo', 'created_by' => $dentistId, 'created_at' => now(),
        ]);
        $patientId = $newPatient('uno');
        $mergedInto = $newPatient('dos');
        $attentionId = $owner->table('attentions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'patient_id' => $patientId, 'dentist_id' => $dentistId,
            'is_first_attention' => false, 'opened_at' => now(), 'created_at' => now(),
        ]);
        $findingId = $owner->table('finding_catalog')->insertGetId([
            'code' => 'PRUEBA_CADENA', 'name' => 'Hallazgo sintético', 'level' => 'superficie', 'dentition' => 'ambas',
            'introduced_in_version' => '1.0', 'is_active' => true, 'display_order' => 1,
        ]);
        $stateId = $owner->table('finding_states')->insertGetId([
            'finding_id' => $findingId, 'code' => 'ACTIVO', 'name' => 'Activo', 'color' => 'rojo', 'is_active' => true,
        ]);
        $record = fn (int $tooth) => $owner->table('odontogram_entries')->insertGetId([
            'tenant_id' => $tenantId, 'patient_id' => $patientId, 'chain_patient_id' => $patientId, 'attention_id' => $attentionId,
            'entry_type' => 'evolucion', 'tooth' => $tooth, 'surfaces' => '{O}', 'finding_id' => $findingId,
            'finding_state_id' => $stateId, 'color' => 'rojo', 'origin' => 'manual', 'author_id' => $dentistId,
            'author_cop' => '54321', 'prev_hash' => '', 'hash' => '',
        ]);
        $ids = array_map($record, [16, 26, 36]);
        $verify = fn () => app(HashChainVerifier::class)->brokenRows($owner, 'odontogram_entries', 'chain_patient_id', 'patient_id');

        // La fusión de fichas (DD-37, DI-21) reasigna patient_id sin romper la cadena.
        $owner->select("select set_config('app.patient_merge', 'on', true)");
        $owner->table('odontogram_entries')->where('patient_id', $patientId)->update(['patient_id' => $mergedInto]);
        $owner->select("select set_config('app.patient_merge', 'off', true)");

        expect($verify())->toBe([]);
        $this->artisan('integrity:verify', ['--connection' => 'pgsql_migrator'])->assertSuccessful();

        $owner->statement('ALTER TABLE odontogram_entries DISABLE TRIGGER trg_forbid_update_delete');
        $owner->update("UPDATE odontogram_entries SET note = 'Alterada' WHERE id = ?", [$ids[1]]);
        $owner->statement('ALTER TABLE odontogram_entries ENABLE TRIGGER trg_forbid_update_delete');

        expect($verify())->toBe([$ids[1]]);
        $this->artisan('integrity:verify', ['--connection' => 'pgsql_migrator'])
            ->expectsOutputToContain("odontogram_entries: filas alteradas {$ids[1]}")
            ->assertFailed();
    } finally {
        $owner->rollBack();
    }
})->group('T-068', 'DD-46', 'RNF-089', 'DD-37');
