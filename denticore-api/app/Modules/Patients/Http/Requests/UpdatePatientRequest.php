<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Services\PatientIdentity;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Actualización de la identificación y el contacto del paciente (CUS-15; RF-062, SRS §11.3). El
 * número de historia clínica no cambia (RN-79). El tipo y el número de documento van juntos.
 */
class UpdatePatientRequest extends FormRequest
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
        $today = ClinicClock::for(TenantContext::tenant())->now()->startOfDay();

        return [
            'document_type' => ['required_with:document_number', Rule::in(array_keys(PatientIdentity::DOCUMENT_PREFIXES))],
            'document_number' => ['required_with:document_type', 'string', PatientIdentity::documentNumberRule('document_type')],
            'first_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'last_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'birth_date' => ['sometimes', 'date_format:Y-m-d', "before_or_equal:{$today->toDateString()}", "after:{$today->subYears(121)->toDateString()}"],
            'sex' => ['sometimes', Rule::in(['femenino', 'masculino'])],
            'phone' => ['sometimes', 'string', 'regex:'.PatientIdentity::PHONE_PATTERN],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:180'],
            'address' => ['sometimes', 'nullable', 'string', 'min:5', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'document_type' => 'tipo de documento',
            'document_number' => 'número de documento',
            'first_name' => 'nombres',
            'last_name' => 'apellidos',
            'birth_date' => 'fecha de nacimiento',
            'sex' => 'sexo',
            'phone' => 'teléfono',
            'address' => 'dirección',
        ];
    }
}
