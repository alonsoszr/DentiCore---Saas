<?php

/*
 * Nota de atención, diagnósticos CIE-10 y adendas (TASK-048; SDD §5.2; CUS-80, CUS-81; RF-084,
 * RF-085, RF-090, RF-097, RN-10, RN-77, RN-78).
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
 * Atención abierta por el odontólogo autenticado.
 */
function openedAttention(Tenant $tenant, bool $withConsent = true): Attention
{
    $response = test()->postJson(
        '/api/v1/patients/'.ClinicalFixtures::patient($tenant, $withConsent)->uuid.'/attentions',
        [],
        ['Idempotency-Key' => (string) Str::uuid()],
    );

    return ClinicalFixtures::attention($tenant, $response->json('data.id'));
}

it('saves the note sections idempotently while the attention is open', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = openedAttention($tenant);
    $note = [
        'chief_complaint' => 'Dolor al masticar', 'current_illness' => 'Hace una semana', 'extraoral_exam' => 'Sin hallazgos',
        'intraoral_exam' => 'Lesión cavitada en 36', 'indications' => 'Control en 7 días',
    ];

    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", $note)->assertOk()
        ->assertJsonPath('data.chief_complaint', 'Dolor al masticar')
        ->assertJsonPath('data.status', 'borrador');
    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", $note)->assertOk();
    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Sensibilidad al frío'])->assertOk()
        ->assertJsonPath('data.chief_complaint', 'Sensibilidad al frío')
        ->assertJsonPath('data.indications', null);

    TenantContext::run($tenant, fn () => expect($attention->note()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'note.saved')->count())->toBe(3));
})->group('CUS-80', 'RF-084');

it('validates the length of each note section', function (string $section, int $max) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = openedAttention($tenant);

    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", [$section => str_repeat('a', $max + 1)])
        ->assertUnprocessable()->assertJsonValidationErrors($section);
    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", [$section => str_repeat('a', $max)])->assertOk();
})->with([
    ['chief_complaint', 2000],
    ['current_illness', 5000],
    ['extraoral_exam', 5000],
    ['intraoral_exam', 5000],
    ['indications', 5000],
])->group('RF-084');

it('lets only the dentist in charge write the note and diagnoses', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = openedAttention($tenant);

    $this->actingAsRole('dentist', $tenant);
    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Dolor'])->assertForbidden();
    $this->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertForbidden();
})->group('CUS-80');

it('blocks the note and diagnoses without a current consent', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = openedAttention($tenant, withConsent: false);

    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Dolor'])
        ->assertUnprocessable()->assertJsonPath('rule', 'RN-10');
    $this->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])
        ->assertUnprocessable()->assertJsonPath('rule', 'RN-10');
})->group('RN-10', 'CUS-80');

it('adds and removes CIE-10 diagnoses only while the attention is open', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = openedAttention($tenant);
    $diagnoses = "/api/v1/attentions/{$attention->uuid}/diagnoses";

    $this->postJson($diagnoses, ['cie10_code' => 'Z99.9', 'type' => 'definitivo'])->assertUnprocessable()->assertJsonValidationErrors('cie10_code');
    $this->postJson($diagnoses, ['cie10_code' => 'K02.1', 'type' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('type');

    $added = $this->postJson($diagnoses, ['cie10_code' => 'K02.1', 'type' => 'presuntivo'])->assertCreated()
        ->assertJsonPath('data.code', 'K02.1')
        ->assertJsonPath('data.description', 'Caries de la dentina')
        ->assertJsonPath('data.type', 'presuntivo')
        ->assertJsonPath('data.origin', 'nota');
    $this->postJson($diagnoses, ['cie10_code' => 'K05', 'type' => 'definitivo'])->assertCreated();

    $this->deleteJson("{$diagnoses}/{$added->json('data.id')}")->assertNoContent();
    $this->deleteJson("{$diagnoses}/".Str::uuid())->assertNotFound();

    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Dolor'])->assertOk();
    $this->postJson("/api/v1/attentions/{$attention->uuid}/close", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    $remaining = TenantContext::run($tenant, fn () => $attention->diagnoses()->sole());
    $this->deleteJson("{$diagnoses}/{$remaining->uuid}")->assertConflict()->assertJsonPath('rule', 'RN-78');
    $this->postJson($diagnoses, ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertConflict()->assertJsonPath('rule', 'RN-78');

    TenantContext::run($tenant, fn () => expect(AuditLog::query()->where('action', 'diagnosis.added')->count())->toBe(2));
})->group('CUS-80', 'RF-084', 'RF-085', 'RN-78');

it('searches the CIE-10 catalog with the dental chapter first', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);

    $this->getJson('/api/v1/cie10?q=caries')->assertOk()
        ->assertJsonPath('data.0.code', 'K02')
        ->assertJsonPath('data.0.is_dental', true)
        ->assertJsonPath('data.1.description', 'Caries limitada al esmalte');
    $this->getJson('/api/v1/cie10')->assertUnprocessable()->assertJsonValidationErrors('q');
})->group('RF-085', 'DD-30');

it('only adds addenda to a closed attention and validates them', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $attention = openedAttention($tenant);
    $addenda = "/api/v1/attentions/{$attention->uuid}/addenda";

    $this->postJson($addenda, ['text' => 'Antes del cierre'])->assertConflict()->assertJsonPath('rule', 'RN-78');

    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Dolor'])->assertOk();
    $this->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertCreated();
    $this->postJson("/api/v1/attentions/{$attention->uuid}/close", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    $this->postJson($addenda, ['text' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->postJson($addenda, ['text' => 'Con un código inexistente', 'diagnoses' => [['cie10_code' => 'X00', 'type' => 'definitivo']]])
        ->assertUnprocessable()->assertJsonValidationErrors('diagnoses.0.cie10_code');

    // Otro odontólogo de la clínica puede agregar información posterior (RN-78).
    $this->actingAsRole('dentist', $tenant, ['name' => 'Dr. Ramos', 'cop_number' => '67890']);
    $this->postJson($addenda, ['text' => 'Revisión posterior.'])->assertCreated()
        ->assertJsonPath('data.author.name', 'Dr. Ramos')
        ->assertJsonPath('data.author.cop', '67890');
})->group('CUS-81', 'RF-097', 'RN-78');
