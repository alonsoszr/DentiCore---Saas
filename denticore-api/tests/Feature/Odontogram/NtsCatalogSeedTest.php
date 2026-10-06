<?php

/*
 * Semilla del catálogo de hallazgos de la NTS N° 188-MINSA/DGIESP-2022 (TASK-043b; SDD §2.6;
 * RN-17, RF-078, RNF-004, RNF-061, RNF-151, PQ-05). Transcrita de §6.1 de la norma
 * (RM N° 559-2022/MINSA); pendiente de la firma del validador clínico.
 */

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Odontogram\Services\ClinicalValidator;

it('seeds the 38 findings of section 6.1 with their level, dentition and states', function () {
    $findings = FindingCatalog::query()->where('code', 'not like', 'PRUEBA_%')->with('states')->orderBy('display_order')->get();

    expect($findings)->toHaveCount(38)
        ->and($findings->pluck('display_order')->all())->toBe(range(1, 38))
        ->and($findings->first()->name)->toBe('Aparato ortodóntico fijo')
        ->and($findings->last()->name)->toBe('Transposición dentaria');

    foreach ($findings as $finding) {
        expect($finding->level)->toBeIn(['pieza', 'superficie', 'tramo'])
            ->and($finding->dentition)->toBeIn(['permanente', 'temporal', 'ambas'])
            ->and($finding->is_active)->toBeTrue()
            ->and($finding->introduced_in_version)->toBe('1.0')
            ->and($finding->states)->not->toBeEmpty("{$finding->code} sin estados");
    }
})->group('RN-17', 'RNF-004');

it('takes colors and acronyms only from the norm', function (string $finding, string $state, string $color, ?string $acronym) {
    $seeded = FindingState::query()
        ->whereHas('finding', fn ($query) => $query->where('code', $finding))
        ->where('code', $state)
        ->sole();

    expect($seeded->color)->toBe($color)
        ->and($seeded->acronym ?? $seeded->finding->acronym)->toBe($acronym);
})->with([
    '6.1.3 corona metal cerámica en mal estado' => ['CORONA', 'CMC_MALO', 'rojo', 'CMC'],
    '6.1.4 corona temporal' => ['CORONA_TEMPORAL', 'PRESENTE', 'rojo', 'CT'],
    '6.1.9 fosas y fisuras profundas' => ['FOSAS_FISURAS_PROF', 'PRESENTE', 'azul', 'FFP'],
    '6.1.10 fractura sin sigla' => ['FRACTURA', 'PRESENTE', 'rojo', null],
    '6.1.16 caries a nivel de la dentina' => ['CARIES', 'CD', 'rojo', 'CD'],
    '6.1.19 movilidad grado 2' => ['MOVILIDAD', 'M2', 'rojo', 'M2'],
    '6.1.20 ausente por extracción' => ['AUSENTE', 'DEX', 'azul', 'DEX'],
    '6.1.27 pulpotomía en buen estado' => ['PULPOTOMIA', 'BUENO', 'azul', 'PP'],
    '6.1.33 resina en mal estado' => ['RESTAURACION', 'R_MALO', 'rojo', 'R'],
    '6.1.37 pulpectomía en buen estado' => ['TRATAMIENTO_CONDUCTO', 'PC_BUENO', 'azul', 'PC'],
])->group('RN-17', 'RNF-151');

it('uses only blue and red and never repeats a state code within a finding', function () {
    expect(FindingState::query()->whereNotIn('color', ['azul', 'rojo'])->count())->toBe(0);

    $duplicated = FindingState::query()
        ->selectRaw('finding_id, code, count(*) as total')
        ->groupBy('finding_id', 'code')
        ->havingRaw('count(*) > 1')
        ->count();
    expect($duplicated)->toBe(0);
})->group('RNF-151');

it('validates the seeded findings with their level and dentition', function () {
    $validator = new ClinicalValidator;
    $finding = fn (string $code) => FindingCatalog::query()->where('code', $code)->with('states')->sole();

    $caries = $finding('CARIES');
    $pulpotomy = $finding('PULPOTOMIA');
    $bridge = $finding('PROTESIS_FIJA');

    expect($validator->finding(36, null, ['O'], $caries, $caries->states->firstWhere('code', 'CD')))->toBe([])
        ->and($validator->finding(36, null, [], $caries, $caries->states->firstWhere('code', 'CD')))->toHaveKey('surfaces')
        ->and($validator->finding(84, null, [], $pulpotomy, $pulpotomy->states->first()))->toBe([])
        ->and($validator->finding(46, null, [], $pulpotomy, $pulpotomy->states->first()))->toHaveKey('tooth')
        ->and($validator->finding(13, 23, [], $bridge, $bridge->states->first()))->toBe([])
        ->and($validator->finding(13, null, [], $bridge, $bridge->states->first()))->toHaveKey('tooth_end');
})->group('RN-17', 'RN-18');

it('keeps the verification checklist of the graphic annex pending the clinical validator', function () {
    $data = require database_path('data/nts188_findings.php');
    $checklist = file_get_contents(database_path('data/NTS188_VERIFICACION.md'));

    expect($data['source'])->toContain('NTS N° 188-MINSA/DGIESP-2022')
        ->and($data['review'])->toContain('pendiente')
        ->and($checklist)->toContain('Pendiente de firma del validador clínico (PQ-05)');

    foreach ($data['findings'] as $finding) {
        expect($checklist)->toContain("| {$finding['section']} |");
    }
})->group('PQ-05', 'RNF-004');
