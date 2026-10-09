<?php

/*
 * Consentimiento informado de procedimientos (TASK-060; CUS-82, CUS-83, SDD §2.5, §4.2, §4.3.3;
 * RF-072, RF-073, RF-074, RN-12, RN-76, DD-31, DD-46).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00', 'America/Lima'));
});

/** @param  array<string, mixed>  $attributes */
function icTenant(array $attributes = []): Tenant
{
    return Tenant::factory()->plan('pro')->create(['contact_email' => 'contacto@ic.test', ...$attributes]);
}

/** @param  array<string, mixed>  $attributes */
function icDentist(Tenant $tenant, array $attributes = []): User
{
    return User::factory()->for($tenant)->create([
        'role' => 'dentist', 'name' => 'Dr. Carlos Rivas', 'cop_number' => '98765', ...$attributes,
    ]);
}

/** @param  array<string, mixed>  $attributes */
function icPatient(Tenant $tenant, array $attributes = []): Patient
{
    return TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create([
        'document_number' => '45678912', 'first_name' => 'Ana', 'last_name' => 'Núñez', 'birth_date' => '1990-01-31',
        ...$attributes,
    ]));
}

/**
 * Clínica con un ítem de plan cuyo procedimiento requiere consentimiento informado y una plantilla
 * activa asociada al procedimiento. Vuelve las piezas armadas y el uuid del ítem.
 *
 * @param  array{title?: string, body?: string}  $template
 * @return array{tenant: Tenant, dentist: User, patient: Patient, procedure: Procedure, item: PlanItem, template: string}
 */
function icFixture(array $template = []): array
{
    $tenant = icTenant();
    $dentist = icDentist($tenant);
    $patient = icPatient($tenant);

    $item = TenantContext::run($tenant, function () use ($tenant, $patient, $dentist): PlanItem {
        $procedure = Procedure::factory()->create([
            'tenant_id' => $tenant->id, 'requires_informed_consent' => true,
        ]);
        $plan = TreatmentPlan::factory()->create([
            'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id,
        ]);

        return PlanItem::factory()->create([
            'tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id, 'tooth' => 16,
        ])->load('procedure');
    });

    test()->actingAsRole('clinic_admin', $tenant);
    $templateId = test()->postJson('/api/v1/informed-consent-templates', [
        'title' => $template['title'] ?? 'Consentimiento de extracción',
        'body' => $template['body'] ?? 'El paciente {{paciente}} autoriza el procedimiento {{procedimiento}} sobre la pieza {{pieza}}. '
            .'Riesgos: {{riesgos}}. Alternativas: {{alternativas}}. Informó: {{odontologo}}.',
        'procedures' => [$item->procedure->uuid],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

    return ['tenant' => $tenant, 'dentist' => $dentist, 'patient' => $patient, 'procedure' => $item->procedure, 'item' => $item, 'template' => $templateId];
}

/** @return array<string, mixed> */
function icSignPayload(array $overrides = []): array
{
    return ['channel' => 'dispositivo', 'signer' => 'titular', 'confirmation_document_number' => '45678912', ...$overrides];
}

function icSign(PlanItem $item, array $overrides = []): TestResponse
{
    return test()->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/plan-items/{$item->uuid}/informed-consents", icSignPayload($overrides));
}

it('previews the current template filled with the patient, item and dentist data', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);

    $preview = $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview?riesgos=Sangrado%20leve&alternativas=Corona")
        ->assertOk();

    $text = $preview->json('data.text');
    expect($preview->json('data.template_version'))->toBe(1)
        ->and($preview->json('data.signer'))->toBe('titular')
        ->and($preview->json('data.representative'))->toBeNull()
        ->and($preview->json('data.informed_by.id'))->toBe(auth()->user()->uuid)
        ->and($text)->toContain('Ana Núñez')
        ->and($text)->toContain($f['procedure']->name)
        ->and($text)->toContain('16')
        ->and($text)->toContain('Sangrado leve')
        ->and($text)->toContain('Corona')
        ->and($text)->toContain(auth()->user()->name)
        ->and($text)->not->toContain('{{')
        ->and($preview->json('data.text_sha256'))->toBe(hash('sha256', $text));
})->group('RF-073', 'RF-072', 'DD-31');

