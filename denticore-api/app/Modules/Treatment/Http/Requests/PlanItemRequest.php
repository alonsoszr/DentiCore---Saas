<?php

namespace App\Modules\Treatment\Http\Requests;

use App\Modules\Treatment\Rules\ActiveProcedure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de un ítem `propuesto` del plan en borrador (CUS-33; RF-110, RN-26). Los campos son
 * opcionales; la pieza y las superficies se validan con el valor nuevo o el guardado.
 */
class PlanItemRequest extends FormRequest
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
            'procedure_id' => ['sometimes', 'string', new ActiveProcedure],
            'tooth' => ['sometimes', 'nullable', 'integer'],
            'surfaces' => ['sometimes', 'nullable', 'array', 'max:7'],
            'surfaces.*' => ['string', 'size:1'],
            'quantity' => ['sometimes', 'integer', 'between:1,32'],
            'session_number' => ['sometimes', 'nullable', 'integer', 'between:1,32767'],
            'observations' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PlanItemsRequest::itemAttributes('');
    }
}
