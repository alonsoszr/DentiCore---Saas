<?php

namespace App\Support\ReferenceData;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Services\PatientIdentity;
use App\Modules\Platform\Models\SubscriptionPlan;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\RucValidator;
use App\Modules\Platform\Services\TenantService;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Lotes sintéticos de TASK-072. Se conservan RLS, cifrado y los disparadores de evidencia.
 * Cada lote confirma las fichas y sus filas relacionadas en la misma transacción.
 */
final class ReferenceDatasetGenerator
{
    private const BATCH_SIZE = 250;

    public function __construct(
        private TenantService $tenants,
        private TenantEncryption $encryption,
        private ReferenceDatasetRows $rows,
    ) {}

    public function generate(ReferenceDatasetPlan $plan, string $password): void
    {
        $passwordHash = Hash::make($password);
        foreach ($plan->clinicSizes() as $offset => $size) {
            $clinic = $offset + 1;
            $tenant = $this->clinic($plan, $clinic, $size, $passwordHash);

            TenantContext::run($tenant, function () use ($tenant, $plan, $clinic, $size): void {
                foreach (array_chunk(range(1, $size), self::BATCH_SIZE) as $positions) {
                    DB::transaction(fn () => $this->batch($tenant, $plan, $clinic, $positions));
                }
            });
        }
    }

    private function clinic(ReferenceDatasetPlan $plan, int $clinic, int $size, string $passwordHash): Tenant
    {
        return DB::transaction(function () use ($plan, $clinic, $size, $passwordHash): Tenant {
            $slug = $plan->slug($clinic);
            $uuid = $plan->uuid($clinic, 'tenant');
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$slug]);
            $existing = Tenant::query()->where('slug', $slug)->first();
            if ($existing !== null) {
                if ($existing->uuid !== $uuid) {
                    throw new RuntimeException("El código {$slug} pertenece a una clínica ajena al CDR.");
                }

                return $existing;
            }

            $ruc = $plan->clinicRucBody($clinic);
            $tenant = new Tenant([
                'name' => "Clínica sintética {$slug}",
                'legal_name' => "Clínica sintética {$slug} S.A.C.",
                'ruc' => $ruc.RucValidator::checkDigit($ruc),
                'slug' => $slug,
                'address' => 'Dirección sintética',
                'subscription_plan_id' => SubscriptionPlan::forCode('enterprise')->id,
            ]);
            $tenant->forceFill(['uuid' => $uuid])->save();
            $this->tenants->provision($tenant);
            $this->createStaff($tenant, $plan, $clinic, $size === 20_000 ? 10 : 3, $passwordHash);
            $this->rows->createCatalogs($tenant);

            return $tenant;
        });
    }

    private function createStaff(Tenant $tenant, ReferenceDatasetPlan $plan, int $clinic, int $dentists, string $passwordHash): void
    {
        $roles = ['clinic_admin', 'receptionist', 'receptionist', ...array_fill(0, $dentists, 'dentist')];
        foreach ($roles as $index => $role) {
            $user = new User([
                'name' => "Personal sintético {$index}",
                'email' => "{$plan->slug($clinic)}-{$index}@example.test",
                'role' => $role,
                'password' => $passwordHash,
                'cop_number' => $role === 'dentist' ? (string) (100_000 + $clinic * 100 + $index) : null,
            ]);
            $user->forceFill(['tenant_id' => $tenant->id, 'uuid' => $plan->uuid($clinic, "staff:{$index}")])->save();
        }
    }

    /** @param list<int> $positions */
    private function batch(Tenant $tenant, ReferenceDatasetPlan $plan, int $clinic, array $positions): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$plan->slug($clinic)]);
        $uuids = array_map(fn (int $position): string => $plan->uuid($clinic, "patient:{$position}"), $positions);
        $existing = DB::table('patients')->where('tenant_id', $tenant->id)->whereIn('uuid', $uuids)->count();
        if ($existing === count($positions)) {
            return;
        }
        if ($existing !== 0) {
            throw new RuntimeException("El lote de {$plan->slug($clinic)} tiene un conjunto parcial de fichas.");
        }

        $author = User::query()->where('tenant_id', $tenant->id)->where('role', 'receptionist')->orderBy('id')->firstOrFail();
        $date = $tenant->created_at?->toDateString() ?? now()->toDateString();
        $records = [];
        foreach ($positions as $index => $position) {
            $document = sprintf('CDR%08d', $position);
            $record = PatientIdentity::clinicalRecordNumber('pasaporte', $document);
            $surname = sprintf('Ficha %05d', $position);
            $records[] = [
                'uuid' => $uuids[$index],
                'tenant_id' => $tenant->id,
                'document_type' => 'pasaporte',
                'document_number' => $this->encryption->encrypt($tenant->id, $document),
                'document_hash' => $this->encryption->blindIndex($tenant->id, PatientIdentity::normalizedDocument('pasaporte', $document)),
                'clinical_record_number' => $this->encryption->encrypt($tenant->id, $record),
                'clinical_record_hash' => $this->encryption->blindIndex($tenant->id, $record),
                'first_name' => 'Paciente sintético',
                'last_name' => $surname,
                'search_name' => PatientIdentity::searchName('Paciente sintético', $surname),
                'birth_date' => sprintf('%04d-01-01', 1940 + $position % 65),
                'sex' => $position % 2 ? 'femenino' : 'masculino',
                'created_by' => $author->id,
                'created_at' => $date,
            ];
        }

        DB::table('patients')->insert($records);
        /** @var list<int> $ids */
        $ids = DB::table('patients')->where('tenant_id', $tenant->id)->whereIn('uuid', $uuids)->orderBy('id')->pluck('id')->all();
        $this->rows->generate($tenant, $ids, $date, $author->id, $positions[0] - 1);
    }
}
