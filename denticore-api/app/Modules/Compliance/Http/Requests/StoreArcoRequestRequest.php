<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Models\ArcoRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solicitud ARCO presentada en la clínica (CUS-63; RF-182): tipo, solicitante, descripción,
 * adjuntos y el documento del titular o del representante, revisado por el personal (SRS §11.15).
 */
class StoreArcoRequestRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(ArcoRequest::TYPES)],
            'requested_by' => ['required', 'string', Rule::in(['titular', 'representante'])],
            'legal_representative_id' => ['exclude_unless:requested_by,representante', 'required', 'uuid'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmation_document_number' => ['required', 'string', 'max:12'],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'requested_by' => 'solicitante',
            'legal_representative_id' => 'representante legal',
            'description' => 'descripción',
            'confirmation_document_number' => 'documento de confirmación',
            'files' => 'adjuntos',
            'files.*' => 'adjunto',
        ];
    }
}
