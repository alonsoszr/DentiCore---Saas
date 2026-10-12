<?php

/*
 * Cálculo del riesgo de caries (TASK-081; SDD §5.8.2, §5.8.3; CUS-55 a CUS-57; RN-58 a RN-64,
 * DD-27, RF-165 a RF-170; T-125 a T-128, T-130, T-132 a T-134). El motor se simula con
 * Tests\Support\MlEngine.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\ExternalCallLog;
use App\Modules\Risk\Models\ModelVersion;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Risk\Services\CircuitBreaker;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ExternalServices;
use Tests\Support\MlEngine;
use Tests\Support\RiskFixtures;

beforeEach(function () {
    app(CircuitBreaker::class)->reset();
    ModelVersion::factory()->active()->create(['version' => '1.0.0']);
});
afterEach(fn () => app(CircuitBreaker::class)->reset());

/**
 * Clínica `pro` con un odontólogo autenticado y un paciente con la finalidad (d) y un registro de
 * variables completo.
 *
 * @return array{tenant: Tenant, dentist: User, patient: Patient, set: RiskVariableSet}
 */
function predictionScenario(array $setAttributes = []): array
{
    $tenant = Tenant::factory()->plan('pro')->create();
    $dentist = test()->actingAsRole('dentist', $tenant);
    $patient = RiskFixtures::patient($tenant);
    $set = RiskVariableSet::factory()->create([
        'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'clinical_captured_by' => $dentist->id, ...$setAttributes,
    ]);

    return compact('tenant', 'dentist', 'patient', 'set');
}

function requestPrediction(Patient $patient, RiskVariableSet $set): TestResponse
{
    return test()->postJson("/api/v1/patients/{$patient->uuid}/risk-predictions", ['risk_variable_set_id' => $set->uuid], ['Idempotency-Key' => (string) Str::uuid()]);
}

function inClinic(Tenant $tenant, Closure $callback): mixed
{
    return TenantContext::run($tenant, $callback);
}

it('assigns high level and creates one alert at probability 0.60', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'patient' => $patient, 'set' => $set] = predictionScenario();
    MlEngine::predicts(0.60);

    $response = requestPrediction($patient, $set)->assertCreated()
        ->assertJsonPath('data.level', 'alto')
        ->assertJsonPath('data.probability', '0.6000')
        ->assertJsonPath('data.status', 'vigente')
        ->assertJsonPath('data.legend', 'Herramienta de apoyo; no constituye diagnóstico');

    inClinic($tenant, function () use ($response, $dentist, $set) {
        $alert = RiskAlert::query()->sole();
        expect($response->json('data.alert_id'))->toBe($alert->uuid)
            ->and($alert->dentist_id)->toBe($dentist->id)
            ->and($alert->status)->toBe('abierta')
            ->and($set->fresh()->locked_at)->not->toBeNull()
            ->and(Notification::query()->where('event', 'alerta_riesgo_alto')->pluck('channel')->sort()->values()->all())->toBe(['correo', 'in_app'])
            ->and(OutboxMessage::query()->latest('id')->value('queue'))->toBe('critical');
    });
})->group('T-125', 'CA-55.1', 'RN-59', 'RN-61', 'CUS-57', 'RF-170');

it('assigns medium level without alert at probability 0.59', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'set' => $set] = predictionScenario();
    MlEngine::predicts(0.59);

    requestPrediction($patient, $set)->assertCreated()->assertJsonPath('data.level', 'medio')->assertJsonPath('data.alert_id', null);

    expect(inClinic($tenant, fn () => RiskAlert::query()->count()))->toBe(0);
})->group('T-126', 'CA-55.2', 'RN-59', 'RN-61');

it('stores no prediction when the engine answers in 4 seconds', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'set' => $set] = predictionScenario();
    $previous = RiskPrediction::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id]);
    ExternalServices::timesOut(MlEngine::URL);

    requestPrediction($patient, $set)->assertOk()
        ->assertJsonPath('data.status', 'no_disponible')
        ->assertJsonPath('data.message', 'Predicción no disponible');

    inClinic($tenant, fn () => expect(RiskPrediction::query()->count())->toBe(1)
        ->and($previous->fresh()->status)->toBe('vigente')
        ->and($set->fresh()->locked_at)->toBeNull());
    expect(ExternalCallLog::query()->sole()->outcome)->toBe('timeout');
})->group('T-127', 'CA-55.3', 'RN-64', 'NN-16');

