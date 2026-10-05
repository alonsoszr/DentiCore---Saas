<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Files\DocumentService;
use App\Support\Files\FileStorage;
use App\Support\Http\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro del consentimiento de datos (CUS-17; SDD §5.10 «Registrar»; RN-10, RN-11, RN-12,
 * RN-15, RF-065, RF-066, DD-46). ConsentGate se resuelve en cada uso porque es `scoped`: la
 * versión vigente de la plantilla se lee una vez por solicitud o job.
 */
class ConsentService
{
    public function __construct(
        private ConsentRenderer $renderer,
        private EvidenceSealer $sealer,
        private DocumentService $documents,
        private FileStorage $files,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * Texto que se presenta para firmar, con quién lo otorga y las finalidades disponibles.
     *
     * @return array{template_version: int, text: string, text_sha256: string, granted_by: string, representative: LegalRepresentative|null, available_purposes: list<string>}
     *
     * @throws BusinessRuleException
     */
    public function preview(Patient $patient): array
    {
        $representative = $this->grantingRepresentative($patient);
        $version = app(ConsentGate::class)->currentTemplateVersion();

        return [
            'template_version' => $version,
            ...$this->renderer->render($version, $patient->tenant, $patient),
            'granted_by' => $representative === null ? 'titular' : 'representante',
            'representative' => $representative,
            'available_purposes' => $this->renderer->availablePurposes($patient->tenant),
        ];
    }

    /**
     * Registra el consentimiento y sustituye al vigente sin alterar sus datos (CA-17.4). En el
     * canal presencial, el documento escrito debe ser el del titular o del representante (FE-2);
     * en papel se adjunta el formulario firmado (FA-2).
     *
     * @param  array{channel: string, purpose_notifications?: bool|null, purpose_ai?: bool|null, purpose_risk?: bool|null, purpose_surveys?: bool|null, confirmation_document_number?: string|null, scanned_file?: UploadedFile|null}  $data
     *
     * @throws BusinessRuleException|ValidationException
     */
    public function grant(Patient $patient, array $data, User $assistant, ?string $ipAddress): Consent
    {
        $representative = $this->grantingRepresentative($patient);

        if ($data['channel'] === 'presencial') {
            $this->ensureConfirmationMatches($patient, $representative, (string) ($data['confirmation_document_number'] ?? ''));
        }

        $version = app(ConsentGate::class)->currentTemplateVersion();
        $rendered = $this->renderer->render($version, $patient->tenant, $patient);

        $consent = DB::transaction(function () use ($patient, $data, $assistant, $ipAddress, $representative, $version, $rendered): Consent {
            // Serializa los registros concurrentes del mismo paciente (índice único `vigente`).
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();

            $scan = isset($data['scanned_file']) ? $this->files->storeUpload($data['scanned_file'], $assistant) : null;

            $patient->consents()->where('status', 'vigente')->get()->each(
                fn (Consent $previous) => $previous->forceFill(['status' => 'sustituido', 'superseded_at' => now()])->save(),
            );

            $consent = new Consent;
            $consent->forceFill([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'consent_template_version' => $version,
                'purpose_care' => true,
                'purpose_notifications' => (bool) ($data['purpose_notifications'] ?? false),
                'purpose_ai' => (bool) ($data['purpose_ai'] ?? false),
                'purpose_risk' => (bool) ($data['purpose_risk'] ?? false),
                'purpose_surveys' => (bool) ($data['purpose_surveys'] ?? false),
                'granted_by' => $representative === null ? 'titular' : 'representante',
                'legal_representative_id' => $representative?->id,
                'channel' => $data['channel'],
                'rendered_text' => $rendered['text'],
                'text_sha256' => $rendered['text_sha256'],
                'scanned_file_id' => $scan?->id,
                'granted_at' => now(),
                'ip_address' => $ipAddress,
                'assisted_by' => $assistant->id,
                'status' => 'vigente',
            ]);
            $consent->setRelation('patient', $patient)
                ->setRelation('legalRepresentative', $representative)
                ->setRelation('assistant', $assistant);
            $consent->evidence_hmac = $this->sealer->seal($consent->evidencePayload());
            $consent->save();

            // RF-066: la constancia se genera en la cola `documents`; se enlaza una sola vez.
            $certificate = $this->documents->request($consent, 'constancia_consentimiento', $assistant);
            $consent->forceFill(['certificate_document_id' => $certificate->id])->save();
            $consent->setRelation('certificate', $certificate);

            $this->sendCertificateEmail($patient, $representative);
            $this->audit->record(AuditEvent::ConsentGranted, $consent);

            return $consent;
        });

        $patient->setRelation('currentConsent', $consent);

        return $consent;
    }

    /**
     * RN-12, CA-17.3: un menor otorga por medio de su representante vigente; sin él no se puede
     * iniciar el registro (FE-3).
     *
     * @throws BusinessRuleException
     */
    private function grantingRepresentative(Patient $patient): ?LegalRepresentative
    {
        if (! $patient->isMinorOn()) {
            return null;
        }

        /** @var LegalRepresentative|null $representative */
        $representative = $patient->representatives()->whereNull('valid_until')->latest('valid_from')->first();

        return $representative ?? throw new BusinessRuleException(
            'RN-12',
            'El paciente es menor de edad y no tiene un representante legal vigente.',
        );
    }

    /**
     * @throws ValidationException
     */
    private function ensureConfirmationMatches(Patient $patient, ?LegalRepresentative $representative, string $typed): void
    {
        $expected = $representative->document_number ?? $patient->document_number;

        if ($expected === null || PatientIdentity::normalizedNumber($typed) !== PatientIdentity::normalizedNumber($expected)) {
            throw ValidationException::withMessages([
                'confirmation_document_number' => $representative === null
                    ? 'El documento no coincide con el del titular.'
                    : 'El documento no coincide con el del representante legal vigente.',
            ]);
        }
    }

    /**
     * RF-066: correo al titular o, si otorgó un representante, a su cuenta de portal. Un
     * representante sin cuenta no recibe correo (la tabla `notifications` solo admite usuarios
     * o pacientes como destinatarios); la constancia queda disponible para descarga.
     */
    private function sendCertificateEmail(Patient $patient, ?LegalRepresentative $representative): void
    {
        $recipient = $representative === null ? $patient : $representative->user;

        if ($recipient === null) {
            return;
        }

        $this->notifications->sendEmail(NotificationEvent::ConsentimientoConstancia, $recipient, links: [
            'certificate' => rtrim((string) config('app.spa_url'), '/')."/c/{$patient->tenant->slug}/portal/consentimiento",
        ]);
    }
}
