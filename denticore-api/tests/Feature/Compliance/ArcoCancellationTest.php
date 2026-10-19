<?php

/*
 * Cancelación ARCO aceptada (TASK-104; CUS-64 FA-1; SDD §5.11, DI-21; RF-184, RN-68, RN-69). T-143,
 * T-144.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArcoFixtures;
use Tests\Support\ClinicalFixtures;
use Tests\Support\PortalFixtures;

/**
 * Titular con cuenta de portal y finalidades opcionales, un hallazgo en una atención abierta y una
 * predicción calculada con variables sociodemográficas.
 *
 * @return array{user: User, patient: Patient, attention: Attention, set: RiskVariableSet, prediction: RiskPrediction}
 */
function cancellationScenario(Tenant $tenant): array
{
    $user = User::factory()->for($tenant)->create(['role' => 'patient']);
    $patient = ArcoFixtures::patientWithOptionalPurposes($tenant, ['user_id' => $user->id]);
    $user->createToken('portal', ['full']);
    PortalFixtures::recordedFinding($tenant, $patient);

    return TenantContext::run($tenant, function () use ($tenant, $user, $patient) {
        $set = RiskVariableSet::factory()->create([
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'sociodemographic' => ['respondent' => 'titular', 'education_level' => 'secundaria', 'employment_status' => 'empleado'],
            'locked_at' => now(),
        ]);
        $prediction = RiskPrediction::factory()->create([
            'tenant_id' => $tenant->id,
            'risk_variable_set_id' => $set->id,
            'explanation_individual' => [
                ['feature' => 'active_lesions', 'value' => '2', 'shap' => 0.521],
                ['feature' => 'education_level', 'value' => 'secundaria', 'shap' => 0.104],
                ['feature' => 'employment_status', 'value' => 'empleado', 'shap' => -0.031],
            ],
        ]);

        return ['user' => $user, 'patient' => $patient, 'attention' => Attention::query()->where('patient_id', $patient->id)->sole(), 'set' => $set, 'prediction' => $prediction];
    });
}

it('keeps odontogram entries and blocks the patient on an accepted cancellation', function () {
    $tenant = Tenant::factory()->create();
    ['user' => $user, 'patient' => $patient, 'set' => $set, 'prediction' => $prediction] = cancellationScenario($tenant);
    $entries = TenantContext::run($tenant, fn () => OdontogramEntry::query()->where('patient_id', $patient->id)->count());
    $officer = ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, $patient, 'cancelacion');

    $this->postJson("/api/v1/arco-requests/{$request->uuid}/cancellation", [], ArcoFixtures::key())
        ->assertOk()
        ->assertJsonPath('data.status', 'en_tramite');

    TenantContext::run($tenant, function () use ($patient, $entries, $set, $prediction, $user, $request, $officer) {
        // CA-64.2: la HC permanece y el paciente queda bloqueado (RN-68).
        expect(OdontogramEntry::query()->where('patient_id', $patient->id)->count())->toBe($entries)->toBeGreaterThan(0)
            ->and($patient->fresh()->archive_status)->toBe('bloqueado');

        // DI-21: se eliminan solo los datos de la finalidad opcional (d).
        expect($set->fresh()->sociodemographic)->toBe([])
            ->and($prediction->fresh()->explanation_individual)->toEqual([
                ['feature' => 'active_lesions', 'value' => '2', 'shap' => 0.521],
                ['feature' => 'education_level', 'value' => null, 'shap' => 0.104],
                ['feature' => 'employment_status', 'value' => null, 'shap' => -0.031],
            ])
            ->and($prediction->fresh()->level)->toBe($prediction->level);

        // Finalidades opcionales revocadas con el canal `arco`; la de atención sigue vigente.
        $consent = Consent::query()->where('patient_id', $patient->id)->sole();
        expect($consent->status)->toBe('vigente')
            ->and($consent->purposeRevocations()->pluck('purpose')->sort()->values()->all())->toBe(['encuestas', 'notificaciones'])
            ->and(DB::table('consent_purpose_revocations')->distinct()->pluck('arco_request_id')->all())->toBe([$request->id])
            ->and(DB::table('consent_purpose_revocations')->distinct()->pluck('channel')->all())->toBe(['arco']);

        // FA-1: la cuenta de portal queda desactivada y sin sesiones.
        expect($user->fresh()->status)->toBe('inactivo')
            ->and($user->tokens()->count())->toBe(0);

        $audit = AuditLog::query()->where('action', 'patient.blocked')->sole();
        expect($audit->patient_uuid)->toBe($patient->uuid)
            ->and($audit->user_id)->toBe($officer->id);
    });

    // La cancelación ya aplicada no se repite.
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/cancellation", [], ArcoFixtures::key())->assertStatus(409);

    // Resolver la cancelación responde al titular y cierra la solicitud.
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/resolve", ['status' => 'atendida', 'response' => ArcoFixtures::response()])
        ->assertOk()->assertJsonPath('data.status', 'atendida');
})->group('T-143', 'CA-64.2', 'RN-68', 'RN-69', 'RF-184', 'DI-21');

