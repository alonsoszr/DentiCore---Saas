<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/*
 * Mensajes 422 en español (RNF-188: 0 claves de traducción faltantes). Cada regla que usan los Form
 * Requests y los `$request->validate([...])` de la API debe tener su traducción en
 * lang/es/validation.php; si falta, Laravel cae al inglés y la pantalla muestra «The … field must …»
 * junto al campo. Las reglas se leen del código, así que una regla nueva sin traducción hace fallar la
 * prueba sin tocar esta lista.
 */

/** Reglas con un mensaje por tipo de valor (arreglo, archivo, número o texto). */
const SIZED_RULES = ['between', 'max', 'min', 'size', 'gt', 'gte', 'lt', 'lte'];

/**
 * Reglas de validación de Laravel que aparecen en `rules()` y en `validate([...])` de app/: las de las
 * cadenas ('required', 'max:100', 'string|email') y las de los objetos Rule (Rule::in → in).
 *
 * @return list<string>
 */
function validationRulesUsedByTheApi(): array
{
    $known = array_keys(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
    $used = [];

    foreach (File::allFiles(app_path()) as $file) {
        $code = $file->getContents();
        preg_match_all('/function rules\(\): array\s*\{(.*?)\n    \}/s', $code, $rules);
        preg_match_all('/->validate\(\[(.*?)\n\s*\]\)/s', $code, $inline);

        foreach ([...$rules[1], ...$inline[1]] as $segment) {
            // Sin los nombres de campo ('email' => ...): solo quedan las reglas y sus parámetros.
            $segment = preg_replace("/'[^']*'\s*=>/", '', $segment);
            preg_match_all("/'([^']*)'/", $segment, $literals);
            foreach ($literals[1] as $literal) {
                foreach (explode('|', $literal) as $rule) {
                    $used[] = Str::before($rule, ':');
                }
            }
            preg_match_all('/Rule::([a-zA-Z]+)\(/', $segment, $objects);
            foreach ($objects[1] as $method) {
                $used[] = Str::snake($method);
            }
        }
    }

    return array_values(array_intersect($known, array_unique($used)));
}

it('reads the validation rules from the API code', function () {
    expect(validationRulesUsedByTheApi())->toContain('required', 'string', 'max', 'in', 'uuid', 'unique', 'date');
})->group('RNF-188');

it('translates every validation rule used by the API', function () {
    $missing = [];
    foreach (validationRulesUsedByTheApi() as $rule) {
        $keys = in_array($rule, SIZED_RULES, true)
            ? array_map(fn (string $type): string => "validation.{$rule}.{$type}", ['array', 'file', 'numeric', 'string'])
            : ["validation.{$rule}"];
        foreach ($keys as $key) {
            if (! Lang::hasForLocale($key, 'es')) {
                $missing[] = $key;
            }
        }
    }

    expect($missing)->toBe([]);
})->group('RNF-188');

it('runs the API in Spanish of Peru by default', function () {
    expect(file_get_contents(base_path('.env.example')))->toMatch('/^APP_LOCALE=es\r?$/m');
})->group('RNF-188');

it('names the attributes in Spanish in the messages', function () {
    app()->setLocale('es');

    $errors = Validator::make(
        ['ruc' => 'x', 'discount_cap_pct' => 150, 'reason' => ''],
        ['ruc' => 'size:11', 'discount_cap_pct' => 'numeric|max:100', 'reason' => 'required'],
    )->errors();

    expect($errors->first('ruc'))->toBe('El campo RUC debe tener 11 caracteres.')
        ->and($errors->first('discount_cap_pct'))->toBe('El campo tope de descuento no debe ser mayor que 100.')
        ->and($errors->first('reason'))->toBe('El campo motivo es obligatorio.');
})->group('RNF-188');
