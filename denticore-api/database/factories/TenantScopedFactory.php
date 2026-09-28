<?php

namespace Database\Factories;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Base de las fábricas de modelos de clínica (BelongsToTenant): cada registro se guarda
 * dentro del contexto de su propia clínica, igual que en una solicitud real (SDD §6.2:
 * fábricas con clínica explícita).
 *
 * @template TModel of Model
 *
 * @extends Factory<TModel>
 */
abstract class TenantScopedFactory extends Factory
{
    /**
     * @param  Collection<int, TModel>  $results
     */
    protected function store(Collection $results)
    {
        $results->each(function (Model $model): void {
            TenantContext::run(
                (int) $model->getAttribute('tenant_id'),
                fn () => parent::store(new EloquentCollection([$model])),
            );
        });
    }
}
