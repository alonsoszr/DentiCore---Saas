<?php

/*
 * Esquema del catálogo de hallazgos NTS 188 (TASK-043a; SDD §2.6 `finding_catalog`,
 * `finding_states`; RN-17, RF-078, DD-05). Sin datos: la semilla llega con la norma (TASK-043b).
 */

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('creates findings with their states through the factories', function () {
    $finding = FindingCatalog::factory()->has(FindingState::factory()->count(2)->sequence(['color' => 'rojo'], ['color' => 'azul']), 'states')->create();

    expect($finding->states)->toHaveCount(2)
        ->and($finding->states->pluck('color')->all())->toEqualCanonicalizing(['rojo', 'azul'])
        ->and($finding->level)->toBeIn(['pieza', 'superficie', 'tramo'])
        ->and($finding->dentition)->toBeIn(['permanente', 'temporal', 'ambas']);
})->group('RN-17');

it('never deletes a finding of the catalog', function () {
    $finding = FindingCatalog::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('finding_catalog')->where('id', $finding->id)->delete()))
        ->toThrow(QueryException::class, 'immutable_row');

    // Retirarlo es cambiar su vigencia (RF-078).
    $finding->forceFill(['is_active' => false, 'retired_in_version' => '2.0'])->save();
    expect($finding->fresh()->is_active)->toBeFalse();
})->group('RF-078');

it('restricts the level, dentition and color to the closed domains', function (string $table, array $values, string $constraint) {
    $finding = FindingCatalog::factory()->create();
    $row = $table === 'finding_catalog'
        ? ['code' => 'X1', 'name' => 'X', 'level' => 'pieza', 'dentition' => 'ambas', 'introduced_in_version' => '1.0', 'is_active' => true, 'display_order' => 1]
        : ['finding_id' => $finding->id, 'code' => 'S1', 'name' => 'S', 'color' => 'azul', 'is_active' => true];

    expect(fn () => DB::transaction(fn () => DB::table($table)->insert([...$row, ...$values])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'nivel desconocido' => ['finding_catalog', ['level' => 'arcada'], 'finding_catalog_level_check'],
    'dentición desconocida' => ['finding_catalog', ['dentition' => 'mixta'], 'finding_catalog_dentition_check'],
    'color distinto de azul o rojo' => ['finding_states', ['color' => 'verde'], 'finding_states_color_check'],
])->group('RN-17', 'RNF-151');

it('keeps each state code unique within its finding', function () {
    $finding = FindingCatalog::factory()->create();
    FindingState::factory()->for($finding, 'finding')->create(['code' => 'ACTIVA']);

    expect(fn () => DB::transaction(fn () => FindingState::factory()->for($finding, 'finding')->create(['code' => 'ACTIVA'])))
        ->toThrow(QueryException::class, 'finding_states_finding_id_code_key');
    FindingState::factory()->for(FindingCatalog::factory(), 'finding')->create(['code' => 'ACTIVA']);
})->group('RN-17');
