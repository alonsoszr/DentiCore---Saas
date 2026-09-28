<?php

/*
 * Objetos SQL auxiliares de SDD §2.2 (TASK-006; RN-16, RN-18, DI-09, DI-11, DI-21, RNF-003).
 * Las expectativas se derivan del texto de RN-16 y RN-18 del SRS, no de la función.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Piezas del Sistema Dígito Dos (RN-16): permanentes 11–18, 21–28, 31–38, 41–48;
 * temporales 51–55, 61–65, 71–75, 81–85.
 *
 * @return list<int>
 */
function validTeeth(): array
{
    $teeth = [];
    foreach ([1, 2, 3, 4] as $quadrant) {
        foreach (range(1, 8) as $position) {
            $teeth[] = $quadrant * 10 + $position;
        }
    }
    foreach ([5, 6, 7, 8] as $quadrant) {
        foreach (range(1, 5) as $position) {
            $teeth[] = $quadrant * 10 + $position;
        }
    }

    return $teeth;
}

/**
 * RN-18: O solo en premolares y molares; I solo en incisivos y caninos; P solo en piezas
 * superiores; L solo en piezas inferiores; M, D y V en todas.
 */
function surfaceAllowedByRn18(int $tooth, string $surface): bool
{
    $quadrant = intdiv($tooth, 10);
    $position = $tooth % 10;
    $isIncisorOrCanine = $position <= 3;
    // Permanentes: premolares 4–5 y molares 6–8; temporales: molares 4–5.
    $isPremolarOrMolar = $position >= 4;
    $isUpper = in_array($quadrant, [1, 2, 5, 6], true);

    return match ($surface) {
        'O' => $isPremolarOrMolar,
        'I' => $isIncisorOrCanine,
        'P' => $isUpper,
        'L' => ! $isUpper,
        'M', 'D', 'V' => true,
    };
}

function sqlBool(string $sql, array $bindings = []): bool
{
    return (bool) DB::selectOne($sql, $bindings)->result;
}

it('accepts the 52 teeth of the two digit system and rejects 19, 29, 56 and 91', function () {
    expect(validTeeth())->toHaveCount(52);

    foreach (validTeeth() as $tooth) {
        expect(sqlBool('select fn_valid_tooth(?::smallint) as result', [$tooth]))->toBeTrue("pieza {$tooth}");
    }

    foreach ([19, 29, 56, 91, 10, 0, 49, 86] as $tooth) {
        expect(sqlBool('select fn_valid_tooth(?::smallint) as result', [$tooth]))->toBeFalse("pieza {$tooth}");
    }
})->group('RN-16', 'RNF-003');

it('matches the RN-18 table in the 364 tooth and surface combinations', function () {
    $combinations = 0;

    foreach (validTeeth() as $tooth) {
        foreach (['M', 'D', 'O', 'I', 'V', 'L', 'P'] as $surface) {
            $combinations++;
            $actual = sqlBool('select fn_valid_surfaces(?::smallint, ARRAY[?]::text[]) as result', [$tooth, $surface]);

            expect($actual)->toBe(surfaceAllowedByRn18($tooth, $surface), "pieza {$tooth}, superficie {$surface}");
        }
    }

    expect($combinations)->toBe(364);
})->group('RN-18', 'RNF-003');

it('rejects unknown and repeated surfaces', function () {
    expect(sqlBool("select fn_valid_surfaces(16::smallint, ARRAY['M','O','D']) as result"))->toBeTrue()
        ->and(sqlBool("select fn_valid_surfaces(16::smallint, ARRAY['X']) as result"))->toBeFalse()
        ->and(sqlBool("select fn_valid_surfaces(16::smallint, ARRAY['M','M']) as result"))->toBeFalse();
})->group('RN-18');

it('tells whether two teeth belong to the same arch', function () {
    expect(sqlBool('select fn_same_arch(11::smallint, 28::smallint) as result'))->toBeTrue()
        ->and(sqlBool('select fn_same_arch(51::smallint, 65::smallint) as result'))->toBeTrue()
        ->and(sqlBool('select fn_same_arch(11::smallint, 41::smallint) as result'))->toBeFalse();
})->group('RN-17');

it('creates the timerange type for working hour slots', function () {
    expect(sqlBool("select '[08:00,13:00)'::timerange && '[12:00,14:00)'::timerange as result"))->toBeTrue()
        ->and(sqlBool("select '[08:00,13:00)'::timerange && '[13:00,14:00)'::timerange as result"))->toBeFalse();
})->group('DI-09');

it('rejects UPDATE and DELETE with SQLSTATE 55000 and allows DELETE only with app.retention_delete', function () {
    DB::statement('create temporary table immutable_probe (id bigint primary key, value text)');
    DB::statement('create trigger trg_forbid_update_delete before update or delete on immutable_probe for each row execute function fn_forbid_update_delete()');
    DB::insert("insert into immutable_probe values (1, 'original')");

    $sqlStateOf = function (callable $statement): ?string {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return $exception->errorInfo[0] ?? null;
        }

        return null;
    };

    expect($sqlStateOf(fn () => DB::update("update immutable_probe set value = 'x'")))->toBe('55000')
        ->and($sqlStateOf(fn () => DB::delete('delete from immutable_probe')))->toBe('55000')
        ->and(DB::selectOne('select value from immutable_probe')->value)->toBe('original');

    DB::transaction(function () {
        DB::select("select set_config('app.retention_delete', 'on', true)");
        DB::delete('delete from immutable_probe');
    });

    expect(DB::selectOne('select count(*) as total from immutable_probe')->total)->toBe(0);
})->group('DI-21', 'RN-22');

it('chains each row hash to the previous row of the same scope', function () {
    DB::statement('create temporary table chain_probe (id bigserial primary key, scope_id bigint, value text, prev_hash char(64), hash char(64))');
    DB::statement("create trigger trg_hash_chain before insert on chain_probe for each row execute function fn_hash_chain('chain_probe', 'scope_id')");

    DB::insert("insert into chain_probe (scope_id, value) values (1, 'a'), (2, 'b')");
    DB::insert("insert into chain_probe (scope_id, value) values (1, 'c')");

    $rows = collect(DB::select('select scope_id, value, prev_hash, hash from chain_probe order by id'))->keyBy('value');

    expect($rows['a']->prev_hash)->toBe(str_repeat('0', 64))
        ->and($rows['b']->prev_hash)->toBe(str_repeat('0', 64))
        ->and($rows['c']->prev_hash)->toBe($rows['a']->hash)
        ->and($rows['a']->hash)->toMatch('/^[0-9a-f]{64}$/');
})->group('DI-11', 'DD-46');
