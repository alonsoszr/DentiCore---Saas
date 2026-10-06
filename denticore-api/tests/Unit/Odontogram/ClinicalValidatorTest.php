<?php

/*
 * Validador clínico central (TASK-046; SDD §5.3; RN-16, RN-17, RN-18, RN-25, RNF-003, RNF-121).
 */

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Odontogram\Services\ClinicalValidator;

/**
 * Las 52 piezas de RN-16 con las superficies que admite cada una según la tabla de RN-18,
 * escritas por cuadrante y tipo de pieza (oráculo independiente del validador).
 *
 * @return array<int, list<string>>
 */
function allowedSurfacesByTooth(): array
{
    $upperAnterior = ['M', 'D', 'I', 'V', 'P'];
    $upperPosterior = ['M', 'D', 'O', 'V', 'P'];
    $lowerAnterior = ['M', 'D', 'I', 'V', 'L'];
    $lowerPosterior = ['M', 'D', 'O', 'V', 'L'];

    $teeth = [];
    foreach ([1 => true, 2 => true, 3 => false, 4 => false] as $quadrant => $upper) {
        foreach (range(1, 8) as $position) {
            $teeth[$quadrant * 10 + $position] = $position <= 3
                ? ($upper ? $upperAnterior : $lowerAnterior)
                : ($upper ? $upperPosterior : $lowerPosterior);
        }
    }
    foreach ([5 => true, 6 => true, 7 => false, 8 => false] as $quadrant => $upper) {
        foreach (range(1, 5) as $position) {
            $teeth[$quadrant * 10 + $position] = $position <= 3
                ? ($upper ? $upperAnterior : $lowerAnterior)
                : ($upper ? $upperPosterior : $lowerPosterior);
        }
    }

    return $teeth;
}

/**
 * @return iterable<string, array{int, string, bool}>
 */
function toothSurfaceCombinations(): iterable
{
    foreach (allowedSurfacesByTooth() as $tooth => $allowed) {
        foreach (['M', 'D', 'O', 'I', 'V', 'L', 'P'] as $surface) {
            yield "{$tooth}{$surface}" => [$tooth, $surface, in_array($surface, $allowed, true)];
        }
    }
}

/**
 * @param  array<string, mixed>  $attributes
 */
