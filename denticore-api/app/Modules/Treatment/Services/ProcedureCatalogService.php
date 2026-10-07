<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Treatment\Models\Procedure;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de procedimientos de la clínica (SDD §2.8; CUS-32; RF-107, RF-109, RN-33). Los
 * presupuestos emitidos guardan su propio precio, así que editar el catálogo no los altera (RN-33).
 */
class ProcedureCatalogService
{
    /**
     * @param  array<string, mixed>  $data  Validado por ProcedureRequest.
     */
    public function create(array $data): Procedure
    {
        return DB::transaction(function () use ($data): Procedure {
            $procedure = new Procedure;
            $procedure->forceFill([
                'requires_informed_consent' => false,
                'is_active' => true,
                ...$this->attributes($data),
            ])->save();

            return $procedure;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validado por ProcedureRequest.
     */
    public function update(Procedure $procedure, array $data): Procedure
    {
        return DB::transaction(function () use ($procedure, $data): Procedure {
            $procedure->forceFill($this->attributes($data))->save();

            return $procedure;
        });
    }

    /**
     * RF-109: un procedimiento usado en planes o presupuestos no se elimina, solo se desactiva.
     *
     * @throws BusinessRuleException
     */
    public function delete(Procedure $procedure): void
    {
        DB::transaction(function () use ($procedure): void {
            $used = DB::table('plan_items')->where('procedure_id', $procedure->id)->exists()
                || DB::table('budget_lines')->where('procedure_id', $procedure->id)->exists();

            if ($used) {
                throw new BusinessRuleException(
                    'RF-109',
                    'El procedimiento se usó en planes o presupuestos: no se puede eliminar, solo desactivar.',
                    status: 409,
                );
            }

            // Su vínculo con plantillas de consentimiento informado es configuración, no uso.
            DB::table('procedure_informed_consent_template')->where('procedure_id', $procedure->id)->delete();
            $procedure->delete();
        });
    }

    /**
     * Columnas del procedimiento a partir de los datos validados; el hallazgo y el estado
     * resultantes llegan por su código del catálogo NTS 188 (RN-39).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'code', 'name', 'category', 'price', 'requires_tooth', 'requires_surface', 'requires_informed_consent', 'is_active',
        ]));

        if (array_key_exists('resulting_finding_code', $data)) {
            $finding = $data['resulting_finding_code'] === null
                ? null
                : FindingCatalog::query()->where('code', $data['resulting_finding_code'])->first();

            $attributes['resulting_finding_id'] = $finding?->id;
            $attributes['resulting_finding_state_id'] = $finding?->states()->where('code', $data['resulting_state_code'] ?? '')->value('id');
        }

        return $attributes;
    }
}
