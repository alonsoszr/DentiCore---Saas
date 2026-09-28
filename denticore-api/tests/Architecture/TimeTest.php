<?php

/*
 * T-161 (SDD §6.2, §6.3.10; RNF-130): las pruebas usan reloj simulado (travelTo,
 * Carbon::setTestNow) y nunca esperas reales.
 */

it('forbids sleep and real clock waits in the test suite', function () {
    $forbidden = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $tokens = PhpToken::tokenize(file_get_contents($file->getPathname()));

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_STRING) || ! in_array(strtolower($token->text), $forbidden, true)) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            $previous = $tokens[$index - 1] ?? null;
            $isCall = $next?->text === '(' && ! in_array($previous?->text, ['->', '::', 'function'], true);

            if ($isCall) {
                $violations[] = $file->getFilename().':'.$token->line.' '.$token->text.'()';
            }
        }
    }

    expect($violations)->toBe([]);
})->group('RNF-130');
