<?php

namespace App\Services;

use App\Models\Tenant;
use App\Services\Encryption\TenantEncryption;
use Illuminate\Support\Facades\DB;

/**
 * Alta de clínicas (technical_specs.md §5.1). La clínica y su clave de cifrado
 * (§2.3 punto 4) se crean en la misma transacción: no puede existir una sin la otra.
 */
class TenantService
{
    public function __construct(private TenantEncryption $encryption) {}

    /**
     * @param  array{name: string, slug: string, subscription_plan: string, settings?: array<string, mixed>|null}  $attributes
     */
    public function create(array $attributes): Tenant
    {
        return DB::transaction(function () use ($attributes): Tenant {
            $tenant = Tenant::create($attributes);

            $this->encryption->generateKeyFor($tenant);

            return $tenant;
        });
    }
}
