<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Aísla los datos por clínica (SDD §1.6.3; RN-02, RF-002). Sin clínica activa la consulta
 * no devuelve nada (deny-by-default); la única forma de omitirlo es explícita:
 * Model::withoutTenantScope().
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $id = TenantContext::id();

        $id === null
            ? $builder->whereRaw('1 = 0')
            : $builder->where($model->qualifyColumn('tenant_id'), $id);
    }
}
