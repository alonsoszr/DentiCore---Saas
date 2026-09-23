<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Encryption\TenantEncryption;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Alta de clínicas (technical_specs.md §5.1). La clínica, su clave de cifrado
 * (§2.3 punto 4) y su primer clinic_admin se crean en la misma transacción: una
 * clínica nunca existe sin clave ni sin alguien que la administre.
 */
class TenantService
{
    public function __construct(private TenantEncryption $encryption) {}

    /**
     * @param  array{name: string, slug: string, subscription_plan: string, settings?: array<string, mixed>|null}  $attributes
     * @param  array{name: string, email: string, password: string}  $admin
     */
    public function create(array $attributes, array $admin): Tenant
    {
        return DB::transaction(function () use ($attributes, $admin): Tenant {
            $tenant = Tenant::create($attributes);

            $this->encryption->generateKeyFor($tenant);

            // Solo estos tres campos del payload: el rol y la clínica los fija el servidor.
            $clinicAdmin = new User([...Arr::only($admin, ['name', 'email', 'password']), 'role' => 'clinic_admin']);
            $clinicAdmin->tenant_id = $tenant->id;
            $clinicAdmin->save();

            return $tenant;
        });
    }
}
