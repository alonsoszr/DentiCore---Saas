<?php

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;

/*
 * Mensajes 422 en español (SRS: interfaz en español del Perú). Cada regla que usan los Form
 * Requests de la API debe tener su traducción en lang/es/validation.php; si falta, Laravel cae al
 * inglés y la pantalla muestra «The … field must …» junto al campo.
 */

dataset('reglas de la API', [
    'accepted', 'after', 'after_or_equal', 'alpha_dash', 'array', 'before_or_equal', 'boolean', 'confirmed',
    'date', 'date_format', 'decimal', 'distinct', 'email', 'exists', 'extensions', 'file', 'in', 'integer', 'ip',
    'list', 'mimes', 'mimetypes', 'missing', 'numeric', 'present', 'prohibited', 'regex', 'required',
    'required_if', 'required_with', 'required_without', 'required_without_all', 'string', 'unique', 'url', 'uuid',
]);

dataset('reglas con tamaño', ['between', 'max', 'min', 'size', 'gt', 'gte', 'lt', 'lte']);

it('translates every validation rule used by the API', function (string $rule) {
    expect(Lang::hasForLocale("validation.{$rule}", 'es'))->toBeTrue("Falta validation.{$rule} en español");
})->with('reglas de la API');

it('translates every type of the size rules', function (string $rule) {
    foreach (['array', 'file', 'numeric', 'string'] as $type) {
        expect(Lang::hasForLocale("validation.{$rule}.{$type}", 'es'))->toBeTrue("Falta validation.{$rule}.{$type} en español");
    }
})->with('reglas con tamaño');

it('names the attributes in Spanish in the messages', function () {
    app()->setLocale('es');

    $errors = Validator::make(
        ['ruc' => 'x', 'discount_cap_pct' => 150, 'reason' => ''],
        ['ruc' => 'size:11', 'discount_cap_pct' => 'numeric|max:100', 'reason' => 'required'],
    )->errors();

    expect($errors->first('ruc'))->toBe('El campo RUC debe tener 11 caracteres.')
        ->and($errors->first('discount_cap_pct'))->toBe('El campo tope de descuento no debe ser mayor que 100.')
        ->and($errors->first('reason'))->toBe('El campo motivo es obligatorio.');
});
