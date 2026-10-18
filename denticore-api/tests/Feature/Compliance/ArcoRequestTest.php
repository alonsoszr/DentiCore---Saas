<?php

/*
 * Registro de solicitudes ARCO (TASK-103; CUS-63; SDD §2.12, §5.11; RF-182, RN-69, RN-81, DD-26).
 */

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ClinicalFixtures;
use Tests\Support\PortalFixtures;

function arcoKey(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

/** Representante legal vigente del paciente, registrado como en la clínica (CUS-16). */
function arcoRepresentative(Tenant $tenant, Patient $patient): LegalRepresentative
{
    return TenantContext::run($tenant, fn () => app(LegalRepresentativeService::class)->register($patient, [
        'document_type' => 'dni',
        'document_number' => (string) fake()->unique()->numberBetween(40000000, 49999999),
        'first_name' => 'Rosa',
        'last_name' => 'Mamani Soto',
        'relationship' => 'madre',
        'phone' => '912345678',
        'valid_from' => '2020-01-01',
    ]));
}

function arcoPayload(Patient $patient, array $overrides = []): array
{
    return [
        'type' => 'acceso',
        'requested_by' => 'titular',
        'description' => 'Solicito una copia completa de mi historia clínica.',
        'confirmation_document_number' => $patient->document_number,
        ...$overrides,
    ];
}

it('registers an ARCO request at the clinic with the deadline of 10 business days and audits it', function () {
    Storage::fake('s3');
    // Lunes 2 de noviembre de 2026, 10:00 en Lima → vence el lunes 16 (CA-64.1).
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/Lima'));
    $tenant = Tenant::factory()->create();
    $patient = ClinicalFixtures::patient($tenant);
    $receptionist = $this->actingAsRole('receptionist', $tenant);

    $response = $this->postJson("/api/v1/patients/{$patient->uuid}/arco-requests", arcoPayload($patient, [
        'files' => [UploadedFile::fake()->create('dni.pdf', 120, 'application/pdf')],
    ]), arcoKey())
        ->assertCreated()
        ->assertJsonPath('data.type', 'acceso')
        ->assertJsonPath('data.status', 'recibida')
        ->assertJsonPath('data.channel', 'presencial')
        ->assertJsonPath('data.identity_verification', 'documento_presencial')
        ->assertJsonPath('data.due_date', '2026-11-16')
        ->assertJsonPath('data.files_count', 1);

    $request = TenantContext::run($tenant, fn () => ArcoRequest::query()->where('uuid', $response->json('data.id'))->sole());
    expect($request->patient_id)->toBe($patient->id)
        ->and(TenantContext::run($tenant, fn () => DB::table('arco_request_files')->where('arco_request_id', $request->id)->count()))->toBe(1);

    $audit = TenantContext::run($tenant, fn () => AuditLog::query()->where('action', 'arco.received')->sole());
    expect($audit->resource_uuid)->toBe($request->uuid)
        ->and($audit->patient_uuid)->toBe($patient->uuid)
        ->and($audit->user_id)->toBe($receptionist->id)
        ->and(json_encode($audit->toArray()))->not->toContain('copia completa');
})->group('RF-182', 'RN-69', 'DD-26', 'CA-64.1', 'CUS-63', 'RN-67');

it('lists the ARCO requests of the patient from the most recent', function () {
    $tenant = Tenant::factory()->create();
    $patient = ClinicalFixtures::patient($tenant);
    $this->actingAsRole('clinic_admin', $tenant);
    $this->travelTo(now()->subDay());
    $first = $this->postJson("/api/v1/patients/{$patient->uuid}/arco-requests", arcoPayload($patient), arcoKey())->json('data.id');
    $this->travelBack();
    $second = $this->postJson("/api/v1/patients/{$patient->uuid}/arco-requests", arcoPayload($patient, ['type' => 'oposicion']), arcoKey())->json('data.id');

    $this->getJson("/api/v1/patients/{$patient->uuid}/arco-requests")
        ->assertOk()
        ->assertJsonPath('data.*.id', [$second, $first]);
})->group('RF-182', 'CUS-63');

it('verifies the identity with the document of the holder or of the current representative', function () {
    $tenant = Tenant::factory()->create();
    $minor = PortalFixtures::minor($tenant);
    $representative = arcoRepresentative($tenant, $minor);
    $this->actingAsRole('receptionist', $tenant);
    $url = "/api/v1/patients/{$minor->uuid}/arco-requests";

    $this->postJson($url, arcoPayload($minor, ['confirmation_document_number' => '00000000']), arcoKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmation_document_number');
    $this->postJson($url, arcoPayload($minor, ['requested_by' => 'representante']), arcoKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('legal_representative_id');
    $this->postJson($url, arcoPayload($minor, [
        'requested_by' => 'representante',
        'legal_representative_id' => $representative->uuid,
        'confirmation_document_number' => $representative->document_number,
    ]), arcoKey())
        ->assertCreated()
        ->assertJsonPath('data.requested_by', 'representante')
        ->assertJsonPath('data.representative_id', $representative->uuid);
})->group('RF-182', 'RN-12', 'CUS-63');

it('rejects an unknown type, an empty description and a representative of another patient', function () {
    $tenant = Tenant::factory()->create();
    $patient = ClinicalFixtures::patient($tenant);
    $other = PortalFixtures::minor($tenant);
    $foreign = arcoRepresentative($tenant, $other);
    $this->actingAsRole('receptionist', $tenant);
    $url = "/api/v1/patients/{$patient->uuid}/arco-requests";

    $this->postJson($url, arcoPayload($patient, ['type' => 'borrado']), arcoKey())->assertJsonValidationErrors('type');
    $this->postJson($url, arcoPayload($patient, ['description' => '']), arcoKey())->assertJsonValidationErrors('description');
    $this->postJson($url, arcoPayload($patient, [
        'requested_by' => 'representante', 'legal_representative_id' => $foreign->uuid,
        'confirmation_document_number' => $foreign->document_number,
    ]), arcoKey())->assertJsonValidationErrors('legal_representative_id');

    expect(TenantContext::run($tenant, fn () => ArcoRequest::query()->count()))->toBe(0);
})->group('RF-182', 'CUS-63');

it('lets the holder register an ARCO request from the portal verified by the session', function () {
    $this->travelTo(Carbon::parse('2026-11-06 18:00', 'America/Lima'));
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    $this->actingWithToken($user);
    $url = "/api/v1/portal/patients/{$patient->uuid}/arco-requests";

    $this->postJson($url, ['type' => 'portabilidad', 'description' => 'Quiero mis datos en un formato estructurado.'], arcoKey())
        ->assertCreated()
        ->assertJsonPath('data.channel', 'portal')
        ->assertJsonPath('data.identity_verification', 'sesion_portal')
        ->assertJsonPath('data.requested_by', 'titular')
        // Recibida un viernes: vence el viernes de dos semanas después (DD-26).
        ->assertJsonPath('data.due_date', '2026-11-20');

    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
})->group('RF-182', 'RN-81', 'DD-26', 'CUS-63');

it('registers the request of a representative from the portal for the represented minor', function () {
    $tenant = Tenant::factory()->create();
    $minor = PortalFixtures::minor($tenant);
    $user = PortalFixtures::representative($tenant, $minor);
    $this->actingWithToken($user);

    $this->postJson("/api/v1/portal/patients/{$minor->uuid}/arco-requests", ['type' => 'oposicion', 'description' => 'No deseo recibir encuestas.'], arcoKey())
        ->assertCreated()
        ->assertJsonPath('data.requested_by', 'representante');

    $request = TenantContext::run($tenant, fn () => ArcoRequest::query()->sole());
    expect($request->legal_representative_id)->not->toBeNull();
})->group('RF-182', 'RN-12', 'CUS-63');
