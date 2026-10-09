<?php

namespace App\Modules\Treatment\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Descuento de una línea del borrador (CUS-35 paso 3; RN-31): 0,00 a 100,00 %. El motivo (5 a 200
 * caracteres si el descuento es mayor que 0) y el tope de la clínica los aplica BudgetService.
 */
class BudgetLineRequest extends FormRequest
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
        return [
            'discount_pct' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'discount_reason' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['discount_pct' => 'descuento', 'discount_reason' => 'motivo del descuento'];
    }
}
