<?php

namespace App\Modules\Treatment\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición del plan de tratamiento (CUS-33; RF-110). El alta admite sus primeros ítems;
 * la edición, solo el título (los ítems tienen sus propias rutas).
 */
class TreatmentPlanRequest extends FormRequest
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
        if (! $this->isMethod('post')) {
            return ['title' => ['sometimes', 'string', 'max:150']];
        }

        return [
            'title' => ['required', 'string', 'max:150'],
            'items' => ['sometimes', 'array'],
            ...PlanItemsRequest::itemRules('items.*.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'título', ...PlanItemsRequest::itemAttributes('items.*.')];
    }
}
