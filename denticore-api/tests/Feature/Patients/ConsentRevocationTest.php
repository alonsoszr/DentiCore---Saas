<?php

/*
 * Revocación de finalidades del consentimiento (TASK-099; CUS-18; SDD §5.10; RN-14, RF-068,
 * RF-069). T-041 y T-042.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ClinicalFixtures;
use Tests\Support\PortalFixtures;

/** Paciente con consentimiento vigente y las finalidades opcionales indicadas. */
function revocablePatient(Tenant $tenant, array $purposes = ['purpose_notifications' => true]): Patient
{
    return TenantContext::run($tenant, function () use ($tenant, $purposes) {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', 'email' => Str::random(8).'@example.test']);
        app(ConsentService::class)->grant($patient, [
            'channel' => 'presencial', 'confirmation_document_number' => $patient->document_number, ...$purposes,
        ], User::factory()->for($tenant)->create(['role' => 'receptionist']), '127.0.0.1');

        return $patient;
    });
}

function currentConsent(Tenant $tenant, Patient $patient): Consent
{
    return TenantContext::run($tenant, fn () => Consent::query()->where('patient_id', $patient->id)->where('status', 'vigente')->sole());
}

function revoke(Patient $patient, Consent $consent, array $body, string $prefix = '')
{
    return test()->postJson("/api/v1{$prefix}/patients/{$patient->uuid}/consents/{$consent->uuid}/revocations", $body);
}

it('stops reminders immediately after revoking the notifications purpose', function () {
    $this->travelTo(Carbon::parse('2026-11-02 09:00'));
    $tenant = Tenant::factory()->create();
    $patient = revocablePatient($tenant);
    Appointment::factory()->create([
        'tenant_id' => $tenant->id, 'patient_id' => $patient->id,
        'starts_at' => Carbon::parse('2026-11-03 08:45'), 'ends_at' => Carbon::parse('2026-11-03 09:15'),
    ]);
    $this->actingAsRole('receptionist', $tenant);

    revoke($patient, currentConsent($tenant, $patient), ['purposes' => ['notificaciones'], 'reason' => 'Ya no desea correos'])
        ->assertOk()
        ->assertJsonPath('data.status', 'vigente')
        ->assertJsonPath('data.revoked_purposes', ['notificaciones']);

    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(Notification::query()->where('tenant_id', $tenant->id)->where('event', 'cita_recordatorio')->count())->toBe(0);
})->group('T-041', 'RN-14', 'RF-068', 'CUS-18');

it('makes clinical data read only after revoking the care purpose', function () {
    $tenant = Tenant::factory()->create();
    ClinicalFixtures::cariesFinding();
    $this->actingAsRole('dentist', $tenant);
    $attention = ClinicalFixtures::openAttention($tenant);
    ClinicalFixtures::recordFinding($attention)->assertCreated();
    $patient = TenantContext::run($tenant, fn () => $attention->patient()->firstOrFail());
    $consent = currentConsent($tenant, $patient);

    $this->actingAsRole('receptionist', $tenant);
    revoke($patient, $consent, ['purposes' => ['atencion']])
        ->assertOk()
        ->assertJsonPath('data.status', 'revocado');

    $this->actingAsRole('dentist', $tenant);
    ClinicalFixtures::recordFinding($attention, ['tooth' => 46])->assertUnprocessable();

    // Los datos clínicos existentes se conservan (RN-14, RN-68).
    expect(TenantContext::run($tenant, fn () => DB::table('odontogram_entries')->where('patient_id', $patient->id)->count()))->toBeGreaterThan(0)
        ->and(TenantContext::run($tenant, fn () => $consent->fresh()?->revoked_at))->not->toBeNull();
})->group('T-042', 'RN-14', 'RF-069', 'CUS-18');

