<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Models\ArcoRequestFile;
use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentRevocationService;
use App\Modules\Patients\Services\PatientIdentity;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStorage;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\BusinessDays;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro y atención de solicitudes ARCO (SDD §5.11; CUS-63, CUS-64; RF-182, RF-183, RN-69, RN-81,
 * DD-26).
 *
 * El plazo vence el décimo día hábil desde el día hábil siguiente a la recepción, en la fecha local de
 * la clínica (DD-26, CA-64.1).
 *
 * Verificación de identidad (SRS §11.15, precondición 2):
 * - en la clínica, el documento del titular o del representante vigente, revisado por el personal;
 * - en el portal, la propia sesión.
 *
 * Atención por el Oficial de Datos Personales (CUS-64): `recibida` → `en_tramite` → `atendida` o
 * `denegada`. Al atender, cada tipo ejecuta su acción (§5.11): el acceso adjunta la copia de la HC,
 * la oposición revoca las finalidades opcionales indicadas, la rectificación clínica guarda la
 * corrección del odontólogo asignado y la cancelación exige el bloqueo ya aplicado
 * (ArcoCancellationService). La portabilidad se responde por texto hasta RF-181 (MS-13).
 */
class ArcoRequestService
{
    /** Finalidades opcionales que la oposición y la cancelación revocan (RN-11, RN-14). */
    public const OPTIONAL_PURPOSES = ['notificaciones', 'ia', 'prediccion', 'encuestas'];

    public function __construct(
        private FileStorage $files,
        private AuditLogger $audit,
        private ClinicalRecordCopyService $copies,
        private ConsentRevocationService $revocations,
        private NotificationService $notifications,
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
     * CUS-64, paso 3: el Oficial abre la solicitud y queda a su cargo.
     *
     * @throws BusinessRuleException
     */
    public function start(ArcoRequest $request, User $officer): ArcoRequest
    {
        return DB::transaction(function () use ($request, $officer): ArcoRequest {
            $locked = $this->lock($request, ['recibida'], 'La solicitud ya está en trámite o resuelta.');
            $locked->forceFill(['status' => 'en_tramite', 'handled_by' => $officer->id])->save();

            return $locked;
        });
    }

    /**
     * CUS-64, paso 4 (rectificación de datos clínicos): un odontólogo activo de la clínica ejecuta
     * la corrección con CUS-23.
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function assignCorrection(ArcoRequest $request, User $dentist): ArcoRequest
    {
        if ($request->type !== 'rectificacion') {
            throw new BusinessRuleException('RN-69', 'Solo una rectificación se asigna a un odontólogo.');
        }

        if ($dentist->role !== 'dentist' || $dentist->status !== 'activo') {
            throw ValidationException::withMessages(['dentist_id' => ['Indica un odontólogo activo de la clínica.']]);
        }

        return DB::transaction(function () use ($request, $dentist): ArcoRequest {
            $locked = $this->lock($request, ['en_tramite'], 'Inicia la solicitud antes de asignar la corrección.');
            $locked->forceFill(['assigned_dentist_id' => $dentist->id])->save();

            return $locked;
        });
    }

    /**
     * CUS-64, pasos 5 a 8: ejecuta la acción del tipo, registra la resolución con el indicador de
     * plazo (FE-3) y responde al titular por correo y en el portal (RF-183).
     *
     * @param  array{status: string, response: string, denial_reason?: string|null, purposes?: list<string>, correction_entry_id?: string|null}  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function resolve(ArcoRequest $request, array $data, User $officer): ArcoRequest
    {
        $resolved = DB::transaction(function () use ($request, $data, $officer): ArcoRequest {
            $locked = $this->lock($request, ['en_tramite'], 'Solo se resuelve una solicitud en trámite.');
            $patient = $locked->patient;
            $attended = $data['status'] === 'atendida';

            $changes = [
                'status' => $data['status'],
                'response' => $data['response'],
                'denial_reason' => $attended ? null : ($data['denial_reason'] ?? null),
                'handled_by' => $officer->id,
                'resolved_at' => now(),
                'within_deadline' => ClinicClock::for($patient->tenant)->localDate(now()) <= $locked->due_date->toDateString(),
            ];

            if ($attended) {
                $changes = [...$changes, ...$this->attend($locked, $patient, $data, $officer)];
            }

            $locked->forceFill($changes)->save();
            $this->audit->record(AuditEvent::ArcoResolved, $locked, meta: [
                'status' => $locked->status,
                'within_deadline' => $locked->within_deadline,
            ], actor: $officer);

            return $locked;
        });

        $this->notifyRequester($resolved);

        return $resolved;
    }

    /**
     * Acción de cada tipo al atender la solicitud (SDD §5.11).
     *
     * @param  array{purposes?: list<string>, correction_entry_id?: string|null}  $data
     * @return array<string, mixed> Columnas de la solicitud que la acción completa.
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    private function attend(ArcoRequest $request, Patient $patient, array $data, User $officer): array
    {
        return match ($request->type) {
            // RN-69, RN-70: la copia de la HC (CUS-62) es el documento de respuesta.
            'acceso' => ['response_document_id' => $this->copies->request($patient, $officer)->id],
            'oposicion' => $this->oppose($request, $patient, $data['purposes'] ?? [], $officer),
            'rectificacion' => ['correction_entry_uuid' => $this->correction($patient, $data['correction_entry_id'] ?? null)],
            'cancelacion' => $patient->archive_status === 'bloqueado'
                ? []
                : throw new BusinessRuleException('RF-184', 'Aplica la cancelación antes de atender la solicitud.'),
            default => [],
        };
    }

    /**
     * CUS-18 «extend» CUS-64: revoca las finalidades opcionales indicadas con el canal `arco`.
     *
     * @param  list<string>  $purposes
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    private function oppose(ArcoRequest $request, Patient $patient, array $purposes, User $officer): array
    {
        /** @var Consent|null $consent */
        $consent = Consent::query()->where('patient_id', $patient->id)->where('status', 'vigente')->first();

        if ($consent === null) {
            throw new BusinessRuleException('RN-14', 'El paciente no tiene un consentimiento vigente.');
        }

        $this->revocations->revoke($patient, $consent, $purposes, null, $officer, 'arco', $request->id);

        return [];
    }

