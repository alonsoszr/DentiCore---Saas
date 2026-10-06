<?php

/*
 * Consulta de la historia clínica y del odontograma (TASK-050; SDD §5.3; CUS-21, CUS-24; RF-064,
 * RF-076, RF-077, RF-079, RF-081, RF-092, RN-24, RN-67).
 */

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ClinicalFixtures;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/**
 * Paciente con una primera atención abierta por el odontólogo autenticado.
 *
 * @return array{tenant: Tenant, attention: Attention, patient: string}
 */
function recordWithAttention(): array
{
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    test()->actingAsRole('dentist', $tenant, ['name' => 'Dra. Pérez', 'cop_number' => '12345']);
    $attention = ClinicalFixtures::openAttention($tenant);

    return ['tenant' => $tenant, 'attention' => $attention, 'patient' => TenantContext::run($tenant, fn () => $attention->patient->uuid)];
}

function closeWithNote(Attention $attention): void
{
    test()->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Control'])->assertOk();
    test()->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertCreated();
    test()->postJson("/api/v1/attentions/{$attention->uuid}/close", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
}

it('shows the clinical record with identification, allergies, consent and attentions', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'patient' => $patient] = recordWithAttention();
    TenantContext::run($tenant, fn () => $attention->patient->forceFill(['medical_history' => [
        'alergias' => ['Penicilina'], 'enfermedades' => [], 'medicamentos' => [], 'observaciones' => null,
    ]])->save());

    $this->getJson("/api/v1/patients/{$patient}/clinical-record")->assertOk()
        ->assertJsonPath('data.patient.id', $patient)
        ->assertJsonPath('data.patient.allergies', ['Penicilina'])
        ->assertJsonPath('data.patient.has_current_consent', true)
        ->assertJsonPath('data.attentions.0.id', $attention->uuid)
        ->assertJsonPath('data.attentions.0.dentist.cop', '12345')
        ->assertJsonPath('data.initial_odontogram.status', 'abierto');

    TenantContext::run($tenant, fn () => expect(AuditLog::query()->where('action', 'clinical_record.viewed')->where('patient_uuid', $patient)->count())->toBe(1));
})->group('CUS-21', 'RF-076', 'RF-064', 'RN-67');

it('applies the latest entry of each tooth, surface and finding and replays past dates', function () {
    ['attention' => $attention, 'patient' => $patient] = recordWithAttention();

    $active = ClinicalFixtures::recordFinding($attention, ['tooth' => 16, 'surfaces' => ['O']])->json('data.id');
    $this->travelTo(Carbon::parse('2026-10-06 10:30', 'America/Lima'));
    $arrested = ClinicalFixtures::recordFinding($attention, ['tooth' => 16, 'surfaces' => ['O'], 'state_code' => 'DETENIDA'])->json('data.id');
    $other = ClinicalFixtures::recordFinding($attention, ['tooth' => 16, 'surfaces' => ['M']])->json('data.id');

    $this->getJson("/api/v1/patients/{$patient}/odontogram")->assertOk()
        ->assertJsonPath('data.entries.*.id', [$arrested, $other])
        ->assertJsonPath('data.default_dentition', 'permanente');

    // RF-080: el estado a una fecha pasada solo considera lo registrado hasta entonces.
    $this->getJson("/api/v1/patients/{$patient}/odontogram?at=".urlencode('2026-10-06T10:15:00-05:00'))->assertOk()
        ->assertJsonPath('data.entries.*.id', [$active])
        ->assertJsonPath('data.at', '2026-10-06T15:15:00.000000Z');
    $this->getJson("/api/v1/patients/{$patient}/odontogram?at=no-es-fecha")->assertUnprocessable()->assertJsonValidationErrors('at');
})->group('RN-24', 'RF-077', 'RF-080');