it('rejects a finding for a blocked patient with 422', function () {
    $tenant = Tenant::factory()->create();
    ['patient' => $patient, 'attention' => $attention] = cancellationScenario($tenant);
    $dentist = TenantContext::run($tenant, fn () => $attention->dentist);
    ArcoFixtures::officer($tenant);
    $request = ArcoFixtures::started($tenant, $patient, 'cancelacion');
    $this->postJson("/api/v1/arco-requests/{$request->uuid}/cancellation", [], ArcoFixtures::key())->assertOk();

    // CA-64.3: el odontólogo ya no registra hallazgos en la atención que tenía abierta.
    $this->actingWithToken($dentist);
    ClinicalFixtures::recordFinding(TenantContext::run($tenant, fn () => $attention->fresh()), ['tooth' => 47])
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-10');
})->group('T-144', 'CA-64.3', 'RN-69', 'RN-14');

it('applies the cancellation only to cancellation requests in progress', function () {
    $tenant = Tenant::factory()->create();
    $patient = ClinicalFixtures::patient($tenant);
    ArcoFixtures::officer($tenant);
    $access = ArcoFixtures::started($tenant, $patient);
    $received = ArcoFixtures::request($tenant, $patient, 'cancelacion');

    $this->postJson("/api/v1/arco-requests/{$access->uuid}/cancellation", [], ArcoFixtures::key())->assertUnprocessable();
    $this->postJson("/api/v1/arco-requests/{$received->uuid}/cancellation", [], ArcoFixtures::key())->assertStatus(409);

    // Resolver como atendida una cancelación sin aplicar es 422: primero se bloquea al paciente.
    $started = ArcoFixtures::started($tenant, $patient, 'cancelacion');
    $this->postJson("/api/v1/arco-requests/{$started->uuid}/resolve", ['status' => 'atendida', 'response' => ArcoFixtures::response()])
        ->assertUnprocessable();
    expect(TenantContext::run($tenant, fn () => $patient->fresh()->archive_status))->toBe('activo');
})->group('CUS-64', 'RF-184');

it('keeps the risk data immutable outside the ARCO erasure', function () {
    $tenant = Tenant::factory()->create();
    ['set' => $set, 'prediction' => $prediction] = cancellationScenario($tenant);

    // DI-21: sin `app.arco_erasure`, los registros bloqueados siguen siendo inmutables.
    expect(fn () => TenantContext::run($tenant, fn () => DB::transaction(
        fn () => DB::table('risk_variable_sets')->where('id', $set->id)->update(['sociodemographic' => '{}']),
    )))->toThrow(QueryException::class, 'immutable_row');

    // Con la variable, solo se vacían esas columnas: cambiar otra sigue rechazado.
    expect(fn () => TenantContext::run($tenant, fn () => DB::transaction(function () use ($prediction) {
        DB::statement("SELECT set_config('app.arco_erasure', 'on', true)");
        DB::table('risk_predictions')->where('id', $prediction->id)->update(['level' => 'bajo', 'probability' => '0.1000']);
    })))->toThrow(QueryException::class, 'immutable_row');
    expect(fn () => TenantContext::run($tenant, fn () => DB::transaction(function () use ($prediction) {
        DB::statement("SELECT set_config('app.arco_erasure', 'on', true)");
        DB::table('risk_predictions')->where('id', $prediction->id)->update([
            'explanation_individual' => json_encode([['feature' => 'active_lesions', 'value' => '9', 'shap' => 0.521]]),
        ]);
    })))->toThrow(QueryException::class, 'immutable_row');
})->group('DI-21', 'RN-60');
