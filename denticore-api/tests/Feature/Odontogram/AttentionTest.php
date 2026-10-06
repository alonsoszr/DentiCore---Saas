<?php

/*
 * Abrir, cerrar y cerrar automáticamente la atención (TASK-047; SDD §5.2; CUS-25, CUS-26,
 * CUS-27; RF-082, RF-094, RF-096, RN-10, RN-20, RN-68, RN-75, RN-77, RN-78).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClinicalFixtures;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/** @param  array<string, mixed>  $attributes */
function attentionPatient(Tenant $tenant, bool $withConsent = true, array $attributes = []): Patient
{
    return ClinicalFixtures::patient($tenant, $withConsent, $attributes);
}

function openAttention(Patient $patient): TestResponse
{
    return test()->postJson("/api/v1/patients/{$patient->uuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()]);
}

function closeAttention(string $attentionUuid): TestResponse
{
    return test()->postJson("/api/v1/attentions/{$attentionUuid}/close", [], ['Idempotency-Key' => (string) Str::uuid()]);
}

/** Nota con motivo de consulta y un diagnóstico CIE-10 (RN-77); la API de la nota llega en TASK-048. */
function completeNote(Attention $attention, bool $withChiefComplaint = true, bool $withDiagnosis = true): void
{
    TenantContext::run($attention->tenant_id, function () use ($attention, $withChiefComplaint, $withDiagnosis) {
        DB::table('clinical_notes')->insert([
            'tenant_id' => $attention->tenant_id, 'attention_id' => $attention->id,
            'chief_complaint' => $withChiefComplaint ? 'Dolor al masticar' : '   ', 'status' => 'borrador',
        ]);
        if ($withDiagnosis) {
            DB::table('attention_diagnoses')->insert([
                'tenant_id' => $attention->tenant_id, 'attention_id' => $attention->id, 'cie10_code' => 'K02.1',
                'type' => 'definitivo', 'origin' => 'nota', 'created_by' => $attention->dentist_id,
            ]);
        }
    });
}

function attentionByUuid(Tenant $tenant, string $uuid): Attention
{
    return ClinicalFixtures::attention($tenant, $uuid);
}

it('opens the first attention with the initial odontogram and reactivates a passive record', function () {
    $tenant = Tenant::factory()->create();
    $dentist = $this->actingAsRole('dentist', $tenant, ['name' => 'Dra. Pérez', 'cop_number' => '12345']);
    $patient = attentionPatient($tenant, attributes: ['archive_status' => 'pasivo']);

    $response = openAttention($patient)->assertCreated()
        ->assertJsonPath('data.status', 'abierta')
        ->assertJsonPath('data.is_first_attention', true)
        ->assertJsonPath('data.patient_id', $patient->uuid)
        ->assertJsonPath('data.dentist.name', 'Dra. Pérez')
        ->assertJsonPath('data.dentist.cop', '12345')
        ->assertJsonPath('consent_warning', null);

    $attention = attentionByUuid($tenant, $response->json('data.id'));

    TenantContext::run($tenant, function () use ($attention, $patient, $dentist) {
        expect($attention->dentist_id)->toBe($dentist->id)
            ->and(InitialOdontogram::query()->where('patient_id', $patient->id)->first())
            ->attention_id->toBe($attention->id)
            ->status->toBe('abierto')
            ->and($patient->fresh()->archive_status)->toBe('activo')
            ->and(AuditLog::query()->where('action', 'attention.opened')->where('resource_uuid', $attention->uuid)->exists())->toBeTrue();
    });
})->group('CUS-25', 'RF-082', 'RN-20', 'RN-68');

it('opens an attention without consent but warns that clinical records are blocked', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $withoutConsent = attentionPatient($tenant, withConsent: false);
    $outdated = attentionPatient($tenant);
    DB::table('platform_settings')->where('key', 'consent.current_version')->update(['value' => '2']);
    // ConsentGate lee la versión una vez por solicitud (scoped); la siguiente solicitud la relee.
    app()->forgetScopedInstances();

    openAttention($withoutConsent)->assertCreated()
        ->assertJsonPath('consent_warning.rule', 'RN-10')
        ->assertJsonPath('consent_warning.detail', 'El paciente no tiene un consentimiento de datos vigente: no podrá registrar datos clínicos.');
    openAttention($outdated)->assertCreated()
        ->assertJsonPath('consent_warning.rule', 'RN-15')
        ->assertJsonPath('consent_warning.detail', 'El consentimiento del paciente usa una versión anterior de la plantilla: renuévelo.');
})->group('CUS-25', 'RN-10', 'RN-15', 'RF-151');

