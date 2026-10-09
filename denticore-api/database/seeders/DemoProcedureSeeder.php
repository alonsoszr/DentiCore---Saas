<?php

namespace Database\Seeders;

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Procedure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Catálogo de procedimientos de demostración de la «Clínica Demo» (solo entorno local; datos
 * sintéticos, RES-08): una restauración y una extracción con hallazgo resultante NTS 188 y una
 * profilaxis sin pieza. Es idempotente: se puede correr sobre una base ya sembrada con
 * `php artisan db:seed --class=DemoProcedureSeeder`.
 */
class DemoProcedureSeeder extends Seeder
{
    /**
     * Código => [nombre, categoría, precio, pieza, superficie, consentimiento informado, hallazgo y estado resultantes].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: bool, 6: string|null, 7: string|null}>
     */
    private const PROCEDURES = [
        'RES-01' => ['Restauración con resina', 'Operatoria', '150.00', true, true, false, 'RESTAURACION', 'R_BUENO'],
        'EXO-01' => ['Extracción simple', 'Cirugía', '120.00', true, false, true, 'AUSENTE', 'DEX'],
        'PRF-01' => ['Profilaxis', 'Prevención', '80.00', false, false, false, null, null],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Los datos de demostración solo se cargan en entorno local.');
        }

        $clinic = Tenant::query()->where('slug', 'clinica-demo')->first();

        if ($clinic === null) {
            return;
        }

        TenantContext::run($clinic, function () use ($clinic): void {
            foreach (self::PROCEDURES as $code => [$name, $category, $price, $tooth, $surface, $consent, $findingCode, $stateCode]) {
                $finding = $findingCode === null ? null : FindingCatalog::query()->where('code', $findingCode)->first();

                $procedure = Procedure::query()->firstOrNew(['code' => $code]);
                $procedure->forceFill([
                    'tenant_id' => $clinic->id,
                    'name' => $name,
                    'category' => $category,
                    'price' => $price,
                    'requires_tooth' => $tooth,
                    'requires_surface' => $surface,
                    'requires_informed_consent' => $consent,
                    'resulting_finding_id' => $finding?->id,
                    'resulting_finding_state_id' => $finding?->states()->where('code', $stateCode)->value('id'),
                    'is_active' => true,
                ])->save();
            }
        });
    }
}
