<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Encryption\TenantEncryption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ficha del paciente (M03; CUS-13 y CUS-14 heredados de las fases 0–3, que TASK-032
 * y TASK-033 completan). Patient lleva Global Scope, así que
 * las consultas ya quedan acotadas al tenant activo.
 */
class PatientService
{
    public function __construct(private TenantEncryption $encryption, private AuditLogger $audit) {}

    /**
     * @return LengthAwarePaginator<int, Patient>
     */
    public function paginate(): LengthAwarePaginator
    {
        return Patient::query()
            ->with('user')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15);
    }

    /**
     * El tenant_id sale siempre del contexto de sesión (BelongsToTenant). La unicidad del
     * DNI por clínica se comprueba sobre el índice ciego, ya que document_id está cifrado.
     *
     * @param  array{document_id: string, first_name: string, last_name: string, birth_date: string, phone?: string|null, email?: string|null, medical_history?: array<string, mixed>|null, user_uuid?: string|null}  $attributes
     *
     * @throws ValidationException
     */
    public function create(Tenant $tenant, array $attributes): Patient
    {
        $documentHash = $this->encryption->blindIndex($tenant->id, $attributes['document_id']);

        if (Patient::query()->where('document_id_hash', $documentHash)->exists()) {
            throw $this->duplicatedDocument();
        }

        $attributes['medical_history'] = $this->normalizeMedicalHistory($attributes['medical_history'] ?? null);

        $patient = new Patient($attributes);

        if (! empty($attributes['user_uuid'])) {
            $patient->user_id = $this->portalUserId($tenant, $attributes['user_uuid']);
        }

        try {
            DB::transaction(function () use ($patient): void {
                $patient->save();
                $this->audit->record(AuditEvent::PatientCreated, $patient);
            });
        } catch (UniqueConstraintViolationException) {
            // Alta concurrente del mismo DNI o de la misma cuenta de portal.
            throw ValidationException::withMessages([
                'document_id' => 'Ya existe una ficha con este documento o esta cuenta en la clínica.',
            ]);
        }

        return $patient->load('user');
    }

    /**
     * La cuenta de portal debe ser de rol 'patient', de la misma clínica, y no estar
     * vinculada ya a otra ficha.
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

    /**
     * Guarda siempre las cuatro claves (listas sin elementos vacíos ni espacios sobrantes)
     * o null si no se registró ningún antecedente.
     *
     * @param  array{alergias?: list<string>, enfermedades?: list<string>, medicamentos?: list<string>, observaciones?: string|null}|null  $history
     * @return array{alergias: list<string>, enfermedades: list<string>, medicamentos: list<string>, observaciones: string|null}|null
     */
    private function normalizeMedicalHistory(?array $history): ?array
    {
        if ($history === null) {
            return null;
        }

        $cleanList = fn (?array $items): array => array_values(array_filter(
            array_map(fn (?string $item): string => trim((string) $item), $items ?? []),
            fn (string $item): bool => $item !== '',
        ));

        $normalized = [
            'alergias' => $cleanList($history['alergias'] ?? null),
            'enfermedades' => $cleanList($history['enfermedades'] ?? null),
            'medicamentos' => $cleanList($history['medicamentos'] ?? null),
            'observaciones' => trim((string) ($history['observaciones'] ?? '')) ?: null,
        ];

        $isEmpty = $normalized['alergias'] === [] && $normalized['enfermedades'] === []
            && $normalized['medicamentos'] === [] && $normalized['observaciones'] === null;

        return $isEmpty ? null : $normalized;
    }

    private function duplicatedDocument(): ValidationException
    {
        return ValidationException::withMessages([
            'document_id' => 'Ya existe un paciente con este documento en la clínica.',
        ]);
    }
}
