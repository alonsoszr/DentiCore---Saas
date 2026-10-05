<?php

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\InvitationService;
use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Platform\Models\SubscriptionPlan;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Encryption\TenantEncryption;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Clínicas de la plataforma (M01; SDD §5.13, CUS-01). El alta crea en una sola transacción la
 * clínica `activa`, sus parámetros, su clave de cifrado v1, sus secuencias de documentos y su
 * primer `clinic_admin` `pendiente_activacion`, oficial de datos, con la invitación encolada
 * (DD-22, RF-015). Cualquier fallo revierte todo (CA-01.3).
 */
class TenantService
{
    public function __construct(
        private TenantEncryption $encryption,
        private AuditLogger $audit,
        private InvitationService $invitations,
        private NotificationService $notifications,
    ) {}

    /**
     * RF-018: búsqueda por nombre, RUC o código y filtros por estado y plan.
     *
     * @param  array{q?: string|null, status?: string|null, plan?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Tenant::query()
            ->with('plan')
            ->withCount(['users as active_dentists_count' => fn (Builder $query) => $query
                ->where('role', 'dentist')->where('status', 'activo')])
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $search) => $search
                ->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('ruc', $term)
                ->orWhere('slug', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['plan'] ?? null, fn (Builder $query, string $plan) => $query->whereRelation('plan', 'code', $plan))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(string $uuid): Tenant
    {
        return Tenant::query()
            ->with('plan')
            ->withCount(['users as active_dentists_count' => fn (Builder $query) => $query
                ->where('role', 'dentist')->where('status', 'activo')])
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * @param  array{name: string, legal_name: string, ruc: string, slug: string, address: string, subscription_plan: string, admin: array{name: string, email: string}}  $data
     */
    public function create(array $data): Tenant
    {
        return DB::transaction(function () use ($data): Tenant {
            $plan = SubscriptionPlan::forCode($data['subscription_plan']);

            $tenant = Tenant::create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'],
                'ruc' => $data['ruc'],
                'slug' => $data['slug'],
                'address' => $data['address'],
                // `subscription_plan` heredado convive con la FK hasta TASK-038.
                'subscription_plan' => $plan->code,
                'subscription_plan_id' => $plan->id,
                'status' => 'activa',
            ]);

            $this->provision($tenant);

            // Sin contraseña: la define al activar su cuenta (DD-22). El rol y la clínica los
            // fija el servidor, nunca el payload.
            $admin = new User(['name' => $data['admin']['name'], 'email' => $data['admin']['email'], 'role' => 'clinic_admin']);
            $admin->tenant_id = $tenant->id;
            $admin->is_data_officer = true; // RF-047
            $admin->save();

            $this->invitations->send($admin);

            // Evento de plataforma (cadena de tenant_id nulo, SDD §2.12).
            $this->audit->record(AuditEvent::TenantCreated, $tenant);

            return $this->find($tenant->uuid);
        });
    }

    /**
     * @param  array{name?: string, legal_name?: string, ruc?: string, address?: string}  $data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data): Tenant {
            $tenant->fill($data)->save();

            return $this->find($tenant->uuid);
        });
    }

    /**
     * FA-1 de CUS-01: reenvía la invitación del administrador pendiente e invalida el enlace
     * anterior (RF-016).
     */
    public function resendInvitation(Tenant $tenant): void
    {
        $admin = $this->pendingAdmin($tenant);

        if ($admin === null) {
            throw new BusinessRuleException('RF-016', 'La clínica no tiene un administrador pendiente de activación.', status: 409);
        }

        $this->invitations->send($admin);
    }

    /**
     * CUS-02 (RF-019): suspende una clínica `activa` con motivo; sus usuarios quedan en solo
     * lectura (`tenant.writable`) y sus administradores reciben un correo.
     */
    public function suspend(Tenant $tenant, string $reason): Tenant
    {
        return $this->changeStatus($tenant, from: 'activa', to: 'suspendida', reason: $reason);
    }

    /**
     * CUS-02 (RF-019): reactiva una clínica `suspendida` con motivo.
     */
    public function reactivate(Tenant $tenant, string $reason): Tenant
    {
        return $this->changeStatus($tenant, from: 'suspendida', to: 'activa', reason: $reason);
    }

    /**
     * CUS-03: RF-022 rechaza un plan cuyo máximo de odontólogos es menor que los activos e indica
     * cuántos desactivar; RF-023 (funciones en lectura al bajar de plan) lo aplica `plan.feature`.
     */
    public function changePlan(Tenant $tenant, string $planCode): Tenant
    {
        return DB::transaction(function () use ($tenant, $planCode): Tenant {
            $plan = SubscriptionPlan::forCode($planCode);
            // La fila de la clínica serializa el cambio de plan con las altas y reactivaciones de
            // odontólogos (RN-08), que la bloquean igual.
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $activeDentists = User::query()
                ->where('tenant_id', $tenant->id)->where('role', 'dentist')->where('status', 'activo')
                ->count();

            if ($plan->max_dentists !== null && $activeDentists > $plan->max_dentists) {
                $excess = $activeDentists - $plan->max_dentists;

                throw new BusinessRuleException(
                    'RF-022',
                    "La clínica tiene {$activeDentists} odontólogos activos y el plan {$plan->name} permite {$plan->max_dentists}.",
                    ['subscription_plan' => ["Desactive {$excess} odontólogo(s) antes de cambiar al plan {$plan->name}."]],
                    extensions: ['dentists_to_deactivate' => $excess],
                );
            }

            $tenant->forceFill(['subscription_plan' => $plan->code, 'subscription_plan_id' => $plan->id])->save();
            $this->audit->record(AuditEvent::TenantPlanChanged, $tenant, ['subscription_plan_id']);

            return $this->find($tenant->uuid);
        });
    }

    private function changeStatus(Tenant $tenant, string $from, string $to, string $reason): Tenant
    {
        return DB::transaction(function () use ($tenant, $from, $to, $reason): Tenant {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if ($tenant->status !== $from) {
                throw new BusinessRuleException('RF-019', "La clínica está {$tenant->status}; solo se puede pasar a {$to} desde {$from}.", status: 409);
            }

            $tenant->forceFill([
                'status' => $to,
                'status_reason' => $reason,
                'suspended_at' => $to === 'suspendida' ? now() : null,
            ])->save();

            $this->audit->record($to === 'suspendida' ? AuditEvent::TenantSuspended : AuditEvent::TenantReactivated, $tenant, ['status']);

            // RF-019: correo a los administradores de la clínica.
            $event = $to === 'suspendida' ? NotificationEvent::ClinicaSuspendida : NotificationEvent::ClinicaReactivada;
            TenantContext::run($tenant, function () use ($tenant, $event, $reason): void {
                User::query()
                    ->where('tenant_id', $tenant->id)->where('role', 'clinic_admin')->where('status', '<>', 'inactivo')
                    ->orderBy('id')
                    ->each(fn (User $admin) => $this->notifications->sendEmail($event, $admin, ['reason' => $reason]));
            });

            return $this->find($tenant->uuid);
        });
    }

    public function pendingAdmin(Tenant $tenant): ?User
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'clinic_admin')
            ->where('status', 'pendiente_activacion')
            ->orderBy('id')
            ->first();
    }

    /**
     * Primer administrador de la clínica (el que se registró en el alta).
     */
    public function firstAdmin(Tenant $tenant): ?User
    {
        return User::query()->where('tenant_id', $tenant->id)->where('role', 'clinic_admin')->orderBy('id')->first();
    }

    /**
     * Lo que toda clínica tiene desde que existe: su clave de cifrado v1 (SDD §1.7.1), su fila
     * de `clinic_settings` con los valores por defecto (RF-015) y sus dos secuencias de
     * documentos en cero (DD-23). Debe llamarse dentro de la transacción del alta.
     */
    public function provision(Tenant $tenant): void
    {
        $this->encryption->generateKeyFor($tenant);

        TenantContext::run($tenant, function () use ($tenant): void {
            ClinicSetting::create();

            // `document_sequences` no tiene modelo hasta MS-03; la RLS exige el contexto.
            DB::table('document_sequences')->insert([
                ['tenant_id' => $tenant->id, 'doc_type' => 'presupuesto'],
                ['tenant_id' => $tenant->id, 'doc_type' => 'recibo'],
            ]);
        });
    }
}
