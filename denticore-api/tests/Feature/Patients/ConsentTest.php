<?php

/*
 * Consentimiento de datos (TASK-036; CUS-17, SDD §5.10, §4.2; RF-047, RF-065, RF-066, RF-067,
 * RN-10, RN-11, RN-12, RN-15, DD-13, DD-14, DD-28, DD-46).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Files\GeneratedDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'America/Lima'));
});

/** @param  array<string, mixed>  $attributes */
function consentClinic(string $plan = 'pro', array $attributes = []): Tenant
{
    return Tenant::factory()->create(['subscription_plan' => $plan, 'contact_email' => 'contacto@sonrisa.test', ...$attributes]);
}

/** @param  array<string, mixed>  $attributes */
function consentPatient(Tenant $tenant, array $attributes = []): Patient
{
    return TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create([
        'document_id' => '45678912', 'first_name' => 'Ana', 'last_name' => 'Núñez', 'birth_date' => '1990-01-31',
        'email' => 'ana@correo.test', ...$attributes,
    ]));
}

/** Paciente de 15 años con su madre como representante vigente. */
function minorWithRepresentative(Tenant $tenant, ?User $portalUser = null): Patient
{
    $minor = consentPatient($tenant, ['document_id' => '71234567', 'birth_date' => '2011-06-01', 'email' => null]);

    TenantContext::run($tenant, function () use ($minor, $portalUser) {
        $representative = app(LegalRepresentativeService::class)->add($minor, [
            'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani',
            'relationship' => 'madre', 'phone' => '912345678', 'email' => 'rosa@correo.test', 'valid_from' => '2026-01-01',
        ]);
        if ($portalUser !== null) {
            $representative->forceFill(['user_id' => $portalUser->id])->save();
        }
    });

    return $minor;
}

/** @return array<string, mixed> */
function grantPayload(array $overrides = []): array
{
    return ['channel' => 'presencial', 'purpose_care' => true, 'confirmation_document_number' => '45678912', ...$overrides];
}

function grantConsent(Patient $patient, array $overrides = []): TestResponse
{
    return test()->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/patients/{$patient->uuid}/consents", grantPayload($overrides));
}

it('presents the current template filled with the clinic, officer and holder data', function () {
    $tenant = consentClinic('pro');
    $this->actingAsRole('receptionist', $tenant);
    User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'name' => 'Lucía Prado', 'email' => 'oficial@sonrisa.test', 'is_data_officer' => true]);
    $patient = consentPatient($tenant);

    $preview = $this->getJson("/api/v1/patients/{$patient->uuid}/consents/preview")->assertOk();

    $text = $preview->json('data.text');
    expect($preview->json('data.template_version'))->toBe(1)
        ->and($text)->toContain($tenant->legal_name)
        ->and($text)->toContain($tenant->ruc)
        ->and($text)->toContain('Lucía Prado (oficial@sonrisa.test)')
        ->and($text)->toContain('Ana Núñez')
        ->and($text)->toContain(config('services.consent.transfers'))
        ->and($text)->not->toContain('{{')
        ->and($preview->json('data.text_sha256'))->toBe(hash('sha256', $text))
        ->and($preview->json('data.granted_by'))->toBe('titular')
        // CA-17.1: el formulario parte con las opcionales sin marcar; la IA no está activada.
        ->and($preview->json('data.available_purposes'))->toBe(['purpose_care', 'purpose_notifications', 'purpose_risk', 'purpose_surveys']);
})->group('RF-047', 'RF-065', 'DD-28', 'CA-17.1');

it('uses the clinic contact email when no data officer is designated', function () {
    $tenant = consentClinic('pro');
    $this->actingAsRole('receptionist', $tenant);

    $text = $this->getJson('/api/v1/patients/'.consentPatient($tenant)->uuid.'/consents/preview')->json('data.text');

    expect($text)->toContain('contacto@sonrisa.test');
})->group('RF-047');

