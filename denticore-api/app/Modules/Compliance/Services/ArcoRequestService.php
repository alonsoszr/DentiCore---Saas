<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Models\ArcoRequestFile;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientIdentity;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStorage;
use App\Support\Time\BusinessDays;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro de solicitudes ARCO (SDD §5.11; CUS-63; RF-182, RN-69, RN-81, DD-26).
 *
 * El plazo vence el décimo día hábil desde el día hábil siguiente a la recepción, en la fecha local de
 * la clínica (DD-26, CA-64.1).
 *
 * Verificación de identidad (SRS §11.15, precondición 2):
 * - en la clínica, el documento del titular o del representante vigente, revisado por el personal;
 * - en el portal, la propia sesión.
 */
class ArcoRequestService
{
    public function __construct(
        private FileStorage $files,
        private AuditLogger $audit,
    ) {}

    /**
     * Solicitud presentada en la clínica, con el documento del solicitante.
     *
     * @param  array{type: string, requested_by: string, legal_representative_id?: string|null, description: string, confirmation_document_number: string, files?: list<UploadedFile>}  $data
     *
     * @throws ValidationException
     */
    public function registerAtClinic(Patient $patient, array $data, User $actor): ArcoRequest
    {
        $representative = $data['requested_by'] === 'representante'
            ? $this->currentRepresentative($patient, $data['legal_representative_id'] ?? null)
            : null;

        $expected = $representative->document_number ?? $patient->document_number;
        if ($expected === null || PatientIdentity::normalizedNumber($data['confirmation_document_number']) !== PatientIdentity::normalizedNumber($expected)) {
            throw ValidationException::withMessages(['confirmation_document_number' => [$representative === null
                ? 'El documento no coincide con el del titular.'
                : 'El documento no coincide con el del representante legal vigente.']]);
        }

        return $this->create($patient, $data['type'], $representative, 'presencial', $data['description'], $data['files'] ?? [], $actor);
    }

    /**
     * Solicitud presentada desde el portal: la sesión verifica la identidad, y el solicitante es el
     * titular o el representante dueño de la cuenta.
     *
     * @param  array{type: string, description: string}  $data
     */
    public function registerFromPortal(Patient $patient, array $data, User $account): ArcoRequest
    {
        /** @var LegalRepresentative|null $representative */
        $representative = $patient->user_id === $account->id
            ? null
            : $patient->representatives()->whereNull('valid_until')->where('user_id', $account->id)->latest('valid_from')->first();

        return $this->create($patient, $data['type'], $representative, 'portal', $data['description'], [], $account);
    }

    /**
     * @param  list<UploadedFile>  $uploads
     */
    private function create(Patient $patient, string $type, ?LegalRepresentative $representative, string $channel, string $description, array $uploads, User $actor): ArcoRequest
    {
        return DB::transaction(function () use ($patient, $type, $representative, $channel, $description, $uploads, $actor): ArcoRequest {
            $receivedAt = now();
            $receivedOn = ClinicClock::for($patient->tenant)->localDate($receivedAt);

            $request = new ArcoRequest;
            $request->forceFill([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'type' => $type,
                'requested_by' => $representative === null ? 'titular' : 'representante',
                'legal_representative_id' => $representative?->id,
                'identity_verification' => $channel === 'portal' ? 'sesion_portal' : 'documento_presencial',
                'channel' => $channel,
                'description' => $description,
                'status' => 'recibida',
                'received_at' => $receivedAt,
                'due_date' => BusinessDays::addWorkingDays(CarbonImmutable::parse($receivedOn), ArcoRequest::DEADLINE_BUSINESS_DAYS)->toDateString(),
            ])->save();

            foreach ($uploads as $upload) {
                (new ArcoRequestFile)->forceFill([
                    'arco_request_id' => $request->id,
                    'stored_file_id' => $this->files->storeUpload($upload, $actor)->id,
                ])->save();
            }

            $request->setRelation('patient', $patient)->setRelation('legalRepresentative', $representative);
            $this->audit->record(AuditEvent::ArcoReceived, $request, actor: $actor);

            return $request->loadCount('files');
        });
    }

    /**
     * Representante vigente del paciente (RN-12); otro o ya terminado → 422.
     *
     * @throws ValidationException
     */
    private function currentRepresentative(Patient $patient, ?string $uuid): LegalRepresentative
    {
        /** @var LegalRepresentative|null $representative */
        $representative = $uuid === null ? null : $patient->representatives()->where('uuid', $uuid)->whereNull('valid_until')->first();

        return $representative ?? throw ValidationException::withMessages([
            'legal_representative_id' => ['Indica un representante legal vigente del paciente.'],
        ]);
    }
}
