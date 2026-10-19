<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Services\ArcoRequestService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolución de una solicitud ARCO (CUS-64, pasos 5 y 6; SRS §11.15 «Datos»): respuesta de 20 a
 * 5000 caracteres y, si se deniega, fundamento de 20 a 2000 (FE-2). La oposición atendida indica
 * las finalidades opcionales que revoca; la rectificación clínica, la corrección del odontólogo.
 */
class ResolveArcoRequestRequest extends FormRequest
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
        $request = $this->route('arcoRequest');
        $type = $request instanceof ArcoRequest ? $request->type : null;

        return [
            'status' => ['required', Rule::in(['atendida', 'denegada'])],
            'response' => ['required', 'string', 'min:20', 'max:5000'],
            'denial_reason' => ['required_if:status,denegada', 'nullable', 'string', 'min:20', 'max:2000'],
            'purposes' => $type === 'oposicion'
                ? ['required_if:status,atendida', 'array', 'min:1']
                : ['prohibited'],
            'purposes.*' => ['string', 'distinct', Rule::in(ArcoRequestService::OPTIONAL_PURPOSES)],
            'correction_entry_id' => $type === 'rectificacion' ? ['nullable', 'uuid'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'resolución',
            'response' => 'respuesta',
            'denial_reason' => 'fundamento de la denegación',
            'purposes' => 'finalidades',
            'purposes.*' => 'finalidad',
            'correction_entry_id' => 'corrección del odontograma',
        ];
    }
}
