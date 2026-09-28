<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Models\Patient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de paciente (CUS-14, contrato heredado). Los límites de longitud se aplican sobre el valor en
 * claro (las columnas cifradas son `text`). La unicidad del DNI y la validez de
 * user_uuid se comprueban en PatientService (requieren el índice ciego y el tenant).
 */
class StorePatientRequest extends FormRequest
{
    /**
     * La autorización por rol la aplica `role:` en la ruta.
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
            // Fecha civil (SDD §2.1): AAAA-MM-DD.
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:180'],
            // Estructura de SDD §2.14.1.
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
