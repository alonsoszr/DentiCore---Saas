<?php

/*
 * Esquema de consentimientos (TASK-035; SDD §2.5; DD-14, DD-28, RN-10, RN-11, RN-12, RN-14,
 * RN-15, DI-19, DI-21).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Inserta un consentimiento válido de la clínica activa y devuelve su id.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertConsent(Patient $patient, array $overrides = []): int
{
    return DB::table('consents')->insertGetId([
        'tenant_id' => $patient->tenant_id,
        'patient_id' => $patient->id,
        'consent_template_version' => 1,
        'purpose_care' => true,
        'purpose_notifications' => true,
        'granted_by' => 'titular',
        'channel' => 'presencial',
        'rendered_text' => 'Texto presentado.',
        'text_sha256' => str_repeat('a', 64),
        'granted_at' => now(),
        'evidence_hmac' => str_repeat('b', 64),
        ...$overrides,
    ]);
}

it('seeds the platform consent template v1 with its markers, hash and current version', function () {
    $template = DB::table('consent_templates')->where('version', 1)->first();

    expect($template)->not->toBeNull()
        ->and($template->body_sha256)->toBe(hash('sha256', $template->body))
        ->and($template->published_at)->not->toBeNull();

    foreach (['clinica.razon_social', 'clinica.ruc', 'clinica.direccion', 'oficial.contacto', 'titular.nombre', 'transferencias'] as $marker) {
        expect($template->body)->toContain('{{'.$marker.'}}');
    }
    foreach (['(a)', '(b)', '(c)', '(d)', '(e)'] as $purpose) {
        expect($template->body)->toContain($purpose);
    }

    expect(json_decode((string) DB::table('platform_settings')->where('key', 'consent.current_version')->value('value')))->toBe(1);
})->group('DD-28', 'RN-11', 'RN-15', 'RF-047');

it('rejects changing a published consent template', function () {
    expect(fn () => DB::transaction(fn () => DB::table('consent_templates')->where('version', 1)->update(['body' => 'Otro texto'])))
        ->toThrow(QueryException::class, 'immutable_row');
})->group('RN-15');

it('rejects updating a purpose of a granted consent', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $consentId = insertConsent(Patient::factory()->for($tenant)->create());

        foreach (['purpose_notifications' => false, 'rendered_text' => 'Otro', 'channel' => 'portal'] as $column => $value) {
            expect(fn () => DB::transaction(fn () => DB::table('consents')->where('id', $consentId)->update([$column => $value])))
                ->toThrow(QueryException::class, 'immutable_row');
        }
        expect(fn () => DB::transaction(fn () => DB::table('consents')->where('id', $consentId)->delete()))
            ->toThrow(QueryException::class, 'immutable_row');
    });
})->group('RN-11', 'RN-15', 'CA-17.4');

it('allows changing only the status columns and setting the certificate once', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create();
        $consentId = insertConsent($patient);

        DB::table('consents')->where('id', $consentId)->update(['status' => 'sustituido', 'superseded_at' => now(), 'updated_at' => now()]);
        expect(DB::table('consents')->where('id', $consentId)->value('status'))->toBe('sustituido');

        $document = fn () => DB::table('generated_documents')->insertGetId([
            'tenant_id' => $tenant->id, 'documentable_type' => 'consent', 'documentable_id' => $consentId,
            'kind' => 'constancia_consentimiento',
        ]);
        $first = $document();
        DB::table('consents')->where('id', $consentId)->update(['certificate_document_id' => $first]);

        // La constancia se fija una sola vez (RF-066).
        $second = $document();
        expect(fn () => DB::transaction(fn () => DB::table('consents')->where('id', $consentId)->update(['certificate_document_id' => $second])))
            ->toThrow(QueryException::class, 'immutable_row');
    });
})->group('RF-066', 'CA-17.4');

it('allows reassigning the patient only during a merge', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        [$secondary, $primary] = Patient::factory()->for($tenant)->count(2)->create();
        $consentId = insertConsent($secondary, ['status' => 'sustituido']);

        expect(fn () => DB::transaction(fn () => DB::table('consents')->where('id', $consentId)->update(['patient_id' => $primary->id])))
            ->toThrow(QueryException::class, 'immutable_row');

        DB::transaction(function () use ($consentId, $primary) {
            DB::select("select set_config('app.patient_merge', 'on', true)");
            DB::table('consents')->where('id', $consentId)->update(['patient_id' => $primary->id]);
        });
        expect(DB::table('consents')->where('id', $consentId)->value('patient_id'))->toBe($primary->id);
    });
})->group('DI-21', 'DD-37');

it('rejects a second current consent for the same patient', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create();
        insertConsent($patient);

        expect(fn () => DB::transaction(fn () => insertConsent($patient)))
            ->toThrow(QueryException::class, 'consents_current_unique');

        // Uno sustituido no cuenta para el índice parcial.
        insertConsent($patient, ['status' => 'sustituido']);
    });
})->group('RN-10', 'RN-15');

it('enforces the purpose, grantor and channel rules', function (array $overrides, string $constraint) {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant, $overrides, $constraint) {
        $patient = Patient::factory()->for($tenant)->create();

        expect(fn () => DB::transaction(fn () => insertConsent($patient, $overrides)))
            ->toThrow(QueryException::class, $constraint);
    });
})->with([
    'sin la finalidad de atención (a)' => [['purpose_care' => false], 'consents_purpose_care_check'],
    'otorgado por representante sin representante' => [['granted_by' => 'representante'], 'consents_representative_check'],
    'otorgado por el titular con representante' => [['legal_representative_id' => 999], 'consents_representative_check'],
    'en papel sin el escaneo' => [['channel' => 'papel'], 'consents_scanned_file_check'],
    'versión de plantilla inexistente' => [['consent_template_version' => 99], 'consents_consent_template_version_fkey'],
])->group('RN-11', 'RN-12', 'CA-17.2', 'CA-17.3');

it('rejects a consent that points to a patient of another clinic', function () {
    [$sonrisa, $muela] = Tenant::factory()->count(2)->create();
    $foreign = TenantContext::run($muela, fn () => Patient::factory()->for($muela)->create());

    expect(fn () => TenantContext::run($sonrisa, fn () => DB::transaction(fn () => insertConsent($foreign, ['tenant_id' => $sonrisa->id]))))
        ->toThrow(QueryException::class, 'consents_tenant_id_patient_id_fkey');
})->group('DI-19', 'RN-01');

it('records each purpose revocation once and keeps it immutable', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $consentId = insertConsent(Patient::factory()->for($tenant)->create());
        $revoke = fn (string $purpose) => DB::table('consent_purpose_revocations')->insertGetId([
            'tenant_id' => $tenant->id, 'consent_id' => $consentId, 'purpose' => $purpose, 'channel' => 'presencial',
        ]);

        $revocationId = $revoke('notificaciones');

        expect(fn () => DB::transaction(fn () => $revoke('notificaciones')))
            ->toThrow(QueryException::class, 'consent_purpose_revocations_consent_id_purpose_key');
        expect(fn () => DB::transaction(fn () => DB::table('consent_purpose_revocations')->where('id', $revocationId)->update(['reason' => 'x'])))
            ->toThrow(QueryException::class, 'immutable_row');
        expect(fn () => DB::transaction(fn () => $revoke('marketing')))
            ->toThrow(QueryException::class, 'consent_purpose_revocations_purpose_check');
    });
})->group('RN-14', 'RF-068');

it('hides the consents of another clinic under row level security', function () {
    [$sonrisa, $muela] = Tenant::factory()->count(2)->create();
    TenantContext::run($muela, fn () => insertConsent(Patient::factory()->for($muela)->create()));

    expect(TenantContext::run($sonrisa, fn () => DB::table('consents')->count()))->toBe(0)
        ->and(TenantContext::run($muela, fn () => DB::table('consents')->count()))->toBe(1);
})->group('DD-40', 'RNF-102');
