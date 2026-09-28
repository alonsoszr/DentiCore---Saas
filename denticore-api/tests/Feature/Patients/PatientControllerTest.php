<?php

namespace Tests\Feature\Patients;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Pacientes heredados de las fases 0–3 (CUS-13, CUS-14) y acceso a /patients/{patient}.
 */
class PatientControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'document_id' => '45678912',
            'first_name' => 'Ana',
            'last_name' => 'Quispe',
            'birth_date' => '1990-05-10',
            'phone' => '987654321',
            'email' => 'ana@example.com',
            'medical_history' => ['alergias' => ['penicilina']],
        ], $overrides);
    }

    public function test_user_cannot_access_patient_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $dentistA = User::factory()->for($tenantA)->create(['role' => 'dentist']);
        $patientB = Patient::factory()->for($tenantB)->create();

        $this->actingAs($dentistA, 'sanctum')
            ->getJson("/api/v1/patients/{$patientB->uuid}")
            ->assertNotFound();
    }

    public function test_staff_can_view_patient_of_own_clinic_decrypted(): void
    {
        $tenant = Tenant::factory()->create();
        $receptionist = User::factory()->for($tenant)->create(['role' => 'receptionist']);
        $patient = Patient::factory()->for($tenant)->create(['document_id' => '11223344', 'phone' => '911222333']);

        $this->actingAs($receptionist, 'sanctum')
            ->getJson("/api/v1/patients/{$patient->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $patient->uuid)
            ->assertJsonPath('data.document_id', '11223344')
            ->assertJsonPath('data.phone', '911222333')
            ->assertJsonMissingPath('data.document_id_hash');
    }

    public function test_index_lists_only_own_clinic_patients_paginated(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = User::factory()->for($tenantA)->create(['role' => 'clinic_admin']);
        Patient::factory()->for($tenantA)->count(16)->create();
        Patient::factory()->for($tenantB)->count(3)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/patients')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 16);
    }

    public function test_staff_can_register_patient_and_tenant_comes_from_session(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

        $response = $this->actingAs($dentist, 'sanctum')
            ->postJson('/api/v1/patients', $this->payload(['tenant_id' => $otherTenant->id]));

        $response->assertCreated()
            ->assertJsonPath('data.document_id', '45678912')
            ->assertJsonPath('data.medical_history.alergias.0', 'penicilina');

        $patient = TenantContext::run($tenant, fn () => Patient::query()->where('uuid', $response->json('data.id'))->sole());
        $this->assertSame($tenant->id, $patient->tenant_id);
    }

    public function test_document_id_is_unique_per_clinic_but_reusable_across_clinics(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $receptionistA = User::factory()->for($tenantA)->create(['role' => 'receptionist']);
        $receptionistB = User::factory()->for($tenantB)->create(['role' => 'receptionist']);

        $this->actingAs($receptionistA, 'sanctum')->postJson('/api/v1/patients', $this->payload())->assertCreated();

        $this->actingAs($receptionistA, 'sanctum')
            ->postJson('/api/v1/patients', $this->payload(['document_id' => ' 45678912 ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_id');

        $this->actingAs($receptionistB, 'sanctum')->postJson('/api/v1/patients', $this->payload())->assertCreated();
    }

    public function test_patient_role_and_super_admin_cannot_list_or_register_patients(): void
    {
        $tenant = Tenant::factory()->create();
        $patientUser = User::factory()->for($tenant)->create(['role' => 'patient']);
        $superAdmin = User::factory()->superAdmin()->create();

        foreach ([$patientUser, $superAdmin] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/patients')->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/patients', $this->payload())->assertForbidden();
        }
    }

    public function test_patient_can_only_view_own_records(): void
    {
        $tenant = Tenant::factory()->create();
        $patientUser = User::factory()->for($tenant)->create(['role' => 'patient']);
        $ownRecord = Patient::factory()->for($tenant)->create();
        TenantContext::run($tenant, function () use ($ownRecord, $patientUser) {
            $ownRecord->user_id = $patientUser->id;
            $ownRecord->save();
        });
        $otherRecord = Patient::factory()->for($tenant)->create();

        $this->actingAs($patientUser, 'sanctum')
            ->getJson("/api/v1/patients/{$ownRecord->uuid}")
            ->assertOk();

        $this->actingAs($patientUser, 'sanctum')
            ->getJson("/api/v1/patients/{$otherRecord->uuid}")
            ->assertForbidden();
    }

    public function test_patient_can_be_linked_to_portal_account_of_same_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
        $portalUser = User::factory()->for($tenant)->create(['role' => 'patient']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/patients', $this->payload(['user_uuid' => $portalUser->uuid]))
            ->assertCreated()
            ->assertJsonPath('data.user_uuid', $portalUser->uuid);

        // La misma cuenta no puede vincularse a una segunda ficha.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/patients', $this->payload(['document_id' => '99887766', 'user_uuid' => $portalUser->uuid]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_uuid');
    }

    public function test_portal_account_must_be_patient_role_of_same_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
        $foreignPatientUser = User::factory()->for($otherTenant)->create(['role' => 'patient']);

        foreach ([$dentist, $foreignPatientUser] as $candidate) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/v1/patients', $this->payload(['user_uuid' => $candidate->uuid]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('user_uuid');
        }
    }

    public function test_portal_account_receives_its_patient_uuid_on_login_and_me(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'clinica-sonrisa']);
        $patientUser = User::factory()->for($tenant)->create(['role' => 'patient', 'email' => 'pablo@example.com']);
        $record = Patient::factory()->for($tenant)->create();
        TenantContext::run($tenant, function () use ($record, $patientUser) {
            $record->user_id = $patientUser->id;
            $record->save();
        });

        $this->postJson('/api/v1/auth/login', [
            'tenant_slug' => 'clinica-sonrisa',
            'email' => 'pablo@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.patient_uuid', $record->uuid);

        $this->actingAs($patientUser, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.patient_uuid', $record->uuid);
    }

    public function test_unlinked_portal_account_gets_null_and_staff_gets_no_patient_uuid(): void
    {
        $tenant = Tenant::factory()->create();
        $unlinkedPatientUser = User::factory()->for($tenant)->create(['role' => 'patient']);
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

        $this->actingAs($unlinkedPatientUser, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.patient_uuid', null);

        $this->actingAs($dentist, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.patient_uuid');
    }

    public function test_medical_history_is_stored_with_fixed_structure(): void
    {
        $tenant = Tenant::factory()->create();
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

        $response = $this->actingAs($dentist, 'sanctum')->postJson('/api/v1/patients', $this->payload([
            'medical_history' => ['alergias' => [' Penicilina ', ''], 'observaciones' => '  Hipertenso  '],
        ]));

        $response->assertCreated()->assertJsonPath('data.medical_history', [
            'alergias' => ['Penicilina'],
            'enfermedades' => [],
            'medicamentos' => [],
            'observaciones' => 'Hipertenso',
        ]);
    }

    public function test_empty_medical_history_is_stored_as_null(): void
    {
        $tenant = Tenant::factory()->create();
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

        $this->actingAs($dentist, 'sanctum')->postJson('/api/v1/patients', $this->payload([
            'medical_history' => ['alergias' => [], 'enfermedades' => [''], 'medicamentos' => [], 'observaciones' => ''],
        ]))->assertCreated()->assertJsonPath('data.medical_history', null);
    }

    public function test_medical_history_rejects_keys_outside_the_structure(): void
    {
        $tenant = Tenant::factory()->create();
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

        $this->actingAs($dentist, 'sanctum')
            ->postJson('/api/v1/patients', $this->payload(['medical_history' => ['cirugias' => ['Apendicectomía']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medical_history');
    }
}