    /**
     * CUS-23 «extend» CUS-64: la corrección registrada por el odontólogo, del mismo paciente.
     *
     * @throws ValidationException
     */
    private function correction(Patient $patient, ?string $uuid): ?string
    {
        if ($uuid === null) {
            return null;
        }

        $isCorrection = OdontogramEntry::query()
            ->where('patient_id', $patient->id)
            ->where('uuid', $uuid)
            ->whereNotNull('corrects_entry_id')
            ->exists();

        return $isCorrection ? $uuid : throw ValidationException::withMessages([
            'correction_entry_id' => ['Indica una corrección del odontograma de este paciente.'],
        ]);
    }

    /**
     * RF-183: correo al titular, o a la cuenta del representante que presentó la solicitud, y
     * aviso en su portal. Sin correo ni cuenta activa, la respuesta queda solo en la solicitud.
     */
    private function notifyRequester(ArcoRequest $request): void
    {
        $patient = $request->patient;
        $account = $request->requested_by === 'representante'
            ? $request->legalRepresentative?->user
            : $patient->user;
        $data = ['type' => $request->type, 'status' => $request->status];
        $links = ['portal' => rtrim((string) config('app.spa_url'), '/').'/c/'.TenantContext::tenantOrFail()->slug.'/portal/arco'];

        if ($request->requested_by === 'titular' && $patient->deceased_on === null) {
            $this->notifications->sendEmail(NotificationEvent::RespuestaArco, $patient, $data, $links, "respuesta_arco:correo:{$request->uuid}");
        } elseif ($account !== null) {
            $this->notifications->sendEmail(NotificationEvent::RespuestaArco, $account, $data, $links, "respuesta_arco:correo:{$request->uuid}");
        }

        if ($account !== null && $account->status === 'activo') {
            $this->notifications->notifyInApp(NotificationEvent::RespuestaArco, $account, $data, $links, "respuesta_arco:{$request->uuid}");
        }
    }

    /**
     * Bloquea la fila y exige uno de los estados indicados (409 si no).
     *
     * @param  list<string>  $statuses
     *
     * @throws BusinessRuleException
     */
    private function lock(ArcoRequest $request, array $statuses, string $detail): ArcoRequest
    {
        /** @var ArcoRequest $locked */
        $locked = ArcoRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw new BusinessRuleException('RN-69', $detail, status: 409);
        }

        return $locked;
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
