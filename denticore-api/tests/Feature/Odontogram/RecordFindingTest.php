<?php

/*
 * Registrar hallazgos en el odontograma (TASK-049; SDD §5.3; CUS-22; RF-077, RF-078, RF-087 a
 * RF-091, RN-10, RN-16 a RN-22, RN-25, RN-75).
 */

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Odontogram\Models\OdontogramEntry;
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

it('appends a new entry leaving previous entries identical', function () {
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['name' => 'Dra. Pérez', 'cop_number' => '12345']);
    $attention = ClinicalFixtures::openAttention($tenant);

    foreach ([16, 26, 46] as $tooth) {
        ClinicalFixtures::recordFinding($attention, ['tooth' => $tooth])->assertCreated();
    }
    $before = TenantContext::run($tenant, fn () => OdontogramEntry::query()->orderBy('id')->get()->map->getAttributes()->all());

    $this->travelTo(Carbon::parse('2026-10-06 10:14:22', 'America/Lima'));
    ClinicalFixtures::recordFinding($attention)->assertCreated()
        ->assertJsonPath('data.entry_type', 'inicial')
        ->assertJsonPath('data.tooth', 36)
        ->assertJsonPath('data.surfaces', ['O', 'M'])
        ->assertJsonPath('data.finding.code', 'PRUEBA_CARIES')
        ->assertJsonPath('data.state.code', 'ACTIVA')
        ->assertJsonPath('data.state.acronym', 'CA')
        ->assertJsonPath('data.color', 'rojo')
        ->assertJsonPath('data.origin', 'manual')
        ->assertJsonPath('data.note', 'Lesión cavitada')
        ->assertJsonPath('data.author.name', 'Dra. Pérez')
        ->assertJsonPath('data.author.cop', '12345')
        ->assertJsonPath('data.recorded_at', '2026-10-06T15:14:22.000000Z');

    TenantContext::run($tenant, function () use ($before) {
        $after = OdontogramEntry::query()->orderBy('id')->get()->map->getAttributes()->all();

        expect($after)->toHaveCount(4)
            ->and(array_slice($after, 0, 3))->toBe($before)
            ->and(AuditLog::query()->where('action', 'odontogram.entry_added')->count())->toBe(4);
    });
})->group('T-049', 'CA-22.1', 'RN-22', 'RF-087');

