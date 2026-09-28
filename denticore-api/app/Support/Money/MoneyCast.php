<?php

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Columna `numeric(12,2)` ↔ Money (SDD §2.1, DI-05). En la BD y en JSON el importe es la
 * cadena decimal con 2 decimales ("1234.56"), nunca un float.
 *
 * @implements CastsAttributes<Money|null, Money|string|int|null>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::of((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) Money::of($value);
    }
}