it('keeps the initial odontogram apart from the evolution', function () {
    ['tenant' => $tenant, 'attention' => $first, 'patient' => $patient] = recordWithAttention();
    $initial = ClinicalFixtures::recordFinding($first, ['tooth' => 16, 'surfaces' => ['O']])->json('data.id');
    closeWithNote($first);

    $second = ClinicalFixtures::attention($tenant, $this->postJson("/api/v1/patients/{$patient}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()])->json('data.id'));
    $evolution = ClinicalFixtures::recordFinding($second, ['tooth' => 16, 'surfaces' => ['O'], 'state_code' => 'DETENIDA'])->json('data.id');

    $this->getJson("/api/v1/patients/{$patient}/odontogram/initial")->assertOk()
        ->assertJsonPath('data.status', 'cerrado')
        ->assertJsonPath('data.closed_by', 'cierre_atencion')
        ->assertJsonPath('data.entries.*.id', [$initial]);
    $this->getJson("/api/v1/patients/{$patient}/odontogram")->assertOk()->assertJsonPath('data.entries.*.id', [$evolution]);
})->group('RF-079', 'RN-20');

it('returns no initial odontogram before the first attention', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $patient = ClinicalFixtures::patient($tenant);

    $this->getJson("/api/v1/patients/{$patient->uuid}/odontogram/initial")->assertOk()->assertJsonPath('data', null);
    $this->getJson("/api/v1/patients/{$patient->uuid}/odontogram")->assertOk()->assertJsonPath('data.entries', []);
})->group('RF-079');

it('lists the history of a tooth in chronological order with corrections flagged', function () {
    ['attention' => $attention, 'patient' => $patient] = recordWithAttention();
    $original = ClinicalFixtures::recordFinding($attention, ['tooth' => 36, 'surfaces' => ['O']])->json('data.id');
    ClinicalFixtures::recordFinding($attention, ['tooth' => 46, 'surfaces' => ['O']]);
    $this->travelTo(Carbon::parse('2026-10-06 10:20', 'America/Lima'));
    $correction = $this->postJson("/api/v1/odontogram-entries/{$original}/corrections", [
        'kind' => 'reemplazo', 'reason' => 'El estado correcto es detenida',
        'tooth' => 36, 'surfaces' => ['O'], 'finding_code' => 'PRUEBA_CARIES', 'state_code' => 'DETENIDA',
    ], ['Idempotency-Key' => (string) Str::uuid()])->json('data.id');

    $this->getJson("/api/v1/patients/{$patient}/teeth/36/history")->assertOk()
        ->assertJsonPath('data.*.id', [$original, $correction])
        ->assertJsonPath('data.0.corrected_by_id', $correction)
        ->assertJsonPath('data.0.state.code', 'ACTIVA')
        ->assertJsonPath('data.1.corrected_by_id', null)
        ->assertJsonPath('data.1.corrects_entry_id', $original)
        ->assertJsonPath('data.1.author.cop', '12345');
    $this->getJson("/api/v1/patients/{$patient}/teeth/19/history")->assertUnprocessable()->assertJsonValidationErrors('tooth');
})->group('CUS-24', 'RF-081', 'CA-23.1');

it('gives reception a read-only odontogram without clinical notes and denies the platform', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'patient' => $patient] = recordWithAttention();
    ClinicalFixtures::recordFinding($attention, ['tooth' => 16, 'surfaces' => ['O'], 'note' => 'Lesión cavitada'])->assertCreated();

    $this->actingAsRole('receptionist', $tenant);
    $this->getJson("/api/v1/patients/{$patient}/odontogram")->assertOk()
        ->assertJsonPath('data.entries.0.tooth', 16)
        ->assertJsonMissingPath('data.entries.0.note');
    $this->getJson("/api/v1/patients/{$patient}/teeth/16/history")->assertOk()->assertJsonMissingPath('data.0.note');

    $this->actingAsRole('super_admin');
    $this->getJson("/api/v1/patients/{$patient}/odontogram")->assertForbidden();

    // Cada lectura de la HC queda en la bitácora (RN-67).
    TenantContext::run($tenant, fn () => expect(AuditLog::query()->where('action', 'clinical_record.viewed')->count())->toBe(2));
})->group('CUS-21', 'CUS-24', 'RN-67', 'RNF-153');