it('rejects a second open attention of the same dentist and patient', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $patient = attentionPatient($tenant);

    openAttention($patient)->assertCreated();
    openAttention($patient)->assertConflict()->assertJsonPath('rule', 'RF-082');

    // Otro odontólogo sí puede atender al mismo paciente; ya no es la primera atención (RN-20).
    $this->actingAsRole('dentist', $tenant);
    openAttention($patient)->assertCreated()->assertJsonPath('data.is_first_attention', false);
    TenantContext::run($tenant, fn () => expect(InitialOdontogram::query()->where('patient_id', $patient->id)->count())->toBe(1));
})->group('CUS-25', 'RF-082', 'RN-20');

it('does not open an attention for a blocked or merged record', function (string $archiveStatus, string $rule) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $patient = attentionPatient($tenant, withConsent: false);
    TenantContext::run($tenant, function () use ($patient, $archiveStatus) {
        $target = $archiveStatus === 'fusionado' ? Patient::factory()->for($patient->tenant)->create()->id : null;
        DB::table('patients')->where('id', $patient->id)->update(['archive_status' => $archiveStatus, 'merged_into_patient_id' => $target]);
    });

    openAttention($patient)->assertUnprocessable()->assertJsonPath('rule', $rule);
})->with([
    'bloqueado' => ['bloqueado', 'RF-184'],
    'fusionado' => ['fusionado', 'RF-075'],
])->group('CUS-25', 'CA-64.3');

it('requires the COP number of the dentist for clinical routes', function () {
    Route::middleware(['api', 'auth:sanctum', 'cop'])->post('/api/v1/__cop-probe', fn () => response()->noContent());
    $tenant = Tenant::factory()->create();
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

    $this->actingWithToken($dentist)->postJson('/api/v1/__cop-probe')->assertNoContent();

    // La BD exige el COP a todo odontólogo (users_dentist_cop_check); el middleware es la
    // barrera de la API si el dato faltara.
    $dentist->cop_number = '';
    $this->actingWithToken($dentist)->postJson('/api/v1/__cop-probe')->assertUnprocessable()->assertJsonPath('rule', 'RN-75');
})->group('RN-75', 'RF-090');

it('rejects closing an attention without chief complaint or CIE-10 diagnosis', function (bool $withNote, bool $withChiefComplaint, bool $withDiagnosis) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = attentionByUuid($tenant, openAttention(attentionPatient($tenant))->json('data.id'));
    if ($withNote) {
        completeNote($attention, $withChiefComplaint, $withDiagnosis);
    }

    closeAttention($attention->uuid)->assertUnprocessable()->assertJsonPath('rule', 'RN-77');

    expect(attentionByUuid($tenant, $attention->uuid)->status)->toBe('abierta');
})->with([
    'sin nota' => [false, false, false],
    'sin motivo de consulta' => [true, false, true],
    'sin diagnóstico' => [true, true, false],
])->group('T-064', 'RN-77', 'RF-094');

