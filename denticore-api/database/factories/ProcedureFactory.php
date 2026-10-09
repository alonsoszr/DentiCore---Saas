<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Procedure;

/**
 * Procedimiento sintético activo del catálogo de la clínica (RES-08).
 *
 * @extends TenantScopedFactory<Procedure>
 */
class ProcedureFactory extends TenantScopedFactory
{
    /**
     * @var class-string<Procedure>
     */
    protected $model = Procedure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'PROC-'.fake()->unique()->numerify('#####'),
            'name' => 'Procedimiento de prueba '.fake()->unique()->numerify('###'),
            'category' => null,
            'price' => '150.00',
            'requires_tooth' => true,
            'requires_surface' => false,
            'requires_informed_consent' => false,
            'is_active' => true,
        ];
    }
}
