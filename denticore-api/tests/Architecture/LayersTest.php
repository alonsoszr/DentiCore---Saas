<?php

/*
 * T-158 (SDD §1.3, §6.3.10; RNF-119): los controladores no acceden a la BD y los
 * modelos no llaman a clientes HTTP.
 */

use App\Support\Http\Controller;

$modules = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR));

it('keeps controllers free of database access and models free of HTTP clients', function () use ($modules) {
    foreach ($modules as $module) {
        $controllers = sprintf('App\Modules\%s\Http\Controllers', $module);

        expect($controllers)->classes()->toExtend(Controller::class);
        expect($controllers)->classes()->toHaveSuffix('Controller');
        expect($controllers)->not->toUse([
            'Illuminate\Support\Facades\DB',
            'Illuminate\Database\Eloquent\Builder',
            'Illuminate\Database\Query\Builder',
            'Illuminate\Support\Facades\Hash',
        ]);

        expect(sprintf('App\Modules\%s\Models', $module))->not->toUse([
            'Illuminate\Support\Facades\Http',
            'Illuminate\Http\Client',
        ]);
    }
})->group('RNF-119');

it('keeps the shared core free of form requests', function () {
    expect('App\Support')->not->toUse('Illuminate\Foundation\Http\FormRequest');
})->group('RNF-119');
