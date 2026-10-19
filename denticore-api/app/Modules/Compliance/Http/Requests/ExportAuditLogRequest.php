<?php

namespace App\Modules\Compliance\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Exportación CSV de la bitácora (CUS-66, RF-187): los mismos filtros, con el rango de fechas
 * obligatorio y de 31 días como máximo, porque el CSV se genera en la respuesta.
 */
class ExportAuditLogRequest extends AuditLogFilterRequest
{
    public const MAX_DAYS = 31;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['per_page']);

        return [
            ...$rules,
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $days = CarbonImmutable::parse((string) $this->input('from'))->diffInDays(CarbonImmutable::parse((string) $this->input('to'))) + 1;

            if ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'El rango a exportar es de '.self::MAX_DAYS.' días como máximo.');
            }
        }];
    }
}
