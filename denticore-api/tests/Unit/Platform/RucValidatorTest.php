<?php

/*
 * RUC de la SUNAT (TASK-023; RF-014, SRS §11.1). 20131312955 es el RUC publicado de la propia
 * SUNAT: (2·5 + 0·4 + 1·3 + 3·2 + 1·7 + 3·6 + 1·5 + 2·4 + 9·3 + 5·2) = 94; 94 mod 11 = 6;
 * 11 − 6 = 5.
 */

use App\Modules\Platform\Services\RucValidator;

it('accepts a RUC with a valid check digit', function (string $ruc) {
    expect(RucValidator::isValid($ruc))->toBeTrue();
})->with([
    'persona jurídica (SUNAT)' => '20131312955',
    'persona natural' => '10'.substr('12345678', 0, 8).RucValidator::checkDigit('1012345678'),
])->group('RF-014');

it('rejects a RUC with a wrong check digit, prefix or length', function (string $ruc) {
    expect(RucValidator::isValid($ruc))->toBeFalse();
})->with([
    'dígito verificador inválido' => '20131312954',
    'prefijo 30' => '30131312955',
    '10 dígitos' => '2013131295',
    '12 dígitos' => '201313129550',
    'con letras' => '2013131295A',
])->group('RF-014');

it('maps a remainder of 0 to 1 and of 1 to 0', function () {
    // Suma 0 → resto 0 → 11 − 0 = 11 → 1.
    expect(RucValidator::checkDigit('0000000000'))->toBe(1);
    // 2·5 + 1·2 = 12 → resto 1 → 11 − 1 = 10 → 0.
    expect(RucValidator::checkDigit('2000000001'))->toBe(0)
        ->and(RucValidator::isValid('20000000010'))->toBeTrue();
})->group('RF-014');
