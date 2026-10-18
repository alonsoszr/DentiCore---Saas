<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Models\Consent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Revocación de finalidades (CUS-18; RF-068, RF-069): una o más finalidades del consentimiento
 * vigente y un motivo opcional (`consent_purpose_revocations.reason`, 500).
 */
class RevokeConsentPurposesRequest extends FormRequest
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
            'purposes' => ['required', 'array', 'min:1', 'max:5'],
            'purposes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Consent::PURPOSES))],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['purposes' => 'finalidades', 'purposes.*' => 'finalidad', 'reason' => 'motivo'];
    }
}
