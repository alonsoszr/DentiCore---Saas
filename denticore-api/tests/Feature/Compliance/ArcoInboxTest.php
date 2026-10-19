<?php

/*
 * Atención de solicitudes ARCO por el Oficial de Datos Personales (TASK-104; CUS-64; SDD §5.11;
 * RF-183, RN-69, DD-26). T-145.
 */

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use App\Support\Files\GeneratedDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArcoFixtures;
use Tests\Support\ClinicalFixtures;
use Tests\Support\PortalFixtures;

it('forbids the ARCO inbox to an admin who is not data officer', function () {
    $tenant = Tenant::factory()->create();
    $request = ArcoFixtures::request($tenant, ClinicalFixtures::patient($tenant));
    $this->actingAsRole('clinic_admin', $tenant, ['is_data_officer' => false]);

    // CA-64.4 (FE-1): sin la designación, ni la bandeja ni las acciones.
    $this->getJson('/api/v1/arco-requests')->assertForbidden();
    $this->getJson("/api/v1/arco-requests/{$request->uuid}")->assertForbidden();
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/start")->assertForbidden();

    ArcoFixtures::officer($tenant);
    $this->getJson('/api/v1/arco-requests')->assertOk()->assertJsonCount(1, 'data');
})->group('T-145', 'CA-64.4', 'CUS-64');

it('lists the inbox by deadline with the business days left', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $patient = ClinicalFixtures::patient($tenant, attributes: ['first_name' => 'Lucía']);
    // Recibida el lunes 2 → vence el lunes 16; recibida el miércoles 4 → vence el miércoles 18.
    $this->travelTo(Carbon::parse('2026-11-04 10:00', 'America/Lima'));
    $later = ArcoFixtures::request($tenant, $patient, 'oposicion');
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/Lima'));
    $sooner = ArcoFixtures::request($tenant, $patient);
    $this->travelTo(Carbon::parse('2026-11-12 09:00', 'America/Lima'));
    ArcoFixtures::officer($tenant);

    // Jueves 12: quedan 2 días hábiles hasta el lunes 16 (viernes 13 y lunes 16) y 4 hasta el miércoles 18.
    $this->getJson('/api/v1/arco-requests')
        ->assertOk()
        ->assertJsonPath('data.0.id', $sooner->uuid)
        ->assertJsonPath('data.0.remaining_business_days', 2)
        ->assertJsonPath('data.0.patient.name', 'Lucía '.$patient->last_name)
        ->assertJsonPath('data.1.id', $later->uuid)
        ->assertJsonPath('data.1.remaining_business_days', 4);

    $this->getJson('/api/v1/arco-requests?status=en_tramite')->assertOk()->assertJsonCount(0, 'data');
})->group('CUS-64', 'DD-26', 'RF-183');

it('starts a request once and records who handles it', function () {
    $tenant = Tenant::factory()->create();
    $request = ArcoFixtures::request($tenant, ClinicalFixtures::patient($tenant));
    $officer = ArcoFixtures::officer($tenant);

    $this->postJson("/api/v1/arco-requests/{$request->uuid}/start")
        ->assertOk()
        ->assertJsonPath('data.status', 'en_tramite');
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/start")->assertStatus(409);

    expect(TenantContext::run($tenant, fn () => $request->fresh()->handled_by))->toBe($officer->id);
})->group('CUS-64', 'RF-183');

