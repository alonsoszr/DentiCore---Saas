<?php

namespace App\Modules\Treatment\Rules;

use App\Modules\Treatment\Models\Procedure;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RN-26: un ítem nuevo de plan solo referencia un procedimiento activo del catálogo de la clínica
 * (el valor es su uuid). Lo usan el plan (TASK-056) y la emisión del presupuesto; la búsqueda pasa
 * por el Global Scope, así que un procedimiento de otra clínica «no existe».
 */
class ActiveProcedure implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $procedure = is_string($value) ? Procedure::query()->where('uuid', $value)->first() : null;

        if ($procedure === null) {
            $fail('El procedimiento no existe en el catálogo de la clínica.');
        } elseif (! $procedure->is_active) {
            $fail('El procedimiento está inactivo: no puede agregarse a un plan.');
        }
    }
}
