<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Patients\Models\InformedConsentTemplate;
use App\Modules\Patients\Services\InformedConsentTemplateService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Procedure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Catálogo de procedimientos de demostración de la «Clínica Demo» (solo entorno local; datos
 * sintéticos, RES-08): una restauración y una extracción con hallazgo resultante NTS 188 y una
 * profilaxis sin pieza, y la plantilla de consentimiento informado de la extracción (CUS-82), que
 * lo exige. Es idempotente: se puede correr sobre una base ya sembrada con
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

    /** Texto sintético con los campos que completa InformedConsentRenderer (RF-072). */
    private const EXTRACTION_TEMPLATE = <<<'TEXT'
        Yo, {{paciente}}, autorizo al odontólogo {{odontologo}} a realizar el procedimiento {{procedimiento}} en la pieza {{pieza}}.
        Se me informaron los riesgos: {{riesgos}}.
        Se me informaron las alternativas: {{alternativas}}.
        Puedo revocar este consentimiento antes de que se realice el procedimiento.
        TEXT;

    public function run(InformedConsentTemplateService $templates): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Los datos de demostración solo se cargan en entorno local.');
        }

        $clinic = Tenant::query()->where('slug', 'clinica-demo')->first();

        if ($clinic === null) {
            return;
        }

        TenantContext::run($clinic, function () use ($clinic, $templates): void {
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

            $this->seedExtractionTemplate($clinic, $templates);
        });
    }

    /** Plantilla activa de EXO-01: sin ella su firma respondería 422 (RF-073). */
    private function seedExtractionTemplate(Tenant $clinic, InformedConsentTemplateService $templates): void
    {
        $extraction = Procedure::query()->where('code', 'EXO-01')->firstOrFail();
        $hasTemplate = InformedConsentTemplate::query()
            ->where('is_active', true)
            ->whereHas('procedures', fn ($query) => $query->whereKey($extraction->id))
            ->exists();
        $admin = User::query()->where('tenant_id', $clinic->id)->where('role', 'clinic_admin')->first();

        if ($hasTemplate || $admin === null) {
            return;
        }

        $templates->create([
            'title' => 'Extracción dental',
            'body' => self::EXTRACTION_TEMPLATE,
            'procedures' => [$extraction->uuid],
        ], $admin);
    }
}
