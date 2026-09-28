<?php

namespace App\Support\Database;

use Illuminate\Support\Str;

/**
 * Genera el uuid público en PHP antes del INSERT (SDD §1.7, DI-02) para que el modelo
 * en memoria lo tenga tras crearse; el default de columna gen_random_uuid() queda como
 * respaldo para inserciones que no pasen por Eloquent.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
