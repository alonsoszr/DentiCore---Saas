<?php

namespace App\Modules\Treatment\Http\Requests;

use App\Modules\Treatment\Rules\ActiveProcedure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ítems nuevos de un plan (CUS-33; RF-110, RF-111, RN-26): procedimiento activo del catálogo,
 * pieza, superficies, cantidad (1–32), sesión opcional, observaciones y hallazgos que atiende.
 * La pieza y las superficies según el procedimiento las valida ClinicalValidator en el servicio.
 */
class PlanItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['items' => ['required', 'array', 'min:1'], ...self::itemRules('items.*.')];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function itemRules(string $prefix): array
    {
        return [
            "{$prefix}procedure_id" => ['required', 'string', new ActiveProcedure],
            "{$prefix}tooth" => ['nullable', 'integer'],
            "{$prefix}surfaces" => ['nullable', 'array', 'max:7'],
            "{$prefix}surfaces.*" => ['string', 'size:1'],
            "{$prefix}quantity" => ['sometimes', 'integer', 'between:1,32'],
            "{$prefix}session_number" => ['nullable', 'integer', 'between:1,32767'],
            "{$prefix}observations" => ['nullable', 'string', 'max:500'],
            "{$prefix}finding_ids" => ['sometimes', 'array'],
            "{$prefix}finding_ids.*" => ['string', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::itemAttributes('items.*.');
    }

    /**
     * @return array<string, string>
     */
    public static function itemAttributes(string $prefix): array
    {
        return [
            "{$prefix}procedure_id" => 'procedimiento',
            "{$prefix}tooth" => 'pieza',
            "{$prefix}surfaces" => 'superficies',
            "{$prefix}quantity" => 'cantidad',
            "{$prefix}session_number" => 'número de sesión',
            "{$prefix}observations" => 'observaciones',
            "{$prefix}finding_ids" => 'hallazgos',
        ];
    }
}