it('records evolution entries after the initial odontogram is closed', function () {
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $first = ClinicalFixtures::openAttention($tenant);
    ClinicalFixtures::recordFinding($first)->assertCreated()->assertJsonPath('data.entry_type', 'inicial');

    TenantContext::run($tenant, fn () => expect(OdontogramEntry::query()->sole()->initial_odontogram_id)->not->toBeNull());

    $this->putJson("/api/v1/attentions/{$first->uuid}/note", ['chief_complaint' => 'Control'])->assertOk();
    $this->postJson("/api/v1/attentions/{$first->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertCreated();
    $this->postJson("/api/v1/attentions/{$first->uuid}/close", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    $patientUuid = TenantContext::run($tenant, fn () => $first->patient->uuid);
    $response = $this->postJson("/api/v1/patients/{$patientUuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()]);
    $second = ClinicalFixtures::attention($tenant, $response->json('data.id'));

    ClinicalFixtures::recordFinding($second, ['state_code' => 'DETENIDA'])->assertCreated()
        ->assertJsonPath('data.entry_type', 'evolucion')
        ->assertJsonPath('data.color', 'azul');
    // La atención cerrada ya no admite hallazgos (SRS §11.5 FE-2).
    ClinicalFixtures::recordFinding($first)->assertConflict()
        ->assertJsonPath('rule', 'RF-087')
        ->assertJsonPath('detail', 'La atención está cerrada; abra una nueva atención.');
})->group('T-050', 'CA-22.2', 'RN-20', 'RN-21');

it('rejects tooth 19 and an occlusal surface on tooth 11', function () {
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = ClinicalFixtures::openAttention($tenant);

    ClinicalFixtures::recordFinding($attention, ['tooth' => 19])->assertUnprocessable()
        ->assertJsonPath('errors.tooth.0', 'La pieza 19 no existe en el Sistema Dígito Dos.');
    ClinicalFixtures::recordFinding($attention, ['tooth' => 11, 'surfaces' => ['O']])->assertUnprocessable()
        ->assertJsonPath('errors.surfaces.0', 'La superficie oclusal no aplica a incisivos.');
    ClinicalFixtures::recordFinding($attention, ['state_code' => 'OTRO'])->assertUnprocessable()->assertJsonValidationErrors('state_code');

    TenantContext::run($tenant, fn () => expect(OdontogramEntry::query()->count())->toBe(0));
})->group('T-051', 'CA-22.3', 'RN-16', 'RN-18', 'RF-088');

it('forbids a receptionist from recording findings', function () {
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = ClinicalFixtures::openAttention($tenant);

    $this->actingAsRole('receptionist', $tenant);
    ClinicalFixtures::recordFinding($attention)->assertForbidden();

    // Otro odontólogo tampoco registra en una atención que no está a su cargo (RN-19).
    $this->actingAsRole('dentist', $tenant);
    ClinicalFixtures::recordFinding($attention)->assertForbidden();
})->group('T-052', 'CA-22.4', 'RN-19');

it('rejects a finding for a patient without current consent', function () {
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = ClinicalFixtures::openAttention($tenant, withConsent: false);

    ClinicalFixtures::recordFinding($attention)->assertUnprocessable()->assertJsonPath('rule', 'RN-10');
})->group('T-054', 'CA-22.6', 'RN-10');

it('rejects procedure codes on the odontogram endpoint', function () {
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = ClinicalFixtures::openAttention($tenant);

    // Un código que no es del catálogo NTS 188 (p. ej. un procedimiento) se rechaza.
    ClinicalFixtures::recordFinding($attention, ['finding_code' => 'RESTAURACION_RESINA'])->assertUnprocessable()
        ->assertJsonPath('errors.finding_code.0', 'El hallazgo no pertenece al catálogo NTS 188 vigente.');
    // Un hallazgo retirado del catálogo tampoco (RF-078).
    FindingCatalog::factory()->has(FindingState::factory(), 'states')->create(['code' => 'PRUEBA_RETIRADO', 'is_active' => false]);
    ClinicalFixtures::recordFinding($attention, ['finding_code' => 'PRUEBA_RETIRADO'])->assertUnprocessable()->assertJsonValidationErrors('finding_code');
})->group('T-057', 'RN-25', 'RF-091', 'RF-078');

it('rejects clinical data from a dentist without COP', function () {
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $dentist = $this->actingAsRole('dentist', $tenant);
    $attention = ClinicalFixtures::openAttention($tenant);

    // La BD exige el COP a todo odontólogo; el middleware `cop` protege la API si faltara.
    $dentist->cop_number = '';
    $this->actingWithToken($dentist);

    ClinicalFixtures::recordFinding($attention)->assertUnprocessable()->assertJsonPath('rule', 'RN-75');
    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Dolor'])
        ->assertUnprocessable()->assertJsonPath('rule', 'RN-75');
})->group('T-058', 'RN-75', 'RF-090');

it('records a span finding with its end tooth', function () {
    FindingCatalog::factory()
        ->has(FindingState::factory()->state(['code' => 'BUEN_ESTADO', 'color' => 'azul']), 'states')
        ->create(['code' => 'PRUEBA_PROTESIS', 'level' => 'tramo']);
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = ClinicalFixtures::openAttention($tenant);

    ClinicalFixtures::recordFinding($attention, ['tooth' => 13, 'tooth_end' => 23, 'surfaces' => [], 'finding_code' => 'PRUEBA_PROTESIS', 'state_code' => 'BUEN_ESTADO'])
        ->assertCreated()->assertJsonPath('data.tooth_end', 23);
    ClinicalFixtures::recordFinding($attention, ['tooth' => 13, 'tooth_end' => 43, 'surfaces' => [], 'finding_code' => 'PRUEBA_PROTESIS', 'state_code' => 'BUEN_ESTADO'])
        ->assertUnprocessable()->assertJsonValidationErrors('tooth_end');
})->group('RN-17', 'RF-088');

it('lists the active NTS 188 catalog with its states', function () {
    ClinicalFixtures::cariesFinding();
    FindingCatalog::factory()->create(['code' => 'PRUEBA_RETIRADO', 'is_active' => false]);
    $this->actingAsRole('receptionist', Tenant::factory()->create());

    $findings = collect($this->getJson('/api/v1/finding-catalog')->assertOk()->json('data'))->keyBy('code');

    // Los 38 hallazgos de la NTS 188 (TASK-043b) y el sintético; nunca el retirado (RF-078).
    expect($findings)->toHaveCount(39)
        ->and($findings->has('PRUEBA_RETIRADO'))->toBeFalse()
        ->and(collect($findings['PRUEBA_CARIES']['states'])->pluck('code')->all())->toEqualCanonicalizing(['ACTIVA', 'DETENIDA'])
        ->and($findings['CARIES']['level'])->toBe('superficie')
        ->and(collect($findings['CARIES']['states'])->pluck('acronym')->all())->toBe(['MB', 'CE', 'CD', 'CDP']);
})->group('RF-077', 'RF-078', 'CUS-22');
