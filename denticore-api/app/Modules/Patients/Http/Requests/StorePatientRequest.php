<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Patients\Services\PatientIdentity;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de paciente (CUS-14; contrato de SDD §4.5 y datos de SRS §11.3). La fecha de nacimiento no
 * puede ser posterior a hoy ni dar más de 120 años (fecha de la clínica). Un menor de 18 años
 * debe traer su representante legal (RN-12, CA-14.4). La unicidad del documento la comprueba
 * PatientService con el índice ciego. `user_uuid` es heredado y se retira en TASK-038 (S-14).
 */
class StorePatientRequest extends FormRequest
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
        $today = $this->today();

        $rules = [
            'document_type' => ['required', Rule::in(array_keys(PatientIdentity::DOCUMENT_PREFIXES))],
            'document_number' => ['required', 'string', PatientIdentity::documentNumberRule('document_type')],
            'first_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_name' => ['required', 'string', 'min:1', 'max:100'],
            // Fecha civil (SDD §2.1): AAAA-MM-DD.
            'birth_date' => ['required', 'date_format:Y-m-d', "before_or_equal:{$today->toDateString()}", "after:{$today->subYears(121)->toDateString()}"],
            'sex' => ['required', Rule::in(['femenino', 'masculino'])],
            'phone' => ['required', 'string', 'regex:'.PatientIdentity::PHONE_PATTERN],
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'address' => ['nullable', 'string', 'min:5', 'max:200'],
            'representative' => [Rule::requiredIf(fn (): bool => $this->isMinor()), 'nullable', 'array'],
            'user_uuid' => ['nullable', 'uuid'],
        ];

        return $this->has('representative')
            ? [...$rules, ...LegalRepresentativeService::rules('representative.')]
            : $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'representative.required' => 'Un paciente menor de 18 años debe registrarse con su representante legal.',
            'birth_date.after' => 'La edad no puede superar los 120 años.',
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

    /**
     * RN-12: menor de 18 años a la fecha de la clínica.
     */
    public function isMinor(): bool
    {
        $birthDate = $this->input('birth_date');

        if (! is_string($birthDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) !== 1) {
            return false;
        }

        return CarbonImmutable::parse($birthDate)->addYears(18)->toDateString() > $this->today()->toDateString();
    }

    private function today(): CarbonImmutable
    {
        return ClinicClock::for(TenantContext::tenant())->now()->startOfDay();
    }
}