it('does not call the engine when brushing frequency is missing', function () {
    ['patient' => $patient, 'set' => $set] = predictionScenario([
        'behavioral' => RiskFixtures::behavioral(['brushing_per_day' => null]), 'is_complete' => false,
    ]);
    MlEngine::predicts(0.2);

    requestPrediction($patient, $set)->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-58')
        ->assertJsonPath('missing', ['brushing_per_day']);

    Http::assertNothingSent();
})->group('T-128', 'CA-55.4', 'RN-58');

it('rejects variables captured more than 6 months ago and a patient without the prediction purpose', function () {
    ['patient' => $patient, 'set' => $set] = predictionScenario(['sociodemographic_captured_at' => now()->subMonths(7)]);
    MlEngine::predicts(0.2);

    requestPrediction($patient, $set)->assertUnprocessable()->assertJsonPath('missing', ['variables capturadas hace más de 6 meses']);

    ['tenant' => $tenant] = predictionScenario();
    $withoutRisk = RiskFixtures::patient($tenant, risk: false);
    $otherSet = RiskVariableSet::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $withoutRisk->id]);
    requestPrediction($withoutRisk, $otherSet)->assertUnprocessable()->assertJsonPath('rule', 'RN-58');

    Http::assertNothingSent();
})->group('CA-55.4', 'RN-58');

it('sends no name, document, patient uuid or birth date to the engine', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'set' => $set] = predictionScenario();
    MlEngine::predicts(0.2);
    $personal = inClinic($tenant, fn () => [$patient->uuid, (string) $patient->document_number, $patient->first_name, $patient->birth_date->toDateString()]);

    requestPrediction($patient, $set)->assertCreated();

    Http::assertSent(fn (Request $request) => array_keys($request['features']) === array_keys(MlEngine::features())
        && collect($personal)->every(fn (string $value) => ! str_contains($request->body(), $value)));
})->group('T-130', 'CA-55.6', 'RF-167', 'RES-04');

it('returns the existing prediction for a repeated request within 60 seconds', function () {
    ['patient' => $patient, 'set' => $set] = predictionScenario();
    MlEngine::predicts(0.2);

    $first = requestPrediction($patient, $set)->assertCreated();
    $this->travel(59)->seconds();
    requestPrediction($patient, $set)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    requestPrediction($patient, $set)->assertCreated();
    Http::assertSentCount(2);
})->group('T-132', 'DD-27');

it('replaces the previous current prediction and rejects any update of a stored one', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'set' => $set] = predictionScenario();
    MlEngine::predicts(0.2);
    $first = requestPrediction($patient, $set)->assertCreated()->json('data.id');

    // El registro quedó bloqueado: un cambio crea otro que lo reemplaza (RF-164).
    $next = $this->patchJson("/api/v1/risk-variable-sets/{$set->uuid}", ['clinical' => RiskFixtures::clinical(['active_lesions' => 4])])
        ->assertCreated()->json('data.id');
    $nextSet = inClinic($tenant, fn () => RiskVariableSet::query()->where('uuid', $next)->sole());
    $second = requestPrediction($patient, $nextSet)->assertCreated()->json('data.id');

    inClinic($tenant, function () use ($first, $second) {
        expect(RiskPrediction::query()->where('uuid', $first)->value('status'))->toBe('reemplazada')
            ->and(RiskPrediction::query()->where('uuid', $second)->value('status'))->toBe('vigente');

    });
    expect(fn () => inClinic($tenant, fn () => DB::transaction(fn () => DB::table('risk_predictions')->where('uuid', $first)->update(['probability' => '0.9000']))))
        ->toThrow(QueryException::class);

    // El registro reemplazado ya no sirve para calcular.
    requestPrediction($patient, $set)->assertUnprocessable()->assertJsonPath('rule', 'RF-164');
})->group('T-133', 'RN-60', 'RF-164');

