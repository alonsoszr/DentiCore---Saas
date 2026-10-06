<?php

/*
 * Antecedentes médicos (TASK-037; CUS-14, CUS-21, SDD §2.14.1, §4.2; RF-064, RN-10, RNF-149).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/** Paciente adulto con o sin consentimiento vigente (finalidad atención). */
function historyPatient(Tenant $tenant, bool $withConsent = true): Patient
{
    $patient = TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create([
        'document_id' => '45678912', 'birth_date' => '1990-01-31',
    ]));

    if ($withConsent) {
        test()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/patients/{$patient->uuid}/consents", [
            'channel' => 'presencial', 'purpose_care' => true, 'confirmation_document_number' => '45678912',
        ])->assertCreated();
    }

    return $patient;
}

/** @return array<string, mixed> */
function historyPayload(array $overrides = []): array
{
    return [
        'alergias' => ['Penicilina'],
        'enfermedades' => ['Hipertensión arterial'],
        'medicamentos' => ['Losartán 50 mg'],
        'observaciones' => null,
        ...$overrides,
    ];
}

it('rejects medical history without consent citing RN-10', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = historyPatient($tenant, withConsent: false);

    $this->putJson("/api/v1/patients/{$patient->uuid}/medical-history", historyPayload())
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-10');

    expect(TenantContext::run($tenant, fn () => $patient->fresh()->medical_history))->toBeNull();
})->group('T-033', 'CA-14.5', 'RN-10', 'RF-064');

it('stores the structured history and exposes the allergy warning', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $patient = historyPatient($tenant);

    $this->putJson("/api/v1/patients/{$patient->uuid}/medical-history", historyPayload([
        'alergias' => ['  Penicilina ', '', 'Látex'],
        'observaciones' => '  Control de presión mensual.  ',
    ]))
        ->assertOk()
        ->assertJsonPath('data.medical_history', [
            'alergias' => ['Penicilina', 'Látex'],
            'enfermedades' => ['Hipertensión arterial'],
            'medicamentos' => ['Losartán 50 mg'],
            'observaciones' => 'Control de presión mensual.',
        ])
        ->assertJsonPath('data.allergies', ['Penicilina', 'Látex']);

    // RNF-149: el aviso acompaña a la ficha en cada consulta.
    $this->getJson("/api/v1/patients/{$patient->uuid}")->assertJsonPath('data.allergies', ['Penicilina', 'Látex']);
})->group('RF-064', 'RNF-149', 'RNF-146');

it('normalizes an empty history to null', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = historyPatient($tenant);

    $this->putJson("/api/v1/patients/{$patient->uuid}/medical-history", [
        'alergias' => [' '], 'enfermedades' => [], 'medicamentos' => [], 'observaciones' => '   ',
    ])
        ->assertOk()
        ->assertJsonPath('data.medical_history', null)
        ->assertJsonPath('data.allergies', []);
})->group('RF-064');

it('validates the exact structure of the history', function (array $payload, string $field) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = historyPatient($tenant);

    $this->putJson("/api/v1/patients/{$patient->uuid}/medical-history", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'una clave adicional' => [historyPayload(['cirugias' => ['Apendicectomía']]), 'cirugias'],
    'una clave faltante' => [array_diff_key(historyPayload(), ['medicamentos' => true]), 'medicamentos'],
    'más de 30 alergias' => [historyPayload(['alergias' => array_fill(0, 31, 'Polen')]), 'alergias'],
    'una enfermedad de más de 150 caracteres' => [historyPayload(['enfermedades' => [str_repeat('a', 151)]]), 'enfermedades.0'],
    'observaciones de más de 2000 caracteres' => [historyPayload(['observaciones' => str_repeat('a', 2001)]), 'observaciones'],
    'una lista que no es lista' => [historyPayload(['medicamentos' => 'Losartán']), 'medicamentos'],
])->group('RF-064');

it('does not let the patient role write the history', function () {
    $tenant = Tenant::factory()->create();
    $patient = TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create());
    $this->actingAsRole('patient', $tenant);

    $this->putJson("/api/v1/patients/{$patient->uuid}/medical-history", historyPayload())->assertForbidden();
})->group('RN-06');
