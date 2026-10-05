<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Encryption\TenantEncryption;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ficha del paciente (M03; CUS-14, CUS-15; RF-055 a RF-059, RF-062, RN-09, RN-12, RN-79).
 * Patient lleva Global Scope, así que las consultas quedan acotadas a la clínica activa.
 */
class PatientService
{
    /** Campos de identificación y contacto editables (CUS-15). El número de HC no cambia. */
    public const IDENTITY_FIELDS = ['document_type', 'document_number', 'first_name', 'last_name', 'birth_date', 'sex', 'phone', 'email', 'address'];

    public function __construct(
        private TenantEncryption $encryption,
        private AuditLogger $audit,
        private LegalRepresentativeService $representatives,
        private PatientSearchRepository $search,
    ) {}

    /**
     * CUS-13 (RF-054, RF-010): los ids salen de {@see PatientSearchRepository} (índice de
     * trigramas, `tenant_id` explícito) y las fichas se cargan con la conexión de la API, bajo RLS
     * y el Global Scope, conservando el orden de la búsqueda.
     *
     * @param  array{q?: string|null, archive_status?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, Patient>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $page = $this->search->query(TenantContext::idOrFail(), $filters)->paginate($filters['per_page'] ?? 15);
        /** @var list<int> $ids */
        $ids = $page->getCollection()->map(fn (object $row): int => (int) $row->id)->all();
        $patients = Patient::query()->whereKey($ids)->with('user')->get()->keyBy('id');

        $page->setCollection(collect($ids)->map(fn (int $id) => $patients->get($id))->filter()->values());

