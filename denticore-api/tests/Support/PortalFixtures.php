<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cuentas de portal sintéticas (SDD §3.3; RES-08): el titular con su ficha vinculada y el
 * representante legal de un menor.
 */
final class PortalFixtures
{
    /**
     * Cuenta de portal (`role = patient`) vinculada a una ficha adulta, salvo que se indique otra
     * fecha de nacimiento.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: Patient}
     */
    public static function holder(Tenant $tenant, array $attributes = [], bool $withConsent = true): array
    {
        $user = User::factory()->for($tenant)->create(['role' => 'patient']);
        $patient = ClinicalFixtures::patient($tenant, $withConsent, ['user_id' => $user->id, ...$attributes]);

        return [$user, $patient];
    }

    /**
     * Menor de edad sin cuenta propia ni consentimiento: lo otorga su representante (RN-12).
     */
    public static function minor(Tenant $tenant, string $birthDate = '2016-03-14'): Patient
    {
        return ClinicalFixtures::patient($tenant, false, ['birth_date' => $birthDate]);
    }

    /**
     * Representante legal vigente del paciente, con su cuenta de portal (la de `$user` o una nueva).
     */
    public static function representative(Tenant $tenant, Patient $patient, ?User $user = null): User
    {
        $user ??= User::factory()->for($tenant)->create(['role' => 'patient']);

        TenantContext::run($tenant, fn () => DB::transaction(function () use ($patient, $user): void {
            $representative = app(LegalRepresentativeService::class)->register($patient, [
                'document_type' => 'dni',
                'document_number' => (string) fake()->unique()->numberBetween(40000000, 49999999),
                'first_name' => 'Rosa',
                'last_name' => 'Mamani Soto',
                'relationship' => 'madre',
                'phone' => '912345678',
                'email' => 'rosa@correo.test',
                'valid_from' => '2020-01-01',
            ]);
            $representative->forceFill(['user_id' => $user->id])->save();
        }));

        return $user;
    }

    /**
     * Hallazgo de `ClinicalFixtures::cariesFinding()` con nota en la pieza 36 del paciente,
     * registrado por su odontóloga (Dra. Pérez, COP 12345) en una atención nueva.
     *
     * @return string Uuid de la entrada.
     */
    public static function recordedFinding(Tenant $tenant, Patient $patient): string
    {
        ClinicalFixtures::cariesFinding();
        test()->actingAsRole('dentist', $tenant, ['name' => 'Dra. Pérez', 'cop_number' => '12345']);
        $attention = test()->postJson("/api/v1/patients/{$patient->uuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->json('data.id');

        return ClinicalFixtures::recordFinding(ClinicalFixtures::attention($tenant, $attention), ['note' => 'Lesión cavitada'])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Presupuesto emitido por una recepcionista para un plan `propuesto` del paciente, con un ítem
     * de S/ 150.00 (vence a los 30 días de la clínica).
     *
     * @return string Uuid del presupuesto.
     */
    public static function issuedBudget(Tenant $tenant, Patient $patient): string
    {
        test()->actingAsRole('receptionist', $tenant);
        $plan = TenantContext::run($tenant, function () use ($tenant, $patient): TreatmentPlan {
            $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
            $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id, 'status' => 'propuesto']);
            PlanItem::factory()->create([
                'tenant_id' => $tenant->id,
                'treatment_plan_id' => $plan->id,
                'procedure_id' => Procedure::factory()->create(['tenant_id' => $tenant->id, 'price' => '150.00'])->id,
            ]);

            return $plan;
        });

        $budget = test()->postJson("/api/v1/treatment-plans/{$plan->uuid}/budgets", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->json('data.id');
        test()->postJson("/api/v1/budgets/{$budget}/issue", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return $budget;
    }

    public static function representation(Tenant $tenant, Patient $patient): LegalRepresentative
    {
        return TenantContext::run($tenant, fn () => LegalRepresentative::query()->where('patient_id', $patient->id)->sole());
    }
}
