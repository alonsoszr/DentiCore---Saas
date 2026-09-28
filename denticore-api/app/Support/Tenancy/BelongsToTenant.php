<?php

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SDD §1.6.3: Global Scope de aislamiento por clínica; tenant_id se asigna al crear desde
 * TenantContext, ignorando cualquier valor recibido (RN-01), y no puede cambiar después.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $model->tenant_id = TenantContext::idOrFail();
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new TenantMutationException;
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