it('exposes the explanation only inside a computed prediction', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'set' => $set] = predictionScenario();
    MlEngine::predicts(0.45);

    $response = requestPrediction($patient, $set)->assertCreated()
        ->assertJsonCount(3, 'data.top_increasing')
        ->assertJsonCount(3, 'data.top_decreasing')
        ->assertJsonPath('data.top_increasing.0', ['feature' => 'active_lesions', 'label' => 'Lesiones activas', 'value' => '2', 'shap' => '0.521'])
        ->assertJsonPath('data.top_decreasing.0', ['feature' => 'fluoride_toothpaste', 'label' => 'Pasta fluorada', 'value' => 'sí', 'shap' => '-0.188'])
        ->assertJsonPath('data.low_confidence', true)
        ->assertJsonCount(12, 'data.explanation_individual');
    $id = $response->json('data.id');

    $this->getJson("/api/v1/risk-predictions/{$id}/explanation")->assertNotFound();
    $this->getJson("/api/v1/risk-predictions/{$id}")->assertOk()->assertJsonPath('data.top_increasing.0.feature', 'active_lesions');

    // El administrador ve nivel, confianza, explicación global y leyendas; no la individual (§3.5).
    $this->actingAsRole('clinic_admin', $tenant);
    $admin = $this->getJson("/api/v1/risk-predictions/{$id}")->assertOk()->assertJsonPath('data.level', 'medio')->json('data');
    expect(array_keys($admin))->not->toContain('probability', 'top_increasing', 'top_decreasing', 'explanation_individual')
        ->toContain('confidence', 'explanation_global', 'legend');
})->group('T-134', 'RN-62', 'RN-63', 'CA-55.7', 'CUS-56');

it('leaves the variables that do not apply out of the top contributions', function () {
    ['patient' => $patient, 'set' => $set] = predictionScenario();
    // El adulto no tiene ceod ni estructura familiar (null, DI-20); el modelo igual les da peso.
    MlEngine::predicts(0.45, shap: ['ceod' => -0.4, 'family_structure' => 0.6]);

    requestPrediction($patient, $set)->assertCreated()
        ->assertJsonPath('data.top_increasing.0.feature', 'active_lesions')
        ->assertJsonPath('data.top_decreasing.0.feature', 'fluoride_toothpaste')
        ->assertJsonMissingPath('data.top_increasing.3')
        ->assertJsonCount(12, 'data.explanation_individual')
        ->assertJsonFragment(['feature' => 'ceod', 'label' => 'ceod', 'value' => '—', 'shap' => '-0.400']);
})->group('T-134', 'RN-62', 'DI-20');

it('answers unavailable without an active model version', function () {
    ['patient' => $patient, 'set' => $set] = predictionScenario();
    ModelVersion::query()->update(['status' => 'retirada']);
    MlEngine::predicts(0.2);

    requestPrediction($patient, $set)->assertOk()->assertJsonPath('data.status', 'no_disponible');

    Http::assertNothingSent();
})->group('RN-84', 'CUS-55');

it('shows the current prediction in the clinical record only to the administrator and the dentist', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'set' => $set] = predictionScenario();
    MlEngine::predicts(0.7);
    $id = requestPrediction($patient, $set)->assertCreated()->json('data.id');

    $this->getJson("/api/v1/patients/{$patient->uuid}/clinical-record")->assertOk()
        ->assertJsonPath('data.current_risk_prediction.id', $id)
        ->assertJsonPath('data.current_risk_prediction.level', 'alto');
    $this->actingAsRole('receptionist', $tenant);
    $this->getJson("/api/v1/patients/{$patient->uuid}/clinical-record")->assertOk()
        ->assertJsonPath('data.current_risk_prediction', null);
})->group('CUS-56', 'TASK-081');

it('expires current predictions after 12 months', function () {
    ['tenant' => $tenant, 'patient' => $patient] = predictionScenario();
    $old = RiskPrediction::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'predicted_at' => now()->subMonths(12)->subDay()]);
    $recent = RiskPrediction::factory()->create(['tenant_id' => $tenant->id]);

    $this->artisan('risk:expire-predictions')->assertSuccessful();

    inClinic($tenant, fn () => expect($old->fresh()->status)->toBe('vencida')->and($recent->fresh()->status)->toBe('vigente'));
})->group('RN-60');
