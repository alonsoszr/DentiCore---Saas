<?php

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Encryption\TenantEncryption;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Clínicas de la plataforma (M01). La clínica, su clave de cifrado (SDD §1.7.1) y su
 * primer clinic_admin se crean en la misma transacción: una clínica nunca existe sin
 * clave ni sin alguien que la administre. Contrato heredado de las fases 0–3 (admin con
 * contraseña); TASK-023 lo reemplaza por el alta con invitación de CUS-01.
 */
class TenantService
{
    public function __construct(private TenantEncryption $encryption, private AuditLogger $audit) {}

    /**
     * @return Collection<int, Tenant>
     */
    public function list(): Collection
    {
        return Tenant::query()->latest()->get();
    }

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

            // Evento de plataforma (cadena de tenant_id nulo, SDD §2.12).
            $this->audit->record(AuditEvent::TenantCreated, $tenant);

            return $tenant;
        });
    }
}
