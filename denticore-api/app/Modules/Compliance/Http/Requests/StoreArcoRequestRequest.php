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
            // 5 MB por adjunto (un documento escaneado): dentro del máximo de 10 MB de RNF-103 y del
            // límite seguro de carga de SonarCloud (S5693, 8 000 000 bytes).
            'files.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
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
