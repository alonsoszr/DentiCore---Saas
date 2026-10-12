<?php

/*
 * Esquema de riesgo (TASK-078; SDD §2.11; DD-12, RN-59 a RN-61, RN-84, RF-164): las restricciones
 * de la base de datos sostienen las reglas aunque falle la validación de la aplicación.
 */

use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\ModelVersion;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Código SQLSTATE del error de `$statement` (en el contexto de la clínica si se indica), o null. */
function riskSqlState(?int $tenantId, Closure $statement): ?string
{
    $run = function () use ($statement) {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return (string) $exception->getCode();
        }

        return null;
    };

    return $tenantId === null ? $run() : TenantContext::run($tenantId, $run);
}

it('rejects activating a model version with AUC 0.74 or without Brier score', function () {
    $low = ModelVersion::factory()->create(['auc_validation' => '0.7400']);
    $noBrier = ModelVersion::factory()->create(['brier_score' => null]);

    expect(riskSqlState(null, fn () => $low->forceFill(['status' => 'activa'])->save()))->toBe('23514')
        ->and(riskSqlState(null, fn () => $noBrier->forceFill(['status' => 'activa'])->save()))->toBe('23514')
        ->and(riskSqlState(null, fn () => ModelVersion::factory()->create(['auc_validation' => '0.7500'])->forceFill(['status' => 'activa'])->save()))->toBeNull();
})->group('RN-84', 'RF-175');

it('keeps a single active model version', function () {
    ModelVersion::factory()->active()->create();

    expect(riskSqlState(null, fn () => ModelVersion::factory()->active()->create()))->toBe('23505');
})->group('RN-84');

it('rejects any update of a stored prediction except its status', function () {
    $prediction = RiskPrediction::factory()->create();
    $rows = fn () => DB::table('risk_predictions')->where('id', $prediction->id);

    expect(riskSqlState($prediction->tenant_id, fn () => $rows()->update(['probability' => '0.2500'])))->toBe('55000')
        ->and(riskSqlState($prediction->tenant_id, fn () => $rows()->delete()))->toBe('55000')
        ->and(riskSqlState($prediction->tenant_id, fn () => $rows()->update(['status' => 'reemplazada'])))->toBeNull();
})->group('RN-60', 'T-133');

it('rejects a level that does not match the applied thresholds and a second current prediction', function () {
    $prediction = RiskPrediction::factory()->create();

    expect(riskSqlState($prediction->tenant_id, fn () => RiskPrediction::factory()->create([
        'tenant_id' => $prediction->tenant_id, 'probability' => '0.6000', 'level' => 'medio', 'confidence' => '0.6000',
    ])))->toBe('23514')
        ->and(riskSqlState($prediction->tenant_id, fn () => RiskPrediction::factory()->create([
            'tenant_id' => $prediction->tenant_id,
            'patient_id' => $prediction->patient_id,
            'risk_variable_set_id' => RiskVariableSet::factory()->create([
                'tenant_id' => $prediction->tenant_id, 'patient_id' => $prediction->patient_id, 'locked_at' => now(),
            ])->id,
        ])))->toBe('23505');
})->group('RN-59', 'RN-60', 'CA-55.1', 'CA-55.2');

it('rejects an alert for a prediction that is not high', function () {
    $prediction = RiskPrediction::factory()->create();

    $state = riskSqlState($prediction->tenant_id, fn () => RiskAlert::factory()->create([
        'tenant_id' => $prediction->tenant_id, 'risk_prediction_id' => $prediction->id,
    ]));

    expect($state)->toBe('23503');
})->group('RN-61');

it('requires an action and a justification of at least 20 characters to acknowledge an alert', function () {
    $alert = RiskAlert::factory()->create();
    $rows = fn () => DB::table('risk_alerts')->where('id', $alert->id);

    expect(riskSqlState($alert->tenant_id, fn () => $rows()->update(['status' => 'reconocida', 'acknowledged_at' => now()])))->toBe('23514')
        ->and(riskSqlState($alert->tenant_id, fn () => $rows()->update([
            'status' => 'reconocida', 'acknowledged_at' => now(), 'action' => 'justificacion', 'justification' => 'Muy corta',
        ])))->toBe('23514')
        ->and(riskSqlState($alert->tenant_id, fn () => $rows()->update([
            'status' => 'reconocida', 'acknowledged_at' => now(), 'action' => 'plan_preventivo',
        ])))->toBe('23514')
        ->and(riskSqlState($alert->tenant_id, fn () => $rows()->update([
            'status' => 'reconocida', 'acknowledged_at' => now(), 'action' => 'justificacion',
            'justification' => 'Paciente con control preventivo vigente en otra sede.',
        ])))->toBeNull();
})->group('RN-65', 'RF-171', 'T-135');

it('rejects changes to a variable set once it is locked by a prediction', function () {
    $open = RiskVariableSet::factory()->create();
    $locked = RiskVariableSet::factory()->create(['locked_at' => now()]);

    expect(riskSqlState($open->tenant_id, fn () => $open->forceFill(['clinical' => ['cpod' => 4]])->save()))->toBeNull()
        ->and(riskSqlState($locked->tenant_id, fn () => $locked->forceFill(['clinical' => ['cpod' => 4]])->save()))->toBe('55000');
})->group('RF-164');

it('isolates the risk tables of each clinic under RLS', function () {
    $alert = RiskAlert::factory()->create();
    $other = Tenant::factory()->create();

    TenantContext::run($other, function () {
        foreach (['risk_variable_sets', 'risk_predictions', 'risk_alerts'] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
    });
    TenantContext::run($alert->tenant_id, fn () => expect(DB::table('risk_alerts')->count())->toBe(1));
})->group('DD-40', 'RNF-102');