it('hides the AI and risk purposes for a basic plan clinic', function () {
    $basic = consentClinic('basic');
    $this->actingAsRole('receptionist', $basic);
    $patient = consentPatient($basic);

    $preview = $this->getJson("/api/v1/patients/{$patient->uuid}/consents/preview")->assertOk();

    expect($preview->json('data.available_purposes'))->toBe(['purpose_care', 'purpose_notifications', 'purpose_surveys'])
        ->and($preview->json('data.text'))->not->toContain('(c) ')
        ->and($preview->json('data.text'))->not->toContain('(d) ');
    grantConsent($patient, ['purpose_risk' => true])->assertUnprocessable()->assertJsonValidationErrors(['purpose_risk']);
    grantConsent($patient, ['purpose_ai' => true])->assertUnprocessable()->assertJsonValidationErrors(['purpose_ai']);
})->group('T-039', 'CA-17.5', 'RN-11', 'RN-08');

it('offers the AI purpose only when the plan includes it and the clinic enabled it', function () {
    $tenant = consentClinic('pro');
    $this->actingAsRole('receptionist', $tenant);
    TenantContext::run($tenant, fn () => DB::table('clinic_settings')->where('tenant_id', $tenant->id)->update(['ai_enabled' => true]));

    expect($this->getJson('/api/v1/patients/'.consentPatient($tenant)->uuid.'/consents/preview')->json('data.available_purposes'))
        ->toContain('purpose_ai');
})->group('CA-17.5', 'RN-53');

it('registers a consent with its evidence, certificate, email and audit event', function () {
    $tenant = consentClinic('pro');
    $receptionist = $this->actingAsRole('receptionist', $tenant);
    $patient = consentPatient($tenant);

    $response = grantConsent($patient, ['purpose_notifications' => true, 'confirmation_document_number' => ' 45678912 '])
        ->assertCreated()
        ->assertJsonPath('data.status', 'vigente')
        ->assertJsonPath('data.template_version', 1)
        ->assertJsonPath('data.purpose_care', true)
        ->assertJsonPath('data.purpose_notifications', true)
        ->assertJsonPath('data.purpose_risk', false)
        ->assertJsonPath('data.granted_by', 'titular')
        ->assertJsonPath('data.channel', 'presencial')
        ->assertJsonPath('data.outdated', false);

    TenantContext::run($tenant, function () use ($response, $patient, $receptionist) {
        $consent = Consent::query()->where('uuid', $response->json('data.id'))->sole();

        expect($consent->assisted_by)->toBe($receptionist->id)
            ->and($consent->ip_address)->toBe('127.0.0.1')
            ->and($consent->text_sha256)->toBe(hash('sha256', $consent->rendered_text))
            ->and(app(EvidenceSealer::class)->verify($consent->evidencePayload(), $consent->evidence_hmac))->toBeTrue()
            ->and($consent->certificate->kind)->toBe('constancia_consentimiento')
            ->and($consent->certificate->status)->toBe('pendiente');

        $notification = Notification::query()->where('recipient_patient_id', $patient->id)->sole();
        expect($notification->event->value)->toBe('consentimiento_constancia')
            ->and($notification->payload['links']['certificate'])->toBe(config('app.spa_url')."/c/{$patient->tenant->slug}/portal/consentimiento");
    });

    expect(AuditLog::query()->where('action', 'consent.granted')->sole()->patient_uuid)->toBe($patient->uuid);
    $this->getJson("/api/v1/patients/{$patient->uuid}")->assertJsonPath('data.has_current_consent', true);
})->group('RF-065', 'RF-066', 'DD-46', 'CUS-17');

it('does not register a consent without the care purpose', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $patient = consentPatient($tenant);

    grantConsent($patient, ['purpose_care' => false])->assertUnprocessable()->assertJsonValidationErrors(['purpose_care']);
    grantConsent($patient, ['purpose_care' => null])->assertUnprocessable()->assertJsonValidationErrors(['purpose_care']);

    expect(TenantContext::run($tenant, fn () => Consent::query()->count()))->toBe(0);
    $this->getJson("/api/v1/patients/{$patient->uuid}")->assertJsonPath('data.has_current_consent', false);
})->group('T-036', 'CA-17.2', 'RN-10', 'RN-11');

it('rejects a confirmation document that does not match the grantor', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);

    grantConsent(consentPatient($tenant), ['confirmation_document_number' => '45678913'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmation_document_number']);
})->group('RF-065', 'CUS-17');

