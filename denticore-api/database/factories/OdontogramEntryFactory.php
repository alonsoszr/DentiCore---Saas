<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Hallazgo de evolución registrado por el odontólogo de la atención, con un hallazgo y un
 * estado sintéticos del catálogo (RES-08). El color se copia del estado (RN-17).
 *
 * @extends TenantScopedFactory<OdontogramEntry>
 */
class OdontogramEntryFactory extends TenantScopedFactory
{
    /**
     * @var class-string<OdontogramEntry>
     */
    protected $model = OdontogramEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'attention_id' => fn (array $attributes) => Attention::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'patient_id' => fn (array $attributes) => $this->attentionValue($attributes, 'patient_id'),
            'chain_patient_id' => fn (array $attributes) => $attributes['patient_id'],
            'initial_odontogram_id' => null,
            'entry_type' => 'evolucion',
            'tooth' => 16,
            'tooth_end' => null,
            'surfaces' => ['O'],
            'finding_id' => FindingCatalog::factory(),
            'finding_state_id' => fn (array $attributes) => $attributes['finding_id'] === null
                ? null
                : FindingState::factory()->create(['finding_id' => $attributes['finding_id']])->id,
            'color' => fn (array $attributes) => $attributes['finding_state_id'] === null
                ? null
                : FindingState::query()->whereKey($attributes['finding_state_id'])->value('color'),
            'origin' => 'manual',
            'author_id' => fn (array $attributes) => $this->attentionValue($attributes, 'dentist_id'),
            'author_cop' => fn (array $attributes) => User::query()->whereKey($attributes['author_id'])->value('cop_number'),
            'recorded_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attentionValue(array $attributes, string $column): mixed
    {
        return TenantContext::run(
            $attributes['tenant_id'],
            fn () => Attention::query()->whereKey($attributes['attention_id'])->value($column),
        );
    }
}
