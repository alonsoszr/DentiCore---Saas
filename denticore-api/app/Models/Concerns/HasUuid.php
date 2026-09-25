<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Genera el uuid en PHP antes del INSERT para que el modelo en memoria lo tenga
 * disponible inmediatamente tras crear el registro (p. ej. en la respuesta de un
 * controlador). El default a nivel de columna (gen_random_uuid()) queda como
 * respaldo únicamente para inserts que no pasen por Eloquent.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