it('records the representative as grantor for a 15 year old patient', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $representativeUser = User::factory()->for($tenant)->create(['role' => 'patient', 'email' => 'rosa.portal@correo.test']);
    $minor = minorWithRepresentative($tenant, $representativeUser);

    expect($this->getJson("/api/v1/patients/{$minor->uuid}/consents/preview")->json('data.granted_by'))->toBe('representante');

    // Confirma con su documento la representante, no el menor.
    grantConsent($minor, ['confirmation_document_number' => '71234567'])->assertUnprocessable();
    $response = grantConsent($minor, ['confirmation_document_number' => '41234567'])
        ->assertCreated()
        ->assertJsonPath('data.granted_by', 'representante');

    TenantContext::run($tenant, function () use ($response, $minor, $representativeUser) {
        $representative = $minor->representatives()->sole();
        expect(Consent::query()->where('uuid', $response->json('data.id'))->value('legal_representative_id'))->toBe($representative->id)
            ->and($response->json('data.representative_id'))->toBe($representative->uuid)
            // La constancia va a la cuenta de portal de la representante.
            ->and(Notification::query()->sole()->recipient_user_id)->toBe($representativeUser->id);
    });
})->group('T-037', 'CA-17.3', 'RN-12', 'RF-066');

it('does not start a consent for a minor without a current representative', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $minor = consentPatient($tenant, ['birth_date' => '2011-06-01']);

    $this->getJson("/api/v1/patients/{$minor->uuid}/consents/preview")->assertUnprocessable()->assertJsonPath('rule', 'RN-12');
    grantConsent($minor)->assertUnprocessable()->assertJsonPath('rule', 'RN-12');
})->group('RN-12', 'CUS-17');

it('supersedes the previous consent without altering its data', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $patient = consentPatient($tenant);

    $first = grantConsent($patient)->assertCreated()->json('data.id');
    $before = TenantContext::run($tenant, fn () => (array) DB::table('consents')->where('uuid', $first)->first());

    $this->travel(1)->days();
    $second = grantConsent($patient, ['purpose_surveys' => true])->assertCreated()->json('data.id');

    $after = TenantContext::run($tenant, fn () => (array) DB::table('consents')->where('uuid', $first)->first());
    expect($after['status'])->toBe('sustituido')
        ->and($after['superseded_at'])->not->toBeNull()
        ->and(Arr::except($after, ['status', 'superseded_at', 'updated_at']))->toBe(Arr::except($before, ['status', 'superseded_at', 'updated_at']));

    $list = $this->getJson("/api/v1/patients/{$patient->uuid}/consents")->assertOk();
    expect($list->json('data.*.id'))->toBe([$second, $first])
        ->and($list->json('data.*.status'))->toBe(['vigente', 'sustituido']);
})->group('T-038', 'CA-17.4', 'RN-15');

it('flags patients whose consent uses a previous template version', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $patient = consentPatient($tenant);
    grantConsent($patient)->assertCreated();

    // Se publica la versión 2 de la plantilla (dentro de la transacción de la prueba).
    DB::table('consent_templates')->insert([
        'version' => 2, 'body' => 'v2 {{titular.nombre}}', 'body_sha256' => hash('sha256', 'v2 {{titular.nombre}}'), 'published_at' => now(),
    ]);
    DB::table('platform_settings')->where('key', 'consent.current_version')->update(['value' => '2']);
    app()->forgetScopedInstances(); // cada solicitud real lee la versión de nuevo

    // RN-15: el anterior sigue vigente, marcado como desactualizado.
    $this->getJson("/api/v1/patients/{$patient->uuid}/consents")
        ->assertJsonPath('data.0.status', 'vigente')
        ->assertJsonPath('data.0.outdated', true);
    $this->getJson("/api/v1/patients/{$patient->uuid}")
        ->assertJsonPath('data.has_current_consent', true)
        ->assertJsonPath('data.consent_outdated', true);

    grantConsent($patient)->assertCreated()->assertJsonPath('data.template_version', 2)->assertJsonPath('data.outdated', false);
})->group('T-040', 'RN-15', 'RF-067');

