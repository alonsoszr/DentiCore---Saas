<?php

/*
 * Correcciones del odontograma (TASK-049; SDD §5.3; CUS-23; RF-093, RN-22, RN-23).
 */

use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClinicalFixtures;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/** @param  array<string, mixed>  $payload */
function correctEntry(string $entryUuid, array $payload): TestResponse
{
    return test()->postJson("/api/v1/odontogram-entries/{$entryUuid}/corrections", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

/**
 * Hallazgo registrado por el odontólogo autenticado en una atención nueva.
 *
 * @return array{tenant: Tenant, entry: string}
 */
function recordedEntry(): array
{
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    test()->actingAsRole('dentist', $tenant, ['name' => 'Dra. Pérez', 'cop_number' => '12345']);
    $attention = ClinicalFixtures::openAttention($tenant);

    return ['tenant' => $tenant, 'entry' => ClinicalFixtures::recordFinding($attention)->assertCreated()->json('data.id')];
}

it('keeps the corrected entry with its original data flagged as corrected', function () {
    ['tenant' => $tenant, 'entry' => $original] = recordedEntry();
    $before = TenantContext::run($tenant, fn () => OdontogramEntry::query()->where('uuid', $original)->sole()->getAttributes());

    // Otro odontólogo de la clínica corrige (SRS §11.6 FA-3).
    $this->actingAsRole('dentist', $tenant, ['name' => 'Dr. Ramos', 'cop_number' => '67890']);
    correctEntry($original, [
        'kind' => 'reemplazo', 'reason' => 'Se registró en la pieza equivocada',
        'tooth' => 37, 'surfaces' => ['O'], 'finding_code' => 'PRUEBA_CARIES', 'state_code' => 'DETENIDA',
    ])->assertCreated()
        ->assertJsonPath('data.entry_type', 'correccion')
        ->assertJsonPath('data.correction_kind', 'reemplazo')
        ->assertJsonPath('data.correction_reason', 'Se registró en la pieza equivocada')
        ->assertJsonPath('data.corrects_entry_id', $original)
        ->assertJsonPath('data.tooth', 37)
        ->assertJsonPath('data.color', 'azul')
        ->assertJsonPath('data.author.name', 'Dr. Ramos');

    TenantContext::run($tenant, function () use ($original, $before) {
        expect(OdontogramEntry::query()->where('uuid', $original)->sole()->getAttributes())->toBe($before)
            ->and(OdontogramEntry::query()->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'odontogram.entry_corrected')->count())->toBe(1);
    });
})->group('T-059', 'CA-23.1', 'RN-23', 'RF-093');

it('annuls an entry without finding data', function () {
    ['tenant' => $tenant, 'entry' => $original] = recordedEntry();

    correctEntry($original, ['kind' => 'anulacion', 'reason' => 'El hallazgo no debió registrarse'])->assertCreated()
        ->assertJsonPath('data.correction_kind', 'anulacion')
        ->assertJsonPath('data.tooth', 36)
        ->assertJsonPath('data.surfaces', ['O', 'M'])
        ->assertJsonPath('data.finding', null)
        ->assertJsonPath('data.color', null);
})->group('RN-23', 'CA-23.2');

it('rejects a second correction of the same entry with 409', function () {
    ['entry' => $original] = recordedEntry();

    $correction = correctEntry($original, ['kind' => 'anulacion', 'reason' => 'El hallazgo no debió registrarse'])->assertCreated();
    correctEntry($original, ['kind' => 'anulacion', 'reason' => 'Segundo intento de anulación'])->assertConflict()
        ->assertJsonPath('rule', 'RN-23')
        ->assertJsonPath('detail', 'Esta entrada ya tiene una corrección; corrija la corrección.');

    // La corrección errónea se corrige a sí misma; la cadena se conserva (SRS §11.6 FA-1).
    correctEntry($correction->json('data.id'), [
        'kind' => 'reemplazo', 'reason' => 'La anulación fue un error',
        'tooth' => 36, 'surfaces' => ['O'], 'finding_code' => 'PRUEBA_CARIES', 'state_code' => 'ACTIVA',
    ])->assertCreated();
})->group('T-061', 'CA-23.3', 'RN-23');

it('rejects a correction reason shorter than 10 characters', function () {
    ['tenant' => $tenant, 'entry' => $original] = recordedEntry();

    correctEntry($original, ['kind' => 'anulacion', 'reason' => 'Corregida'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    correctEntry($original, ['kind' => 'anulacion', 'reason' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('reason');
    correctEntry($original, ['kind' => 'reemplazo', 'reason' => 'Pieza equivocada', 'tooth' => 19, 'surfaces' => ['O'], 'finding_code' => 'PRUEBA_CARIES', 'state_code' => 'ACTIVA'])
        ->assertUnprocessable()->assertJsonValidationErrors('tooth');
    correctEntry($original, ['kind' => 'otra', 'reason' => 'Pieza equivocada'])->assertUnprocessable()->assertJsonValidationErrors('kind');

    TenantContext::run($tenant, fn () => expect(OdontogramEntry::query()->count())->toBe(1));
})->group('T-062', 'CA-23.4', 'RN-23');

it('only lets dentists of the clinic correct entries', function () {
    ['tenant' => $tenant, 'entry' => $original] = recordedEntry();

    $this->actingAsRole('clinic_admin', $tenant);
    correctEntry($original, ['kind' => 'anulacion', 'reason' => 'El hallazgo no debió registrarse'])->assertForbidden();

    $this->actingAsRole('dentist');
    correctEntry($original, ['kind' => 'anulacion', 'reason' => 'El hallazgo no debió registrarse'])->assertNotFound();
})->group('CUS-23', 'RN-19', 'RF-003');

it('excludes an annulled entry from the current state', function () {
    ['tenant' => $tenant, 'entry' => $annulled] = recordedEntry();
    $attention = TenantContext::run($tenant, fn () => OdontogramEntry::query()->where('uuid', $annulled)->sole()->attention);
    $replaced = ClinicalFixtures::recordFinding($attention, ['tooth' => 16, 'surfaces' => ['O']])->json('data.id');
    $kept = ClinicalFixtures::recordFinding($attention, ['tooth' => 46, 'surfaces' => ['V']])->json('data.id');

    correctEntry($annulled, ['kind' => 'anulacion', 'reason' => 'El hallazgo no debió registrarse'])->assertCreated();
    $replacement = correctEntry($replaced, [
        'kind' => 'reemplazo', 'reason' => 'El estado correcto es detenida',
        'tooth' => 16, 'surfaces' => ['O'], 'finding_code' => 'PRUEBA_CARIES', 'state_code' => 'DETENIDA',
    ])->json('data.id');

    $patientUuid = TenantContext::run($tenant, fn () => $attention->patient->uuid);
    $this->getJson("/api/v1/patients/{$patientUuid}/odontogram")->assertOk()
        ->assertJsonPath('data.entries.*.id', [$replacement, $kept])
        ->assertJsonPath('data.entries.0.color', 'azul');
})->group('T-060', 'CA-23.2', 'RN-24');
