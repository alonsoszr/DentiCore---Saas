<?php

namespace App\Modules\Patients\Models;

use App\Modules\Patients\Policies\InformedConsentTemplatePolicy;
use App\Modules\Treatment\Models\Procedure;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Plantilla de consentimiento informado de procedimientos (SDD §2.5 `informed_consent_templates`;
 * CUS-82, RF-072). El cuerpo se versiona en `informed_consent_template_versions` (solo cambia la
 * versión cuando cambia el texto); `current_version` guarda el número de la vigente.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $title
 * @property bool $is_active
 * @property int|null $current_version
 * @property-read InformedConsentTemplateVersion|null $currentVersion
 * @property-read Collection<int, InformedConsentTemplateVersion> $versions
 * @property-read Collection<int, Procedure> $procedures
 */
#[Fillable(['title', 'is_active', 'current_version'])]
#[UsePolicy(InformedConsentTemplatePolicy::class)]
class InformedConsentTemplate extends Model
{
    use BelongsToTenant, HasUuid;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'current_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<InformedConsentTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(InformedConsentTemplateVersion::class);
    }

    /**
     * Versión vigente del cuerpo: `current_version` es el número, no una clave foránea.
     * Para comparar contra la columna de la plantilla hace falta el join (hasOne no une la tabla padre).
     *
     * @return HasOne<InformedConsentTemplateVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(InformedConsentTemplateVersion::class)
            ->join('informed_consent_templates', 'informed_consent_templates.id', '=', 'informed_consent_template_versions.informed_consent_template_id')
            ->whereColumn('informed_consent_template_versions.version', 'informed_consent_templates.current_version')
            ->select('informed_consent_template_versions.*');
    }

    /**
     * Procedimientos a los que se asocia la plantilla (SDD §2.5 `procedure_informed_consent_template`).
     *
     * @return BelongsToMany<Procedure, $this>
     */
    public function procedures(): BelongsToMany
    {
        return $this->belongsToMany(
            Procedure::class,
            'procedure_informed_consent_template',
            'informed_consent_template_id',
            'procedure_id',
        )->withPivot('tenant_id');
    }
}