it('registers a paper consent with the scanned form instead of the typed document', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $patient = consentPatient($tenant);

    grantConsent($patient, ['channel' => 'papel', 'confirmation_document_number' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scanned_file']);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->post("/api/v1/patients/{$patient->uuid}/consents", [
        'channel' => 'papel',
        'purpose_care' => '1',
        'scanned_file' => UploadedFile::fake()->create('consentimiento.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.channel', 'papel');

    TenantContext::run($tenant, fn () => expect(Consent::query()->where('uuid', $response->json('data.id'))->sole()->scannedFile->scan_status)->toBe('pendiente'));
})->group('CUS-17', 'RNF-103');

it('generates the certificate in the documents queue and hands out a signed URL', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $consentId = grantConsent(consentPatient($tenant))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/consents/{$consentId}/certificate")
        ->assertOk()
        ->assertJsonPath('data.status', 'pendiente')
        ->assertJsonPath('data.url', null);

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    $certificate = $this->getJson("/api/v1/consents/{$consentId}/certificate")->assertOk()->assertJsonPath('data.status', 'listo');
    expect($certificate->json('data.url'))->toContain('/files/')->toContain('signature=');

    TenantContext::run($tenant, function () use ($consentId) {
        $document = Consent::query()->where('uuid', $consentId)->sole()->certificate;
        expect($document)->toBeInstanceOf(GeneratedDocument::class)
            ->and(Storage::disk('s3')->get($document->storedFile->path))->toStartWith('%PDF');
    });
})->group('RF-066', 'DD-18', 'RNF-012');

it('hides the consents of another clinic', function () {
    $sonrisa = consentClinic();
    $this->actingAsRole('receptionist', $sonrisa);
    $consentId = grantConsent(consentPatient($sonrisa))->assertCreated()->json('data.id');

    $muela = consentClinic();
    $this->actingAsRole('receptionist', $muela);

    $this->getJson("/api/v1/consents/{$consentId}/certificate")->assertNotFound();
})->group('RN-03', 'RNF-101');

it('requires a current consent with the purpose through the consent middleware', function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'consent:atencion'])
        ->get('/api/v1/_probe/patients/{patient}/clinical', fn () => response()->noContent());
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'consent:prediccion'])
        ->get('/api/v1/_probe/patients/{patient}/risk', fn () => response()->noContent());
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'consent:ia'])
        ->get('/api/v1/_probe/patients/{patient}/ai', fn () => response()->noContent());

    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $patient = consentPatient($tenant);

    $this->getJson("/api/v1/_probe/patients/{$patient->uuid}/clinical")->assertUnprocessable()->assertJsonPath('rule', 'RN-10');

    grantConsent($patient)->assertCreated();
    $this->getJson("/api/v1/_probe/patients/{$patient->uuid}/clinical")->assertNoContent();
    $this->getJson("/api/v1/_probe/patients/{$patient->uuid}/risk")->assertUnprocessable()->assertJsonPath('rule', 'RN-58');
    $this->getJson("/api/v1/_probe/patients/{$patient->uuid}/ai")->assertForbidden()->assertJsonPath('rule', 'RN-53');

    // CA-64.3: un paciente bloqueado no habilita registros clínicos.
    TenantContext::run($tenant, fn () => $patient->forceFill(['archive_status' => 'bloqueado'])->save());
    $this->getJson("/api/v1/_probe/patients/{$patient->uuid}/clinical")->assertUnprocessable()->assertJsonPath('rule', 'RN-10');
})->group('RN-10', 'RN-53', 'RN-58', 'CA-64.3');

it('stops honoring a consent granted by a representative once the patient turns 18', function () {
    $tenant = consentClinic();
    $this->actingAsRole('receptionist', $tenant);
    $minor = minorWithRepresentative($tenant);
    grantConsent($minor, ['confirmation_document_number' => '41234567'])->assertCreated();

    $this->getJson("/api/v1/patients/{$minor->uuid}")->assertJsonPath('data.has_current_consent', true);

    $this->travelTo(Carbon::parse('2029-06-01 09:00', 'America/Lima'));
    $this->getJson("/api/v1/patients/{$minor->uuid}")->assertJsonPath('data.has_current_consent', false);
})->group('RN-13', 'RF-061');
