<?php

/*
 * El validador clínico y los CHECK de la BD aplican la misma tabla de piezas y superficies
 * (TASK-046; SDD §2.2, §5.3; RN-16, RN-18, RNF-003): un registro que pasa el validador nunca
 * lo rechaza la BD, y viceversa.
 */

use App\Modules\Odontogram\Services\ClinicalValidator;
use Illuminate\Support\Facades\DB;

it('agrees with fn_valid_tooth and fn_valid_surfaces for every tooth number and surface', function () {
    $validator = new ClinicalValidator;
    $database = collect(DB::select(<<<'SQL'
        SELECT t AS tooth, s AS surface, fn_valid_tooth(t::smallint) AS tooth_valid,
               fn_valid_tooth(t::smallint) AND fn_valid_surfaces(t::smallint, ARRAY[s]) AS surface_valid
          FROM generate_series(0, 99) AS t
         CROSS JOIN unnest(ARRAY['M','D','O','I','V','L','P']) AS s
        SQL));

    $disagreements = $database->reject(fn (object $row) => ($validator->toothError($row->tooth) === null) === $row->tooth_valid
        && ($row->tooth_valid === false || ($validator->surfaceError($row->tooth, $row->surface) === null) === $row->surface_valid));

    expect($database)->toHaveCount(700)
        ->and($database->where('surface_valid', true)->count())->toBe(52 * 5)
        ->and($disagreements->map(fn (object $row) => "{$row->tooth}{$row->surface}")->values()->all())->toBe([]);
})->group('RNF-003', 'RN-16', 'RN-18');
