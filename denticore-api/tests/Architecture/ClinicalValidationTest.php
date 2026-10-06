<?php

/*
 * RNF-121 (TASK-046; SDD §5.3): ClinicalValidator es la única clase de la aplicación que valida
 * piezas y superficies. Ninguna otra descompone el número de pieza en cuadrante y posición ni
 * declara la tabla de superficies de RN-18; el registro manual, la IA y la importación lo usan.
 */

use App\Modules\Odontogram\Services\ClinicalValidator;
use Symfony\Component\Finder\Finder;

it('keeps the tooth and surface rules inside ClinicalValidator', function () {
    $validator = (new ReflectionClass(ClinicalValidator::class))->getFileName();
    $rules = [
        '/\$\w*tooth\w*\s*%\s*10\b/i' => 'posición de la pieza',
        '/intdiv\(\s*\$\w*tooth/i' => 'cuadrante de la pieza',
        '/\$\w*tooth\w*\s*\/\s*10\b/i' => 'cuadrante de la pieza',
        "/\\[\\s*'M',\\s*'D',\\s*'O'/" => 'tabla de superficies',
    ];

    $found = [];
    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        if ($file->getRealPath() === realpath($validator)) {
            continue;
        }
        foreach ($rules as $pattern => $rule) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $found[] = "{$file->getRelativePathname()}: {$rule}";
            }
        }
    }

    expect($found)->toBe([]);
})->group('RNF-121');

arch('the clinical validator is a final stateless service')
    ->expect(ClinicalValidator::class)
    ->toBeFinal()
    ->not->toHaveConstructor()
    ->group('RNF-121');