it('closes the initial odontogram when the first attention closes or at 23:59 clinic time', function () {
    $tenant = Tenant::factory()->create();
    $dentist = $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    $patient = attentionPatient($tenant);
    $attention = attentionByUuid($tenant, openAttention($patient)->json('data.id'));
    completeNote($attention);

    $this->travelTo(Carbon::parse('2026-10-06 11:30', 'America/Lima'));
    closeAttention($attention->uuid)->assertOk()
        ->assertJsonPath('data.status', 'cerrada')
        ->assertJsonPath('data.signed_at', '2026-10-06T16:30:00.000000Z')
        ->assertJsonPath('data.signer.cop', '12345');

    TenantContext::run($tenant, function () use ($attention, $patient, $dentist) {
        $closed = $attention->fresh();
        $sealed = app(EvidenceSealer::class)->verify($closed->evidencePayload(), (string) $closed->evidence_hmac);

        expect($closed)
            ->signed_by->toBe($dentist->id)
            ->signer_cop->toBe('12345')
            ->closed_by_system->toBeFalse()
            ->and($sealed)->toBeTrue()
            ->and(DB::table('clinical_notes')->where('attention_id', $attention->id)->value('status'))->toBe('firmada')
            ->and(InitialOdontogram::query()->where('patient_id', $patient->id)->first())
            ->status->toBe('cerrado')
            ->closed_by->toBe('cierre_atencion')
            ->and($patient->fresh()->last_attention_at->toIso8601ZuluString())->toBe('2026-10-06T16:30:00Z')
            ->and(AuditLog::query()->where('action', 'attention.closed')->where('resource_uuid', $attention->uuid)->exists())->toBeTrue();
    });

    // La 2.ª vez la atención ya no está abierta.
    closeAttention($attention->uuid)->assertConflict()->assertJsonPath('rule', 'RF-094');

    // Primera atención de otro paciente que nadie cierra: a las 23:59 de la clínica.
    $pending = attentionByUuid($tenant, openAttention(attentionPatient($tenant))->json('data.id'));
    $this->travelTo(Carbon::parse('2026-10-06 23:55', 'America/Lima'));
    $this->artisan('attentions:auto-close')->assertSuccessful();
    expect(attentionByUuid($tenant, $pending->uuid)->status)->toBe('abierta');

    $this->travelTo(Carbon::parse('2026-10-06 23:59', 'America/Lima'));
    $this->artisan('attentions:auto-close')->assertSuccessful();

    TenantContext::run($tenant, fn () => expect(InitialOdontogram::query()->where('attention_id', $pending->id)->first())
        ->status->toBe('cerrado')
        ->closed_by->toBe('cierre_automatico')
        ->closed_at->toIso8601ZuluString()->toBe('2026-10-07T04:59:00Z'));
})->group('T-063', 'RN-20', 'RF-096', 'RNF-020', 'RF-094');

it('auto closes at 23:59 of each clinic and leaves incomplete attentions for an addendum', function () {
    $lima = Tenant::factory()->create();
    $mexico = Tenant::factory()->create(['timezone' => 'America/Mexico_City']);
    $complete = Attention::factory()->create(['tenant_id' => $lima->id, 'opened_at' => now()]);
    completeNote($complete);
    $incomplete = Attention::factory()->create(['tenant_id' => $lima->id, 'opened_at' => now()]);
    $yesterday = Attention::factory()->create(['tenant_id' => $lima->id, 'opened_at' => now()->subDay()]);
    $elsewhere = Attention::factory()->create(['tenant_id' => $mexico->id, 'opened_at' => now()]);

    // 23:59 en Lima son las 22:59 en Ciudad de México.
    $this->travelTo(Carbon::parse('2026-10-06 23:59', 'America/Lima'));
    $this->artisan('attentions:auto-close')->assertSuccessful();
    // Repetirlo no duplica efectos.
    $this->artisan('attentions:auto-close')->assertSuccessful();

    $fresh = fn (Attention $attention) => TenantContext::run($attention->tenant_id, fn () => $attention->fresh());

    expect($fresh($complete))
        ->status->toBe('cerrada')
        ->closed_by_system->toBeTrue()
        ->signed_by->toBe($complete->dentist_id)
        ->evidence_hmac->toHaveLength(64)
        ->and($fresh($incomplete))
        ->status->toBe('cerrada_incompleta')
        ->closed_by_system->toBeTrue()
        ->signed_by->toBeNull()
        ->evidence_hmac->toBeNull()
        ->and($fresh($yesterday)->status)->toBe('cerrada_incompleta')
        ->and($fresh($elsewhere)->status)->toBe('abierta');

    $notifications = Notification::query()->where('event', 'atencion_cierre_incompleto')->get();
    expect($notifications)->toHaveCount(2)
        ->and($notifications->pluck('channel')->unique()->all())->toBe(['in_app'])
        ->and($notifications->pluck('recipient_user_id')->sort()->values()->all())
        ->toBe(collect([$incomplete->dentist_id, $yesterday->dentist_id])->sort()->values()->all())
        ->and($notifications->firstWhere('recipient_user_id', $incomplete->dentist_id)->payload['data'])
        ->toBe(['attention_uuid' => $incomplete->uuid]);

    TenantContext::run($lima, fn () => expect(AuditLog::query()->where('action', 'attention.auto_closed')->count())->toBe(3));

    $this->travelTo(Carbon::parse('2026-10-06 23:59', 'America/Mexico_City'));
    $this->artisan('attentions:auto-close')->assertSuccessful();
    expect($fresh($elsewhere)->status)->toBe('cerrada_incompleta');
})->group('CUS-27', 'RF-096', 'RN-77', 'RN-20');

