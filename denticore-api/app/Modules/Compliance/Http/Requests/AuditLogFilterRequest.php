<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Support\Audit\AuditEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros de la bitácora (CUS-66, RF-187): fecha local desde y hasta, usuario, acción del catálogo
 * `AuditEvent` y recurso (tipo y uuid). En la plataforma, además, la clínica.
 */
class AuditLogFilterRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'uuid'],
            'action' => ['nullable', Rule::in(array_map(fn (AuditEvent $event) => $event->value, AuditEvent::cases()))],
            'resource_type' => ['nullable', 'string', 'max:60'],
            'resource_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ...($this->is('api/v1/platform/*') ? ['clinic_id' => ['nullable', 'uuid']] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'desde',
            'to' => 'hasta',
            'user_id' => 'usuario',
            'action' => 'acción',
            'resource_type' => 'tipo de recurso',
            'resource_id' => 'recurso',
            'clinic_id' => 'clínica',
        ];
    }
}