it('answers an access request with the clinical record copy and notifies the holder', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/Lima'));
    [$user, $patient] = PortalFixtures::holder($tenant, ['email' => 'titular@correo.test']);
    $officer = ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, $patient);

    $response = $this->postJson("/api/v1/arco-requests/{$request->uuid}/resolve", [
        'status' => 'atendida',
        'response' => ArcoFixtures::response(),
    ])->assertOk()
        ->assertJsonPath('data.status', 'atendida')
        ->assertJsonPath('data.within_deadline', true)
        ->assertJsonPath('data.response', ArcoFixtures::response());

    TenantContext::run($tenant, function () use ($response, $patient, $user, $officer, $request) {
        // RN-69: el acceso entrega la copia de la HC (CUS-62) como documento de respuesta.
        $copy = GeneratedDocument::query()->where('uuid', $response->json('data.response_document_id'))->sole();
        expect($copy->kind)->toBe('copia_hc')
            ->and($copy->documentable_id)->toBe($patient->id);

        // RF-183: respuesta por correo y en el portal.
        expect(Notification::query()->where('event', 'respuesta_arco')->where('channel', 'correo')->sole()->recipient_patient_id)->toBe($patient->id)
            ->and(Notification::query()->where('event', 'respuesta_arco')->where('channel', 'in_app')->sole()->recipient_user_id)->toBe($user->id);

        $audit = AuditLog::query()->where('action', 'arco.resolved')->sole();
        expect($audit->resource_uuid)->toBe($request->uuid)
            ->and($audit->user_id)->toBe($officer->id)
            ->and($audit->metadata)->toBe(['status' => 'atendida', 'within_deadline' => true])
            ->and(json_encode($audit->toArray()))->not->toContain('Ley N° 29733');
    });
})->group('CUS-64', 'RF-183', 'RN-69', 'RN-70', 'RN-67');

it('requires the answer and the grounds to deny', function () {
    $tenant = Tenant::factory()->create();
    ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, ClinicalFixtures::patient($tenant));
    $resolve = "/api/v1/arco-requests/{$request->uuid}/resolve";

    // FE-2: denegar sin fundamento (≥ 20) es 422; la respuesta también tiene 20 a 5000 caracteres.
    $this->postJson($resolve, ['status' => 'denegada', 'response' => ArcoFixtures::response()])
        ->assertUnprocessable()->assertJsonValidationErrors('denial_reason');
    $this->postJson($resolve, ['status' => 'denegada', 'response' => ArcoFixtures::response(), 'denial_reason' => 'Muy corto'])
        ->assertUnprocessable()->assertJsonValidationErrors('denial_reason');
    $this->postJson($resolve, ['status' => 'atendida', 'response' => 'Listo.'])
        ->assertUnprocessable()->assertJsonValidationErrors('response');

    $this->postJson($resolve, [
        'status' => 'denegada',
        'response' => ArcoFixtures::response(),
        'denial_reason' => 'El solicitante no acreditó la representación legal vigente.',
    ])->assertOk()->assertJsonPath('data.status', 'denegada');

    // Una solicitud resuelta no se vuelve a resolver.
    $this->postJson($resolve, ['status' => 'atendida', 'response' => ArcoFixtures::response()])->assertStatus(409);
    expect(TenantContext::run($tenant, fn () => GeneratedDocument::query()->where('kind', 'copia_hc')->count()))->toBe(0);
})->group('CUS-64', 'RF-183');

it('marks an answer after the deadline as out of time', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/Lima'));
    ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, ClinicalFixtures::patient($tenant), 'portabilidad');

    // FE-3: se permite responder el martes 17, un día después del plazo.
    $this->travelTo(Carbon::parse('2026-11-17 09:00', 'America/Lima'));
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/resolve", ['status' => 'atendida', 'response' => ArcoFixtures::response()])
        ->assertOk()
        ->assertJsonPath('data.within_deadline', false);
})->group('CUS-64', 'RN-69', 'DD-26');

it('revokes the optional purposes of an accepted opposition', function () {
    $tenant = Tenant::factory()->create();
    $patient = ArcoFixtures::patientWithOptionalPurposes($tenant);
    ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, $patient, 'oposicion');
    $resolve = "/api/v1/arco-requests/{$request->uuid}/resolve";

    // La oposición no toca la finalidad de atención (a), que es necesaria (CUS-18).
    $this->postJson($resolve, ['status' => 'atendida', 'response' => ArcoFixtures::response(), 'purposes' => ['atencion']])
        ->assertUnprocessable()->assertJsonValidationErrors('purposes.0');
    $this->postJson($resolve, ['status' => 'atendida', 'response' => ArcoFixtures::response()])
        ->assertUnprocessable()->assertJsonValidationErrors('purposes');

    $this->postJson($resolve, ['status' => 'atendida', 'response' => ArcoFixtures::response(), 'purposes' => ['notificaciones']])
        ->assertOk();

    TenantContext::run($tenant, function () use ($patient, $request) {
        $revocation = Consent::query()->where('patient_id', $patient->id)->sole()->purposeRevocations()->sole();
        expect($revocation->purpose)->toBe('notificaciones')
            ->and($revocation->channel)->toBe('arco')
            ->and(DB::table('consent_purpose_revocations')->value('arco_request_id'))->toBe($request->id);
    });
})->group('CUS-64', 'RN-69', 'RN-14');