it('lets only the dentist in charge close the attention and lists the attentions of the patient', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $patient = attentionPatient($tenant);
    $attention = attentionByUuid($tenant, openAttention($patient)->json('data.id'));
    completeNote($attention);

    $this->actingAsRole('dentist', $tenant);
    closeAttention($attention->uuid)->assertForbidden();

    $this->actingAsRole('clinic_admin', $tenant);
    $this->getJson("/api/v1/patients/{$patient->uuid}/attentions")->assertOk()
        ->assertJsonPath('data.0.id', $attention->uuid)
        ->assertJsonPath('data.0.status', 'abierta');
    $this->getJson("/api/v1/attentions/{$attention->uuid}")->assertOk()->assertJsonPath('data.id', $attention->uuid);
})->group('CUS-26', 'CUS-21', 'RF-094');

function addAddendum(string $attentionUuid, array $payload): TestResponse
{
    return test()->postJson("/api/v1/attentions/{$attentionUuid}/addenda", $payload);
}

it('leaves an auto closed attention incomplete and completes it with an addendum', function () {
    $tenant = Tenant::factory()->create();
    $dentist = $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    $attention = attentionByUuid($tenant, openAttention(attentionPatient($tenant))->json('data.id'));

    $this->travelTo(Carbon::parse('2026-10-06 23:59', 'America/Lima'));
    $this->artisan('attentions:auto-close')->assertSuccessful();
    expect(attentionByUuid($tenant, $attention->uuid)->status)->toBe('cerrada_incompleta');

    // Una adenda sin motivo ni diagnóstico no la completa.
    $this->travelTo(Carbon::parse('2026-10-07 09:00', 'America/Lima'));
    addAddendum($attention->uuid, ['text' => 'Paciente refiere mejoría.'])->assertCreated()
        ->assertJsonPath('data.author.cop', '12345')
        ->assertJsonPath('data.attention_status', 'cerrada_incompleta');

    addAddendum($attention->uuid, [
        'text' => 'Se completa la atención del día anterior.',
        'chief_complaint' => 'Dolor al masticar',
        'diagnoses' => [['cie10_code' => 'K02.1', 'type' => 'definitivo']],
    ])->assertCreated()
        ->assertJsonPath('data.attention_status', 'cerrada')
        ->assertJsonPath('data.diagnoses.0.code', 'K02.1')
        ->assertJsonPath('data.diagnoses.0.origin', 'adenda');

    TenantContext::run($tenant, function () use ($attention, $dentist) {
        $completed = $attention->fresh();

        expect($completed)
            ->status->toBe('cerrada')
            ->signed_by->toBe($dentist->id)
            ->signer_cop->toBe('12345')
            ->closed_at->toIso8601ZuluString()->toBe('2026-10-07T04:59:00Z')
            ->and(app(EvidenceSealer::class)->verify($completed->evidencePayload(), (string) $completed->evidence_hmac))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'addendum.added')->count())->toBe(2);
    });
})->group('T-065', 'RN-77', 'RF-096', 'RF-097');

it('rejects editing a signed note and keeps it unchanged after an addendum', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = attentionByUuid($tenant, openAttention(attentionPatient($tenant))->json('data.id'));
    $note = ['chief_complaint' => 'Dolor al masticar', 'intraoral_exam' => 'Lesión cavitada en 36'];

    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", $note)->assertOk();
    $this->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertCreated();
    closeAttention($attention->uuid)->assertOk();

    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Otro motivo'])
        ->assertConflict()->assertJsonPath('rule', 'RN-78');
    $this->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K05', 'type' => 'presuntivo'])
        ->assertConflict()->assertJsonPath('rule', 'RN-78');

    addAddendum($attention->uuid, [
        'text' => 'Control telefónico: sin dolor.',
        'diagnoses' => [['cie10_code' => 'K05', 'type' => 'presuntivo']],
    ])->assertCreated()->assertJsonPath('data.attention_status', 'cerrada');

    $this->getJson("/api/v1/attentions/{$attention->uuid}")->assertOk()
        ->assertJsonPath('data.note.chief_complaint', 'Dolor al masticar')
        ->assertJsonPath('data.note.intraoral_exam', 'Lesión cavitada en 36')
        ->assertJsonPath('data.note.status', 'firmada')
        ->assertJsonPath('data.diagnoses.*.code', ['K02.1', 'K05'])
        ->assertJsonPath('data.diagnoses.*.origin', ['nota', 'adenda'])
        ->assertJsonPath('data.addenda.0.text', 'Control telefónico: sin dolor.');
})->group('T-066', 'RN-78', 'RF-094', 'RF-097');