        /** @var LengthAwarePaginator<int, Patient> $page */
        return $page;
    }

    /**
     * CUS-13, RF-056: búsqueda exacta por documento mediante el índice ciego.
     */
    public function lookup(Tenant $tenant, string $type, string $number): ?Patient
    {
        return Patient::query()
            ->where('document_hash', $this->documentHash($tenant, $type, PatientIdentity::normalizedNumber($number)))
            ->with('user')
            ->first();
    }

    /**
     * CUS-14: el documento se normaliza a `TIPO:NUMERO`; si su índice ciego ya existe en la clínica
     * responde 422 con la ficha existente (RF-056). El número de HC es el DNI o el número con el
     * prefijo del tipo (RN-79). Un menor se registra con su representante en la misma transacción
     * (RN-12).
     *
     * @param  array{document_type: string, document_number: string, first_name: string, last_name: string, birth_date: string, sex: string, phone: string, email?: string|null, address?: string|null, representative?: array<string, mixed>|null, user_uuid?: string|null}  $data
     *
     * @throws BusinessRuleException|ValidationException
     */
    public function register(Tenant $tenant, array $data, User $creator): Patient
    {
        $type = $data['document_type'];
        $number = PatientIdentity::normalizedNumber($data['document_number']);
        $documentHash = $this->documentHash($tenant, $type, $number);
        $this->ensureDocumentIsFree($documentHash);

        $record = PatientIdentity::clinicalRecordNumber($type, $number);

        $patient = new Patient(Arr::only($data, ['first_name', 'last_name', 'birth_date', 'sex', 'phone', 'email', 'address']));
        // `document_id` heredado (NOT NULL hasta TASK-038) guarda el documento normalizado.
        $patient->document_id = PatientIdentity::normalizedDocument($type, $number);
        $patient->document_type = $type;
        $patient->document_number = $number;
        $patient->document_hash = $documentHash;
        $patient->clinical_record_number = $record;
        $patient->clinical_record_hash = $this->encryption->blindIndex($tenant->id, $record);
        $patient->created_by = $creator->id;

        if (! empty($data['user_uuid'])) {
            $patient->user_id = $this->portalUserId($tenant, $data['user_uuid']);
        }

        try {
            DB::transaction(function () use ($patient, $data): void {
                $patient->save();

                if (! empty($data['representative'])) {
                    /** @var array{document_type: string, document_number: string, first_name: string, last_name: string, relationship: string, phone: string, email?: string|null, valid_from: string} $representative */
                    $representative = $data['representative'];
                    $this->representatives->register($patient, $representative);
                }

                $this->audit->record(AuditEvent::PatientCreated, $patient);
            });
        } catch (UniqueConstraintViolationException) {
            // FE-2 de CUS-14: alta concurrente del mismo documento o de la misma cuenta de portal.
            throw ValidationException::withMessages([
                'document_number' => 'El documento ya está registrado en la clínica.',
            ]);
        }

        return $patient->load('user');
    }

    /**
     * CUS-15 (RF-062): guarda los campos que cambiaron, conserva sus valores anteriores cifrados
     * en `patient_identity_history` y audita solo los nombres de los campos.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function updateIdentity(Patient $patient, array $data, User $actor): Patient
    {
        return DB::transaction(function () use ($patient, $data, $actor): Patient {
            $previous = [];

            foreach (Arr::only($data, self::IDENTITY_FIELDS) as $field => $value) {
                $current = $field === 'birth_date' ? $patient->birth_date->toDateString() : $patient->getAttribute($field);
                $new = $field === 'document_number' ? PatientIdentity::normalizedNumber((string) $value) : $value;

                if ($current !== $new) {
                    $previous[$field] = $current;
                }
            }

            if ($previous === []) {
                return $patient;
            }

            $patient->fill(Arr::only($data, ['first_name', 'last_name', 'birth_date', 'sex', 'phone', 'email', 'address']));

            if (array_key_exists('document_type', $previous) || array_key_exists('document_number', $previous)) {
                $this->changeDocument($patient, (string) $data['document_type'], (string) $data['document_number']);
            }

            $patient->save();

            DB::table('patient_identity_history')->insert([
                'tenant_id' => $patient->tenant_id,
                'patient_id' => $patient->id,
                'changed_fields' => json_encode(array_keys($previous)),
                'previous_values' => $this->encryption->encrypt($patient->tenant_id, (string) json_encode($previous)),
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->record(AuditEvent::PatientIdentityUpdated, $patient, array_keys($previous));

            return $patient->load('user');
        });
    }

    private function changeDocument(Patient $patient, string $type, string $number): void
    {
        $number = PatientIdentity::normalizedNumber($number);
        $hash = $this->documentHash($patient->tenant, $type, $number);
        $this->ensureDocumentIsFree($hash, except: $patient);

        $patient->document_id = PatientIdentity::normalizedDocument($type, $number);
        $patient->document_type = $type;
        $patient->document_number = $number;
        $patient->document_hash = $hash;
    }

    private function documentHash(Tenant $tenant, string $type, string $number): string
    {
        return $this->encryption->blindIndex($tenant->id, PatientIdentity::normalizedDocument($type, $number));
    }

    /**
     * RF-056, CA-14.1: un documento ya registrado responde 422 con la ficha existente.
     *
     * @throws BusinessRuleException
     */
    private function ensureDocumentIsFree(string $documentHash, ?Patient $except = null): void
    {
        $existing = Patient::query()
            ->where('document_hash', $documentHash)
            ->when($except, fn ($query) => $query->whereKeyNot($except?->id))
            ->first();

        if ($existing !== null) {
            throw new BusinessRuleException(
                'RN-09',
                'El documento ya está registrado en la clínica.',
                ['document_number' => ['El documento ya está registrado en la clínica.']],
                extensions: ['existing_patient_id' => $existing->uuid],
            );
        }
    }

    /**
     * La cuenta de portal debe ser de rol 'patient', de la misma clínica, y no estar vinculada ya
     * a otra ficha (contrato heredado, S-14).
     *
     * @throws ValidationException
     */
    private function portalUserId(Tenant $tenant, string $userUuid): int
    {
        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('uuid', $userUuid)
            ->where('role', 'patient')
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user_uuid' => 'El usuario no existe en la clínica o no tiene rol patient.',
            ]);
        }

        if (Patient::query()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user_uuid' => 'Este usuario ya está vinculado a otra ficha de paciente.',
            ]);
        }

        return $user->id;
    }
}
