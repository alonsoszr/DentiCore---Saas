<?php

/*
 * T-159 (SDD §1.3, §6.3.10; RNF-120): un módulo solo usa los Services y Models públicos de
 * otro módulo, nunca sus clases internas (controladores, Form Requests, Policies, Jobs,
 * Listeners).
 */

$modules = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR));

it('prevents modules from using internal classes of other modules', function () use ($modules) {
    foreach ($modules as $module) {
        $internalsOfOthers = [];

        foreach (array_diff($modules, [$module]) as $other) {
            foreach (['Http\Controllers', 'Http\Requests', 'Policies', 'Jobs', 'Listeners'] as $internal) {
                $internalsOfOthers[] = sprintf('App\Modules\%s\%s', $other, $internal);
            }
        }

        expect(sprintf('App\Modules\%s', $module))->not->toUse($internalsOfOthers);
    }
})->group('RNF-120');