it('signs an informed consent with sealed evidence and audit event', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);

    $response = icSign($f['item'], ['riesgos' => 'Sangrado', 'alternativas' => 'Sin tratamiento'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'vigente')
        ->assertJsonPath('data.template_version', 1)
        ->assertJsonPath('data.signer', 'titular')
        ->assertJsonPath('data.channel', 'dispositivo')
        ->assertJsonPath('data.informed_by.id', auth()->user()->uuid);

    TenantContext::run($f['tenant'], function () use ($response, $f) {
        $consent = InformedConsent::query()->where('uuid', $response->json('data.id'))->sole();

        expect($consent->patient_id)->toBe($f['patient']->id)
            ->and($consent->plan_item_id)->toBe($f['item']->id)
            ->and($consent->signed_at->setTimezone('America/Lima')->toDateTimeString())->toBe('2026-10-08 09:00:00')
            ->and($consent->ip_address)->toBe('127.0.0.1')
            ->and($consent->informed_by)->toBe(auth()->id())
            ->and($consent->registered_by)->toBe(auth()->id())
            ->and($consent->text_sha256)->toBe(hash('sha256', $consent->rendered_text))
            ->and(app(EvidenceSealer::class)->verify($consent->evidencePayload(), $consent->evidence_hmac))->toBeTrue();
    });

    expect(AuditLog::query()->where('action', 'informed_consent.signed')->sole()->patient_uuid)->toBe($f['patient']->uuid);
})->group('RF-073', 'DD-46', 'CUS-83');

it('rejects a confirmation document that does not match the signer', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);

    icSign($f['item'], ['confirmation_document_number' => '45678913'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmation_document_number']);
})->group('RF-073', 'CUS-83');

it('registers a paper consent with the scanned form instead of the typed document', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);

    icSign($f['item'], ['channel' => 'papel', 'confirmation_document_number' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scanned_file']);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->post("/api/v1/plan-items/{$f['item']->uuid}/informed-consents", [
            'channel' => 'papel', 'signer' => 'titular', 'scanned_file' => UploadedFile::fake()->create('firma.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.channel', 'papel');

    TenantContext::run($f['tenant'], fn () => expect(InformedConsent::query()->where('uuid', $response->json('data.id'))->sole()->scannedFile->scan_status)->toBe('pendiente'));
})->group('CUS-83', 'DD-31', 'RNF-103');

it('requires the informing dentist when a receptionist registers the signature', function () {
    $f = icFixture();
    $this->actingAsRole('receptionist', $f['tenant']);

    icSign($f['item'])->assertUnprocessable()->assertJsonValidationErrors(['informed_by']);

    icSign($f['item'], ['informed_by' => $f['dentist']->uuid])
        ->assertCreated()
        ->assertJsonPath('data.informed_by.id', $f['dentist']->uuid);
})->group('RF-073', 'CUS-83');

it('previews for the receptionist the same text that is signed with the informing dentist', function () {
    $f = icFixture();
    $this->actingAsRole('receptionist', $f['tenant']);
    $query = http_build_query(['riesgos' => 'Sangrado leve', 'alternativas' => 'Corona', 'informed_by' => $f['dentist']->uuid]);

    $preview = $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview?{$query}")
        ->assertOk()
        ->assertJsonPath('data.informed_by.id', $f['dentist']->uuid);
    expect($preview->json('data.text'))->toContain($f['dentist']->name);

    $signed = icSign($f['item'], ['informed_by' => $f['dentist']->uuid, 'riesgos' => 'Sangrado leve', 'alternativas' => 'Corona'])
        ->assertCreated();
    expect($signed->json('data.text_sha256'))->toBe($preview->json('data.text_sha256'));

    $receptionist = TenantContext::run($f['tenant'], fn () => User::factory()->for($f['tenant'])->create(['role' => 'receptionist']));
    $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview?informed_by={$receptionist->uuid}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('informed_by');
})->group('RF-073', 'CUS-83');