it('records each revoked purpose with its channel and audits the revocation', function () {
    $tenant = Tenant::factory()->create();
    $patient = revocablePatient($tenant, ['purpose_notifications' => true, 'purpose_surveys' => true]);
    $consent = currentConsent($tenant, $patient);
    $receptionist = $this->actingAsRole('receptionist', $tenant);

    revoke($patient, $consent, ['purposes' => ['notificaciones', 'encuestas'], 'reason' => 'Pedido del titular'])
        ->assertOk()
        ->assertJsonPath('data.revoked_purposes', ['encuestas', 'notificaciones']);

    $rows = TenantContext::run($tenant, fn () => DB::table('consent_purpose_revocations')->where('consent_id', $consent->id)->orderBy('purpose')->get());
    expect($rows->pluck('purpose')->all())->toBe(['encuestas', 'notificaciones'])
        ->and($rows->pluck('channel')->unique()->all())->toBe(['presencial'])
        ->and($rows->pluck('revoked_by_user_id')->unique()->all())->toBe([$receptionist->id])
        ->and($rows->first()->reason)->toBe('Pedido del titular');

    $audit = TenantContext::run($tenant, fn () => AuditLog::query()->where('action', 'consent.purpose_revoked')->sole());
    expect($audit->resource_uuid)->toBe($consent->uuid)
        ->and($audit->patient_uuid)->toBe($patient->uuid)
        ->and($audit->metadata)->toBe(['purposes' => 'encuestas,notificaciones'])
        ->and(json_encode($audit->toArray()))->not->toContain('Pedido del titular');
})->group('RN-14', 'RF-068', 'CUS-18', 'RN-67');

it('rejects purposes that were not granted, were already revoked or do not exist', function (array $purposes, string $error) {
    $tenant = Tenant::factory()->create();
    $patient = revocablePatient($tenant);
    $consent = currentConsent($tenant, $patient);
    $this->actingAsRole('receptionist', $tenant);
    revoke($patient, $consent, ['purposes' => ['notificaciones']])->assertOk();

    revoke($patient, $consent, ['purposes' => $purposes])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'no otorgada' => [['encuestas'], 'purposes'],
    'ya revocada' => [['notificaciones'], 'purposes'],
    'desconocida' => [['marketing'], 'purposes.0'],
    'vacía' => [[], 'purposes'],
])->group('RF-068', 'CUS-18');

it('answers 409 for a consent that is no longer current and 404 for the consent of another patient', function () {
    $tenant = Tenant::factory()->create();
    $patient = revocablePatient($tenant);
    $previous = currentConsent($tenant, $patient);
    $other = revocablePatient($tenant);
    $this->actingAsRole('receptionist', $tenant);
    TenantContext::run($tenant, fn () => app(ConsentService::class)->grant($patient, [
        'channel' => 'presencial', 'confirmation_document_number' => $patient->document_number,
    ], User::factory()->for($tenant)->create(['role' => 'receptionist']), '127.0.0.1'));

    revoke($patient, $previous, ['purposes' => ['notificaciones']])
        ->assertConflict()
        ->assertJsonPath('rule', 'RN-14');
    revoke($patient, currentConsent($tenant, $other), ['purposes' => ['notificaciones']])->assertNotFound();
})->group('RN-14', 'CUS-18');

it('lets the adult holder revoke a purpose from the portal', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant, withConsent: false);
    TenantContext::run($tenant, fn () => app(ConsentService::class)->grant($patient, [
        'channel' => 'presencial', 'confirmation_document_number' => $patient->document_number, 'purpose_notifications' => true,
    ], User::factory()->for($tenant)->create(['role' => 'receptionist']), '127.0.0.1'));
    $this->actingWithToken($user);

    revoke($patient, currentConsent($tenant, $patient), ['purposes' => ['notificaciones']], '/portal')
        ->assertOk()
        ->assertJsonPath('data.revoked_purposes', ['notificaciones']);

    $row = TenantContext::run($tenant, fn () => DB::table('consent_purpose_revocations')->sole());
    expect($row->channel)->toBe('portal')
        ->and($row->revoked_by_user_id)->toBe($user->id);
})->group('RF-068', 'CUS-18');

it('forbids a minor holder from revoking from the portal', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant, ['birth_date' => now()->subYears(15)->toDateString()], withConsent: false);
    $representative = PortalFixtures::representative($tenant, $patient);
    TenantContext::run($tenant, fn () => app(ConsentService::class)->grant($patient, [
        'channel' => 'portal', 'purpose_notifications' => true,
    ], $representative, '127.0.0.1'));
    $this->actingWithToken($user);

    revoke($patient, currentConsent($tenant, $patient), ['purposes' => ['notificaciones']], '/portal')->assertForbidden();
})->group('RN-12', 'CUS-18');
