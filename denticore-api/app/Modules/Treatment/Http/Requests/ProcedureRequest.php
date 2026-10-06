<?php

namespace App\Modules\Treatment\Http\Requests;

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Treatment\Models\Procedure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de un procedimiento del catálogo (CUS-32; RF-107, RN-26, RN-39, RN-76). En la
 * edición los campos son opcionales; las reglas que combinan campos usan el valor nuevo o, si no
 * llega, el guardado.
 */
class ProcedureRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $procedure = $this->route('procedure');

        return [
            'code' => [$required, 'string', 'max:30', Rule::unique('procedure_catalog', 'code')
                ->where('tenant_id', TenantContext::id())
                ->ignore($procedure instanceof Procedure ? $procedure->id : null)],
            'name' => [$required, 'string', 'max:150'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'price' => [$required, 'numeric', 'decimal:0,2', 'min:0', 'max:99999.99'],
            'requires_tooth' => [$required, 'boolean'],
            'requires_surface' => [$required, 'boolean'],
            'requires_informed_consent' => ['sometimes', 'boolean'],
            'resulting_finding_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'resulting_state_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * RN-26: superficie exige pieza. RN-39: el hallazgo resultante es del catálogo NTS 188 vigente
     * y su estado, activo y del mismo hallazgo.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $procedure = $this->route('procedure');
            $current = fn (string $field) => $this->has($field)
                ? $this->boolean($field)
                : ($procedure instanceof Procedure ? (bool) $procedure->getAttribute($field) : false);

            if ($current('requires_surface') && ! $current('requires_tooth')) {
                $validator->errors()->add('requires_surface', 'Un procedimiento que requiere superficie también requiere pieza.');
            }

            $findingCode = $this->input('resulting_finding_code');
            if (! is_string($findingCode) || $findingCode === '') {
                return;
            }

            $finding = FindingCatalog::query()->where('code', $findingCode)->where('is_active', true)->first();
            if ($finding === null) {
                $validator->errors()->add('resulting_finding_code', 'El hallazgo no pertenece al catálogo NTS 188 vigente.');

                return;
            }

            $stateCode = $this->input('resulting_state_code');
            $exists = is_string($stateCode) && $finding->states()->where('code', $stateCode)->where('is_active', true)->exists();
            if (! $exists) {
                $validator->errors()->add('resulting_state_code', 'Elija un estado vigente del hallazgo resultante.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nombre',
            'category' => 'categoría',
            'price' => 'precio',
            'requires_tooth' => 'requiere pieza',
            'requires_surface' => 'requiere superficie',
            'requires_informed_consent' => 'requiere consentimiento informado',
            'resulting_finding_code' => 'hallazgo resultante',
            'resulting_state_code' => 'estado del hallazgo resultante',
            'is_active' => 'activo',
        ];
    }
}
