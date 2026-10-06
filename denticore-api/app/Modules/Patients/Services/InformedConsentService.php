<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Patients\Models\InformedConsentTemplate;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Models\PlanItem;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Files\FileStorage;
use App\Support\Http\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Firma y revocación del consentimiento informado (CUS-83; SDD §5.10 «Registrar», RF-073,
 * RF-074, RN-12, RN-76, DD-31, DD-46). El texto que se presenta para firmar es el de la versión
 * vigente de la plantilla activa del procedimiento; si no hay una única plantilla activa no se
 * puede firmar (RF-073).
 */
class InformedConsentService
{
    public function __construct(
        private InformedConsentRenderer $renderer,
        private EvidenceSealer $sealer,
        private FileStorage $files,
        private AuditLogger $audit,
    ) {}

    /**
     * Texto que se presenta para firmar y quién debe firmarlo.
     *
     * @param  array{riesgos?: string, alternativas?: string}  $fills
     * @return array{template_version: int, text: string, text_sha256: string, signer: 'titular'|'representante', representative: LegalRepresentative|null, informed_by: User|null}
     *
     * @throws BusinessRuleException
     */
    public function preview(PlanItem $item, User $actor, array $fills = []): array
    {
        $template = $this->activeTemplateFor($item);
        $patient = $item->plan->patient;
        $informedBy = $actor->role === 'dentist' ? $actor : null;

        // El preview solo informa quién debe firmar; la exigencia de representante (RN-12) se
        // aplica al firmar, no aquí: un menor sin representante aún debe poder previsualizar.
        $signer = $patient->isMinorOn() ? 'representante' : 'titular';
        $representative = $signer === 'representante'
            ? $this->currentRepresentative($patient)
            : null;

        $rendered = $this->renderer->render($template->currentVersion, $patient, $item, $informedBy, $fills);

        return [
            'template_version' => $template->currentVersion->version,
            ...$rendered,
            'signer' => $signer,
            'representative' => $representative,
            'informed_by' => $informedBy,
        ];
    }

    /**
     * @param  array{channel: string, confirmation_document_number?: string|null, scanned_file?: UploadedFile|null, informed_by?: string|null, riesgos?: string|null, alternativas?: string|null}  $data
     *
     * @throws BusinessRuleException|ValidationException
     */
    public function sign(PlanItem $item, array $data, User $actor, ?string $ipAddress): InformedConsent
    {
        $template = $this->activeTemplateFor($item);
        $patient = $item->plan->patient;
        $representative = $this->representative($item);

        if ($data['channel'] === 'dispositivo') {
            $this->ensureConfirmationMatches($patient, $representative, (string) ($data['confirmation_document_number'] ?? ''));
        }

        $informedBy = $actor->role === 'dentist'
            ? $actor
            : $this->resolveInformedBy($item, $data['informed_by'] ?? null);

        $rendered = $this->renderer->render($template->currentVersion, $patient, $item, $informedBy, [
            'riesgos' => (string) ($data['riesgos'] ?? ''),
            'alternativas' => (string) ($data['alternativas'] ?? ''),
        ]);

        return DB::transaction(function () use ($item, $patient, $template, $representative, $informedBy, $actor, $ipAddress, $data, $rendered): InformedConsent {
            // Serializa los registros concurrentes del mismo paciente (como ConsentService).
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();

            $scan = isset($data['scanned_file']) ? $this->files->storeUpload($data['scanned_file'], $actor) : null;

            $consent = new InformedConsent;
            $consent->forceFill([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'plan_item_id' => $item->id,
                'template_version_id' => $template->currentVersion->id,
                'rendered_text' => $rendered['text'],
                'text_sha256' => $rendered['text_sha256'],
                'signer' => $representative === null ? 'titular' : 'representante',
                'legal_representative_id' => $representative?->id,
                'channel' => $data['channel'],
                'scanned_file_id' => $scan?->id,
                'informed_by' => $informedBy->id,
                'registered_by' => $actor->id,
                'signed_at' => now(),
                'ip_address' => $ipAddress,
                'status' => 'vigente',
            ]);
            $consent->setRelation('patient', $patient)
                ->setRelation('planItem', $item)
                ->setRelation('templateVersion', $template->currentVersion)
                ->setRelation('legalRepresentative', $representative)
                ->setRelation('informedBy', $informedBy)
                ->setRelation('registeredBy', $actor);
            $consent->evidence_hmac = $this->sealer->seal($consent->evidencePayload());
            $consent->save();

            $this->audit->record(AuditEvent::InformedConsentSigned, $consent);

            return $consent;
        });
    }

    /**
     * RF-074: solo se revoca un consentimiento vigente, antes de usarse (RN-76).
     *
     * @throws BusinessRuleException
     */
    public function revoke(InformedConsent $consent, string $reason): InformedConsent
    {
        if ($consent->status !== 'vigente') {
            throw new BusinessRuleException('RF-074', 'Solo se puede revocar un consentimiento informado vigente.');
        }

        $consent->forceFill([
            'status' => 'revocado',
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();

        $this->audit->record(AuditEvent::InformedConsentRevoked, $consent, ['status', 'revoked_at', 'revocation_reason']);

        return $consent;
    }

    /**
     * RF-073: una única plantilla activa asociada al procedimiento del ítem.
     *
     * @throws BusinessRuleException
     */
    private function activeTemplateFor(PlanItem $item): InformedConsentTemplate
    {
        /** @var Collection<int, InformedConsentTemplate> $templates */
        $templates = InformedConsentTemplate::query()
            ->where('is_active', true)
            ->whereHas('procedures', fn (Builder $query) => $query->whereKey($item->procedure_id))
            ->with('currentVersion')
            ->get();

        if ($templates->count() !== 1) {
            throw new BusinessRuleException('RF-073', $templates->isEmpty()
                ? 'El procedimiento no tiene una plantilla de consentimiento informado activa.'
                : 'El procedimiento tiene varias plantillas de consentimiento informado activas.');
        }

        return $templates->first();
    }

    /**
     * RN-12, CA-17.3: un menor firma por medio de su representante vigente; sin él, 422.
     *
     * @throws BusinessRuleException
     */
    private function representative(PlanItem $item): ?LegalRepresentative
    {
        $patient = $item->plan->patient;

        if (! $patient->isMinorOn()) {
            return null;
        }

        return $this->currentRepresentative($patient) ?? throw new BusinessRuleException(
            'RN-12',
            'El paciente es menor de edad y no tiene un representante legal vigente.',
        );
    }

    private function currentRepresentative(Patient $patient): ?LegalRepresentative
    {
        return $patient->representatives()->whereNull('valid_until')->latest('valid_from')->first();
    }

    /**
     * La recepción y el administrador firman "informando" un odontólogo activo de la clínica.
     *
     * @throws ValidationException
     */
    private function resolveInformedBy(PlanItem $item, ?string $uuid): User
    {
        if ($uuid === null || $uuid === '') {
            throw ValidationException::withMessages([
                'informed_by' => 'Debe indicar el odontólogo que informa el procedimiento.',
            ]);
        }

        /** @var User|null $dentist */
        $dentist = User::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('role', 'dentist')
            ->where('status', 'activo')
            ->where('uuid', $uuid)
            ->first();

        return $dentist ?? throw ValidationException::withMessages([
            'informed_by' => 'El odontólogo indicado no existe o no está activo en la clínica.',
        ]);
    }

    /**
     * FE-2: el documento confirmado debe ser el del titular o del representante.
     *
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
}