it('links an identity rectification to its request', function () {
    $tenant = Tenant::factory()->create();
    $patient = ClinicalFixtures::patient($tenant);
    ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, $patient, 'rectificacion');
    $access = ArcoFixtures::request($tenant, $patient);

    // CUS-15 «extend» CUS-64: la edición de la identificación queda vinculada a la solicitud.
    $this->patchJson("/api/v1/patients/{$patient->uuid}", ['last_name' => 'Quispe Huamán', 'arco_request_id' => $access->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('arco_request_id');
    $this->patchJson("/api/v1/patients/{$patient->uuid}", ['last_name' => 'Quispe Huamán', 'arco_request_id' => $request->uuid])
        ->assertOk();

    expect(TenantContext::run($tenant, fn () => DB::table('patient_identity_history')->where('patient_id', $patient->id)->value('arco_request_id')))
        ->toBe($request->id);
})->group('CUS-64', 'CUS-15', 'RN-69');

it('assigns a clinical rectification to a dentist of the clinic and keeps the correction', function () {
    $tenant = Tenant::factory()->create();
    [, $patient] = PortalFixtures::holder($tenant);
    $entry = PortalFixtures::recordedFinding($tenant, $patient);
    $dentist = TenantContext::run($tenant, fn () => User::query()->where('role', 'dentist')->sole());
    $correction = $this->postJson("/api/v1/odontogram-entries/{$entry}/corrections", [
        'kind' => 'anulacion', 'reason' => 'Se registró en la pieza equivocada.',
    ], ArcoFixtures::key())->assertCreated()->json('data.id');
    ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, $patient, 'rectificacion');
    $receptionist = User::factory()->for($tenant)->create(['role' => 'receptionist']);

    $this->postJson("/api/v1/arco-requests/{$request->uuid}/assign-correction", ['dentist_id' => $receptionist->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/assign-correction", ['dentist_id' => $dentist->uuid])
        ->assertOk()
        ->assertJsonPath('data.assigned_dentist_id', $dentist->uuid);

    $this->postJson("/api/v1/arco-requests/{$request->uuid}/resolve", [
        'status' => 'atendida', 'response' => ArcoFixtures::response(), 'correction_entry_id' => $entry,
    ])->assertUnprocessable()->assertJsonValidationErrors('correction_entry_id');
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/resolve", [
        'status' => 'atendida', 'response' => ArcoFixtures::response(), 'correction_entry_id' => $correction,
    ])->assertOk()->assertJsonPath('data.correction_entry_id', $correction);
})->group('CUS-64', 'CUS-23', 'RN-23', 'RN-69');

it('keeps assign-correction for clinical rectifications in progress', function () {
    $tenant = Tenant::factory()->create();
    ArcoFixtures::officer($tenant);
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $access = ArcoFixtures::started($tenant, ClinicalFixtures::patient($tenant));
    $received = ArcoFixtures::request($tenant, ClinicalFixtures::patient($tenant), 'rectificacion');

    $this->postJson("/api/v1/arco-requests/{$access->uuid}/assign-correction", ['dentist_id' => $dentist->uuid])->assertUnprocessable();
    $this->postJson("/api/v1/arco-requests/{$received->uuid}/assign-correction", ['dentist_id' => $dentist->uuid])->assertStatus(409);
    expect(TenantContext::run($tenant, fn () => ArcoRequest::query()->whereNotNull('assigned_dentist_id')->count()))->toBe(0);
})->group('CUS-64', 'RN-69');