function catalogFinding(array $attributes = []): FindingCatalog
{
    return (new FindingCatalog)->forceFill([
        'id' => 1, 'code' => 'PRUEBA_CARIES', 'name' => 'Hallazgo de prueba', 'level' => 'superficie',
        'dentition' => 'ambas', 'introduced_in_version' => '1.0', 'is_active' => true, 'display_order' => 1,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function catalogState(array $attributes = []): FindingState
{
    return (new FindingState)->forceFill([
        'id' => 10, 'finding_id' => 1, 'code' => 'ACTIVO', 'name' => 'Activo', 'color' => 'rojo', 'is_active' => true,
        ...$attributes,
    ]);
}

it('covers the 52 teeth of the two-digit system', function () {
    expect(allowedSurfacesByTooth())->toHaveCount(52)
        ->and(iterator_to_array(toothSurfaceCombinations()))->toHaveCount(364);
})->group('RNF-003', 'RN-16');

it('validates the 52 teeth by 7 surfaces combinations against RN-16 and RN-18', function (int $tooth, string $surface, bool $valid) {
    $validator = new ClinicalValidator;

    expect($validator->toothError($tooth))->toBeNull()
        ->and($validator->surfaceError($tooth, $surface) === null)->toBe($valid)
        ->and($validator->finding($tooth, null, [$surface], catalogFinding(), catalogState()) === [])->toBe($valid);
})->with(toothSurfaceCombinations())->group('T-055', 'RNF-003', 'RN-16', 'RN-18');

it('rejects teeth outside the two-digit system', function (int $tooth) {
    $validator = new ClinicalValidator;

    expect($validator->toothError($tooth))->toBe("La pieza {$tooth} no existe en el Sistema Dígito Dos.")
        ->and($validator->finding($tooth, null, ['M'], catalogFinding(), catalogState()))->toHaveKey('tooth');
})->with([0, 10, 19, 20, 29, 39, 49, 50, 56, 66, 76, 86, 91, 100])->group('RN-16');

it('explains why a surface does not apply to the tooth', function (int $tooth, string $surface, string $message) {
    expect((new ClinicalValidator)->surfaceError($tooth, $surface))->toBe($message);
})->with([
    'oclusal en incisivo (CA-22.3)' => [11, 'O', 'La superficie oclusal no aplica a incisivos.'],
    'oclusal en canino temporal' => [53, 'O', 'La superficie oclusal no aplica a caninos.'],
    'incisal en premolar' => [14, 'I', 'La superficie incisal no aplica a premolares.'],
    'incisal en molar temporal' => [84, 'I', 'La superficie incisal no aplica a molares.'],
    'palatina en pieza inferior' => [36, 'P', 'La superficie palatina solo aplica a piezas superiores.'],
    'lingual en pieza superior' => [26, 'L', 'La superficie lingual solo aplica a piezas inferiores.'],
    'letra desconocida' => [16, 'X', 'La superficie «X» no existe.'],
])->group('RN-18');

it('rejects a state of another finding, surfaces on a tooth level finding and a span across arches', function () {
    $validator = new ClinicalValidator;

    expect($validator->finding(16, null, ['O'], catalogFinding(), catalogState(['finding_id' => 2])))
        ->toBe(['state_code' => 'El estado no corresponde al hallazgo.'])
        ->and($validator->finding(16, null, ['O'], catalogFinding(['level' => 'pieza']), catalogState()))
        ->toBe(['surfaces' => 'El hallazgo «Hallazgo de prueba» se registra por pieza, sin superficies.'])
        ->and($validator->finding(13, 43, [], catalogFinding(['level' => 'tramo']), catalogState()))
        ->toBe(['tooth_end' => 'La pieza final del tramo debe estar en el mismo arco.']);
})->group('T-056', 'RN-17');

it('applies the level, dentition and validity of the catalog', function (array $input, array $errors) {
    [$tooth, $toothEnd, $surfaces, $finding, $state] = $input;

    expect((new ClinicalValidator)->finding($tooth, $toothEnd, $surfaces, $finding(), $state()))->toBe($errors);
})->with([
    'hallazgo fuera del catálogo (RN-25)' => [[16, null, ['O'], fn () => null, fn () => null], ['finding_code' => 'El hallazgo no pertenece al catálogo NTS 188 vigente.']],
    'hallazgo retirado' => [[16, null, ['O'], fn () => catalogFinding(['is_active' => false]), fn () => catalogState()], ['finding_code' => 'El hallazgo no pertenece al catálogo NTS 188 vigente.']],
    'sin estado' => [[16, null, ['O'], fn () => catalogFinding(), fn () => null], ['state_code' => 'El estado no corresponde al hallazgo.']],
    'estado inactivo' => [[16, null, ['O'], fn () => catalogFinding(), fn () => catalogState(['is_active' => false])], ['state_code' => 'El estado no corresponde al hallazgo.']],
    'superficie sin superficies' => [[16, null, [], fn () => catalogFinding(), fn () => catalogState()], ['surfaces' => 'El hallazgo «Hallazgo de prueba» se registra por superficie: indique al menos una.']],
    'superficie repetida' => [[16, null, ['O', 'O'], fn () => catalogFinding(), fn () => catalogState()], ['surfaces' => 'Cada superficie se indica una sola vez.']],
    'pieza sin superficies' => [[16, null, [], fn () => catalogFinding(['level' => 'pieza']), fn () => catalogState()], []],
    'tramo sin pieza final' => [[13, null, [], fn () => catalogFinding(['level' => 'tramo']), fn () => catalogState()], ['tooth_end' => 'El hallazgo «Hallazgo de prueba» requiere la pieza final del tramo.']],
    'tramo hacia la misma pieza' => [[13, 13, [], fn () => catalogFinding(['level' => 'tramo']), fn () => catalogState()], ['tooth_end' => 'La pieza final del tramo debe ser distinta de la inicial.']],
    'tramo hacia una pieza inexistente' => [[13, 19, [], fn () => catalogFinding(['level' => 'tramo']), fn () => catalogState()], ['tooth_end' => 'La pieza 19 no existe en el Sistema Dígito Dos.']],
    'tramo válido' => [[13, 23, [], fn () => catalogFinding(['level' => 'tramo']), fn () => catalogState()], []],
    'pieza final fuera de un tramo' => [[16, 17, ['O'], fn () => catalogFinding(), fn () => catalogState()], ['tooth_end' => 'Solo los hallazgos de tramo admiten pieza final.']],
    'permanente en pieza temporal' => [[55, null, ['O'], fn () => catalogFinding(['dentition' => 'permanente']), fn () => catalogState()], ['tooth' => 'El hallazgo «Hallazgo de prueba» no aplica a piezas temporales.']],
    'temporal en pieza permanente' => [[16, null, ['O'], fn () => catalogFinding(['dentition' => 'temporal']), fn () => catalogState()], ['tooth' => 'El hallazgo «Hallazgo de prueba» no aplica a piezas permanentes.']],
    'tramo que termina en otra dentición' => [[73, 43, [], fn () => catalogFinding(['level' => 'tramo', 'dentition' => 'temporal']), fn () => catalogState()], ['tooth_end' => 'El hallazgo «Hallazgo de prueba» no aplica a piezas permanentes.']],
])->group('T-056', 'RN-17', 'RN-25');
