<?php

/*
 * Catálogo CIE-10 (TASK-044; SDD §2.6 `cie10_codes`; DD-30, RF-085, DE-06, PL-03).
 */

use App\Modules\Odontogram\Models\Cie10Code;
use Illuminate\Support\Facades\DB;

it('returns K02 codes first when searching caries', function () {
    // Un código no odontológico que también menciona caries (dato sintético de la prueba).
    DB::table('cie10_codes')->insert([
        'code' => 'Z99.9', 'description' => 'Caries sintética fuera del capítulo K', 'chapter' => 'XXI',
        'is_dental' => false, 'search_text' => 'caries sintetica fuera del capitulo k', 'is_active' => true,
    ]);

    $codes = Cie10Code::search('caries')->pluck('code')->all();

    expect($codes[0])->toBe('K02')
        ->and(array_slice($codes, 0, 8))->each->toStartWith('K02')
        ->and($codes)->toHaveCount(9)
        ->and(end($codes))->toBe('Z99.9');
})->group('T-067', 'RF-085', 'DD-30');

it('searches without accents or case and by code prefix', function () {
    expect(Cie10Code::search('ENCÍA')->pluck('code')->all())->toBe(['K06'])
        ->and(Cie10Code::search('k05')->pluck('code')->all())->toBe(['K05'])
        ->and(Cie10Code::search('')->count())->toBe(0);
})->group('RF-085');

it('seeds the dental chapter K00 to K14 with its provisional source', function () {
    expect(Cie10Code::query()->where('is_dental', true)->count())->toBe(23)
        ->and(Cie10Code::query()->where('code', 'K02.1')->value('description'))->toBe('Caries de la dentina')
        ->and(Cie10Code::query()->where('is_dental', true)->whereNot('chapter', 'XI')->count())->toBe(0);

    $source = require database_path('data/cie10_k00_k14.php');
    expect($source['source'])->toContain('provisional')
        ->and($source['license'])->not->toBeEmpty()
        ->and($source['codes'])->toHaveCount(23);
})->group('DE-06', 'PL-03');
