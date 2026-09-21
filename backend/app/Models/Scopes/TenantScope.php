<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Aísla los datos por tenant (technical_specs.md §2.3, regla de tolerancia cero).
 *
 * Si no hay un tenant activo resuelto en el contenedor ("currentTenant"), la query no
 * devuelve nada por defecto en lugar de devolver todos los tenants: el único bypass
 * permitido es explícito, vía Model::withoutTenantScope() (super_admin).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound('currentTenant')) {
            $builder->where($model->qualifyColumn('tenant_id'), app('currentTenant')->id);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
