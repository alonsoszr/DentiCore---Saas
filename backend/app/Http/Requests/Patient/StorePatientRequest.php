<?php

namespace App\Http\Requests\Patient;

use App\Models\Patient;
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
            // Estructura fija confirmada con el usuario (el SDD solo dice "estructurados").
            'medical_history' => ['nullable', 'array:'.implode(',', Patient::MEDICAL_HISTORY_KEYS)],
            'medical_history.alergias' => ['sometimes', 'array'],
            'medical_history.alergias.*' => ['nullable', 'string', 'max:150'],
            'medical_history.enfermedades' => ['sometimes', 'array'],
            'medical_history.enfermedades.*' => ['nullable', 'string', 'max:150'],
            'medical_history.medicamentos' => ['sometimes', 'array'],
            'medical_history.medicamentos.*' => ['nullable', 'string', 'max:150'],
            'medical_history.observaciones' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'user_uuid' => ['nullable', 'uuid'],
        ];
    }
}
