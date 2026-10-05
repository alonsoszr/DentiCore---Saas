<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Encryption\TenantEncryption;
use App\Support\Time\ClinicClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Representación legal (CUS-16; RN-12, RN-13, RF-059 a RF-061, DD-13). `register` se ejecuta
 * dentro de la transacción del Service que la invoca (el registro de un menor la llama junto con
 * la ficha, TASK-032) y no abre una propia; `add` es la entrada del endpoint.
 */
class LegalRepresentativeService
{
    public const RELATIONSHIPS = ['madre', 'padre', 'tutor', 'curador', 'otro'];

    public function __construct(private TenantEncryption $encryption, private AuditLogger $audit) {}

    /**
     * Reglas de los datos del representante (RF-060, SRS §11.3).
     *
     * @return array<string, array<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}document_type" => ['required', Rule::in(array_keys(PatientIdentity::DOCUMENT_PREFIXES))],
            "{$prefix}document_number" => ['required', 'string', PatientIdentity::documentNumberRule("{$prefix}document_type")],
            "{$prefix}first_name" => ['required', 'string', 'min:1', 'max:100'],
            "{$prefix}last_name" => ['required', 'string', 'min:1', 'max:100'],
            "{$prefix}relationship" => ['required', Rule::in(self::RELATIONSHIPS)],
            "{$prefix}phone" => ['required', 'string', 'regex:'.PatientIdentity::PHONE_PATTERN],
            "{$prefix}email" => ['nullable', 'email:rfc', 'max:180'],
            "{$prefix}valid_from" => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @param  array{document_type: string, document_number: string, first_name: string, last_name: string, relationship: string, phone: string, email?: string|null, valid_from: string}  $data
     */
    public function add(Patient $patient, array $data): LegalRepresentative
    {
        return DB::transaction(fn (): LegalRepresentative => $this->register($patient, $data));
    }

    /**
     * @param  array{document_type: string, document_number: string, first_name: string, last_name: string, relationship: string, phone: string, email?: string|null, valid_from: string}  $data
     */
    public function register(Patient $patient, array $data): LegalRepresentative
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La representación se registra dentro de la transacción del Service que la invoca.');
        }

        $number = PatientIdentity::normalizedNumber($data['document_number']);

        $representative = new LegalRepresentative([...$data, 'document_number' => $number]);
        $representative->patient_id = $patient->id;
        $representative->document_hash = $this->encryption->blindIndex(
            $patient->tenant_id,
            PatientIdentity::normalizedDocument($data['document_type'], $number),
        );
        $representative->save();
        $representative->setRelation('patient', $patient);

        $this->audit->record(AuditEvent::RepresentativeCreated, $representative);

        return $representative;
    }

    /**
     * RF-061: termina la representación con su motivo, desde la fecha de hoy de la clínica.
     */
    public function end(LegalRepresentative $representative, string $reason): LegalRepresentative
    {
        return DB::transaction(function () use ($representative, $reason): LegalRepresentative {
            $today = ClinicClock::for($representative->tenant)->now()->toDateString();

            $representative->forceFill([
                'valid_until' => max($today, $representative->valid_from->toDateString()),
                'ended_reason' => $reason,
            ])->save();

            $this->audit->record(AuditEvent::RepresentativeEnded, $representative, ['valid_until', 'ended_reason']);

            return $representative;
        });
    }

    /**
     * RF-061, RN-13: el día en que el paciente cumple 18 años (fecha de la clínica) su
     * representación termina con `mayoria_de_edad`. Se puede repetir sin efectos duplicados.
     *
     * @return int Representaciones terminadas.
     */
    public function endAtMajority(Tenant $tenant): int
    {
        $bornOnOrBefore = ClinicClock::for($tenant)->now()->startOfDay()->subYears(18)->toDateString();
        $ended = 0;

        LegalRepresentative::query()
            ->whereNull('valid_until')
            ->whereHas('patient', fn ($query) => $query->where('birth_date', '<=', $bornOnOrBefore))
            ->with('patient')
            ->orderBy('id')
            ->each(function (LegalRepresentative $representative) use (&$ended): void {
                $birthday = $representative->patient->birth_date->copy()->addYears(18)->toDateString();

                DB::transaction(function () use ($representative, $birthday): void {
                    $representative->forceFill([
                        'valid_until' => max($birthday, $representative->valid_from->toDateString()),
                        'ended_reason' => 'mayoria_de_edad',
                    ])->save();

                    $this->audit->record(AuditEvent::RepresentativeEnded, $representative, ['valid_until', 'ended_reason']);
                });
                $ended++;
            });

        return $ended;
    }
}
