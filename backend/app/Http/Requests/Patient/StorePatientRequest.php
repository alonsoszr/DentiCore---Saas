<?php

namespace App\Http\Requests\Patient;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * technical_specs.md §3.4 / §5.3. Los límites de longitud se aplican sobre el valor en
 * claro (las columnas cifradas son `text`). La unicidad del DNI y la validez de
 * user_uuid se comprueban en PatientService (requieren el índice ciego y el tenant).
 */
class StorePatientRequest extends FormRequest
{
    /**
     * La autorización por rol la aplica EnsureRole en la ruta.
     */
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
            'document_id' => ['required', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:180'],
            'medical_history' => ['nullable', 'array'],
            'user_uuid' => ['nullable', 'uuid'],
        ];
    }
}