it('uses the current representative as signer for a minor', function () {
    $tenant = icTenant();
    $dentist = icDentist($tenant);
    $minor = icPatient($tenant, ['document_number' => '71234567', 'birth_date' => '2011-06-01']);

    $unrepresented = TenantContext::run($tenant, function () use ($tenant, $minor, $dentist): PlanItem {
        $procedure = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_informed_consent' => true]);
        $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $minor->id, 'created_by' => $dentist->id]);

        return PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id])->load('procedure');
    });
    $this->actingAsRole('clinic_admin', $tenant);
    $this->postJson('/api/v1/informed-consent-templates', [
        'title' => 'Constructor', 'body' => '{{paciente}} {{procedimiento}} {{pieza}} {{odontologo}}', 'procedures' => [$unrepresented->procedure->uuid],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    $this->actingAsRole('dentist', $tenant);
    expect($this->getJson("/api/v1/plan-items/{$unrepresented->uuid}/informed-consents/preview")->json('data.signer'))->toBe('representante');
    icSign($unrepresented)->assertUnprocessable()->assertJsonPath('rule', 'RN-12');

    $representative = TenantContext::run($tenant, fn () => app(LegalRepresentativeService::class)->add($minor, [
        'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani',
        'relationship' => 'madre', 'phone' => '912345678', 'valid_from' => '2026-01-01',
    ]));

    $created = icSign($unrepresented, ['signer' => 'representante', 'confirmation_document_number' => '41234567'])
        ->assertCreated()
        ->assertJsonPath('data.signer', 'representante');

    TenantContext::run($tenant, function () use ($created, $representative) {
        expect(InformedConsent::query()->where('uuid', $created->json('data.id'))->sole()->legal_representative_id)->toBe($representative->id)
            ->and($created->json('data.representative_id'))->toBe($representative->uuid);
    });
})->group('RN-12', 'RF-073', 'CUS-83');

it('rejects any modification of a signed informed consent (immutable)', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);
    $uuid = icSign($f['item'])->assertCreated()->json('data.id');

    TenantContext::run($f['tenant'], function () use ($uuid) {
        // Cada intento va en su propio savepoint: el error no aborta la transacción de la prueba.
        foreach (['rendered_text' => 'alterado', 'text_sha256' => str_repeat('0', 64), 'signer' => 'representante', 'signed_at' => now()->subDay()] as $column => $value) {
            expect(fn () => DB::transaction(fn () => DB::table('informed_consents')->where('uuid', $uuid)->update([$column => $value])))
                ->toThrow(QueryException::class, 'immutable_row');
        }

        expect(fn () => DB::transaction(fn () => DB::table('informed_consents')->where('uuid', $uuid)->delete()))
            ->toThrow(QueryException::class, 'immutable_row');
        expect(DB::table('informed_consents')->where('uuid', $uuid)->value('rendered_text'))->not->toBe('alterado');
    });
})->group('T-046', 'RF-073', 'DD-31');

it('revokes a signed informed consent before the procedure and records the event', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);
    $uuid = icSign($f['item'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/informed-consents/{$uuid}/revoke", ['reason' => 'El paciente cambió de opinión'])
        ->assertOk()
        ->assertJsonPath('data.status', 'revocado')
        ->assertJsonPath('data.revocation_reason', 'El paciente cambió de opinión');

    TenantContext::run($f['tenant'], function () use ($uuid) {
        $consent = InformedConsent::query()->where('uuid', $uuid)->sole();
        expect($consent->revoked_at)->not->toBeNull()
            ->and($consent->status)->toBe('revocado');
    });

    expect(AuditLog::query()->where('action', 'informed_consent.revoked')->sole()->patient_uuid)->toBe($f['patient']->uuid);

    // RF-074: una segunda revocación (o una sobre uno ya utilizado) es un conflicto de estado.
    $this->postJson("/api/v1/informed-consents/{$uuid}/revoke", ['reason' => 'Otra vez'])
        ->assertConflict()
        ->assertJsonPath('rule', 'RF-074');
    $this->postJson("/api/v1/informed-consents/{$uuid}/revoke", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
})->group('T-046', 'RF-074', 'RN-76', 'CUS-83');

it('does not let another clinic revoke or see the consent', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);
    $uuid = icSign($f['item'])->assertCreated()->json('data.id');

    $muela = icTenant();
    $this->actingAsRole('receptionist', $muela);

    $this->postJson("/api/v1/informed-consents/{$uuid}/revoke", ['reason' => 'ajena'])->assertNotFound();
})->group('RN-03', 'RNF-101');

it('does not alter signed consents when a template is deactivated', function () {
    $f = icFixture();
    $this->actingAsRole('dentist', $f['tenant']);
    $uuid = icSign($f['item'])->assertCreated()->json('data.id');

    $before = TenantContext::run($f['tenant'], fn () => (array) DB::table('informed_consents')->where('uuid', $uuid)->first());

    $this->actingAsRole('clinic_admin', $f['tenant']);
    $this->postJson("/api/v1/informed-consent-templates/{$f['template']}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $after = TenantContext::run($f['tenant'], fn () => (array) DB::table('informed_consents')->where('uuid', $uuid)->first());
    expect(Arr::except($after, ['updated_at']))->toBe(Arr::except($before, ['updated_at']));

    // Desactivada, las nuevas vistas previas del mismo procedimiento ya no tienen plantilla.
    $this->actingAsRole('dentist', $f['tenant']);
    $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview")->assertUnprocessable();
})->group('T-046', 'RF-072', 'CUS-82');

it('rejects preview and signing without an active template or with several', function () {
    $f = icFixture();

    // La API ya no deja asociar dos plantillas activas al mismo procedimiento; la firma conserva
    // la verificación como defensa (p. ej. datos anteriores a esa regla), simulada en el pivote.
    $this->actingAsRole('clinic_admin', $f['tenant']);
    $otherProcedure = TenantContext::run($f['tenant'], fn () => Procedure::factory()->create(['tenant_id' => $f['tenant']->id])->uuid);
    $second = $this->postJson('/api/v1/informed-consent-templates', [
        'title' => 'Segunda', 'body' => 'Otro texto', 'procedures' => [$otherProcedure],
    ])->assertCreated()->json('data.id');
    TenantContext::run($f['tenant'], fn () => DB::table('procedure_informed_consent_template')->insert([
        'tenant_id' => $f['tenant']->id,
        'procedure_id' => $f['procedure']->id,
        'informed_consent_template_id' => DB::table('informed_consent_templates')->where('uuid', $second)->value('id'),
    ]));

    $this->actingAsRole('dentist', $f['tenant']);
    $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview")->assertUnprocessable()->assertJsonPath('rule', 'RF-073');
    icSign($f['item'])->assertUnprocessable()->assertJsonPath('rule', 'RF-073');

    // Sin plantillas activas: desactivar la única resta el pivote del render.
    $this->actingAsRole('clinic_admin', $f['tenant']);
    foreach ([$f['template'], $second] as $templateId) {
        $this->postJson("/api/v1/informed-consent-templates/{$templateId}/deactivate")->assertOk();
    }
    $this->actingAsRole('dentist', $f['tenant']);
    $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview")->assertUnprocessable()->assertJsonPath('rule', 'RF-073');
})->group('RF-073', 'CUS-82', 'CUS-83');

it('creates a new version only when the template body changes', function () {
    $f = icFixture();
    $this->actingAsRole('clinic_admin', $f['tenant']);

    // Cambiar solo el título no crea una versión.
    $this->putJson("/api/v1/informed-consent-templates/{$f['template']}", ['title' => 'Otro título'])
        ->assertOk()
        ->assertJsonPath('data.current_version', 1);

    // Cambiar el cuerpo crea la versión 2 y la deja vigente.
    $this->putJson("/api/v1/informed-consent-templates/{$f['template']}", ['body' => 'Nuevo cuerpo {{paciente}}'])
        ->assertOk()
        ->assertJsonPath('data.current_version', 2)
        ->assertJsonPath('data.body', 'Nuevo cuerpo {{paciente}}');

    $this->actingAsRole('dentist', $f['tenant']);
    $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview")
        ->assertOk()
        ->assertJsonPath('data.template_version', 2)
        ->assertJsonPath('data.text', 'Nuevo cuerpo Ana Núñez');
})->group('RF-072', 'CUS-82');

it('rejects templates with procedures outside the clinic', function () {
    $tenant = icTenant();
    $this->actingAsRole('clinic_admin', $tenant);

    $foreign = TenantContext::run(icTenant(), fn () => Procedure::factory()->create(['requires_informed_consent' => true])->uuid);

    $own = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_informed_consent' => true])->uuid;

    $this->postJson('/api/v1/informed-consent-templates', [
        'title' => 'Ajeno', 'body' => 'Texto', 'procedures' => [$own, $foreign],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['procedures.1'])
        ->assertJsonMissingValidationErrors(['procedures.0']);
})->group('RF-072', 'RNF-101', 'CUS-82');

it('keeps a single active template per procedure', function () {
    $f = icFixture();
    $this->actingAsRole('clinic_admin', $f['tenant']);
    $body = 'El paciente {{paciente}} autoriza {{procedimiento}}.';

    $this->postJson('/api/v1/informed-consent-templates', [
        'title' => 'Duplicada', 'body' => $body, 'procedures' => [$f['procedure']->uuid],
    ])->assertUnprocessable()->assertJsonValidationErrors(['procedures.0']);

    $other = TenantContext::run($f['tenant'], fn () => Procedure::factory()->create([
        'tenant_id' => $f['tenant']->id, 'requires_informed_consent' => true,
    ])->uuid);
    $second = $this->postJson('/api/v1/informed-consent-templates', [
        'title' => 'Segunda', 'body' => $body, 'procedures' => [$other],
    ])->assertCreated()->json('data.id');

    // Editar la segunda para agregarle el procedimiento de la primera también se rechaza.
    $this->putJson("/api/v1/informed-consent-templates/{$second}", ['procedures' => [$other, $f['procedure']->uuid]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['procedures.1'])
        ->assertJsonMissingValidationErrors(['procedures.0']);

    // Desactivada la primera, el procedimiento queda libre.
    $this->postJson("/api/v1/informed-consent-templates/{$f['template']}/deactivate")->assertOk();
    $this->putJson("/api/v1/informed-consent-templates/{$second}", ['procedures' => [$other, $f['procedure']->uuid]])
        ->assertOk();
})->group('RF-073', 'RF-072', 'CUS-82');

it('rejects preview and signing for an item that is not pending', function (string $case) {
    $f = icFixture();
    TenantContext::run($f['tenant'], fn () => $case === 'descartado'
        ? DB::table('plan_items')->where('id', $f['item']->id)->update(['status' => 'descartado', 'discard_reason' => 'El paciente desistió'])
        : DB::table('treatment_plans')->where('id', $f['item']->treatment_plan_id)
            ->update(['status' => 'cancelado', 'cancel_reason' => 'Plan duplicado', 'cancelled_at' => now()]));
    $this->actingAsRole('dentist', $f['tenant']);

    $this->getJson("/api/v1/plan-items/{$f['item']->uuid}/informed-consents/preview")
        ->assertConflict()
        ->assertJsonPath('rule', 'RF-073');
    icSign($f['item'])->assertConflict()->assertJsonPath('rule', 'RF-073');
})->with([
    'ítem descartado' => ['descartado'],
    'plan cancelado' => ['cancelado'],
])->group('RF-073', 'CUS-83');

/**
 * Plan e ítem aceptados (como tras CUS-37) y atención abierta por el odontólogo del escenario, con
 * el consentimiento de datos del paciente para que la ruta del procedimiento lo admita.
 *
 * @param  array{tenant: Tenant, dentist: User, patient: Patient, item: PlanItem}  $f
 */
function icOpenAttentionForAcceptedItem(array $f): string
{
    TenantContext::run($f['tenant'], function () use ($f) {
        app(ConsentService::class)->grant($f['patient'], [
            'channel' => 'presencial', 'confirmation_document_number' => '45678912',
        ], User::factory()->for($f['tenant'])->create(['role' => 'receptionist']), '127.0.0.1');
        DB::table('treatment_plans')->where('id', $f['item']->treatment_plan_id)->update(['status' => 'aceptado']);
        DB::table('plan_items')->where('id', $f['item']->id)->update(['status' => 'aceptado']);
    });

    test()->actingWithToken($f['dentist']);

    return test()->postJson("/api/v1/patients/{$f['patient']->uuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->json('data.id');
}

function icPerform(PlanItem $item, string $attention): TestResponse
{
    return test()->postJson("/api/v1/plan-items/{$item->uuid}/performed-procedures", [
        'attention_id' => $attention, 'quantity' => 1,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
}

it('rejects performing a consent requiring procedure without a signed consent', function () {
    $f = icFixture();
    $attention = icOpenAttentionForAcceptedItem($f);

    icPerform($f['item'], $attention)->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-76')
        ->assertJsonPath('detail', 'Registre el consentimiento informado antes del procedimiento.');

    // RF-074: tras revocar el consentimiento, el registro del procedimiento también es 422.
    $revoked = icSign($f['item'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/informed-consents/{$revoked}/revoke", ['reason' => 'El paciente cambió de opinión'])->assertOk();
    icPerform($f['item'], $attention)->assertUnprocessable()->assertJsonPath('rule', 'RN-76');

    $signed = icSign($f['item'])->assertCreated()->json('data.id');
    $performed = icPerform($f['item'], $attention)->assertCreated()->assertJsonPath('data.informed_consent_id', $signed);

    TenantContext::run($f['tenant'], function () use ($signed, $performed) {
        $consent = InformedConsent::query()->where('uuid', $signed)->sole();
        expect($consent->status)->toBe('utilizado')
            ->and($consent->used_at)->not->toBeNull()
            ->and(DB::table('performed_procedures')->where('uuid', $performed->json('data.id'))->value('informed_consent_id'))->toBe($consent->id);
    });
})->group('T-045', 'RN-76', 'RF-127', 'RF-074');
