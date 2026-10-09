<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientIdentity;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Modules\Treatment\Actions\ExpireBudgetAction;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Files\FileStorage;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decisión sobre el presupuesto (SDD §5.4.3; CUS-37; RF-011, RF-122, RF-123, RN-12, RN-35 a RN-37,
 * DD-46). Este canal es el presencial: la recepción o el administrador registra la decisión del
 * titular o de su representante, verificando su documento contra la ficha. El portal y el enlace
 * con OTP llegan en MS-06.
 *
 * @phpstan-type DecisionData array{decision: 'aceptado'|'rechazado', signer: 'titular'|'representante', signer_document_number: string, rejection_reason?: string|null, rejection_detail?: string|null, signed_file?: UploadedFile|null}
 */
class BudgetDecisionService
{
    public function __construct(
        private TreatmentPlanService $plans,
        private ExpireBudgetAction $expire,
        private EvidenceSealer $sealer,
        private FileStorage $files,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  DecisionData  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function decide(Budget $budget, array $data, User $actor, ?string $ip, ?string $userAgent): Budget
    {
        // Pasos 1 a 3: el FOR UPDATE serializa decisiones simultáneas (CA-37.4); si venció, la
        // transacción termina sin cambios y el vencimiento se confirma aparte.
        $decided = DB::transaction(function () use ($budget, $data, $actor, $ip, $userAgent): ?Budget {
            $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if ($budget->status === 'vencido') {
                throw $this->expiredException($budget);
            }

            if ($budget->status !== 'emitido') {
                throw new BusinessRuleException('RN-36', "El presupuesto ya fue {$budget->status}.", status: 409);
            }

            if ($budget->expires_at === null || now()->isAfter($budget->expires_at)) {
                return null;
            }

            return $data['decision'] === 'aceptado'
                ? $this->accept($budget, $data, $actor, $ip, $userAgent)
                : $this->reject($budget, $data, $actor, $ip, $userAgent);
        });

        if ($decided === null) {
            $this->expire->execute($budget);

            throw $this->expiredException($budget);
        }

        return $decided;
    }

    /**
     * Paso 5 (RN-36, RN-37): aceptación total; los demás emitidos del plan quedan reemplazados y
     * los ítems incluidos y el plan, aceptados.
     *
     * @param  DecisionData  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    private function accept(Budget $budget, array $data, User $actor, ?string $ip, ?string $userAgent): Budget
    {
        $plan = TreatmentPlan::query()->whereKey($budget->treatment_plan_id)->lockForUpdate()->firstOrFail();

        if (! $plan->canTransitionTo('aceptado')) {
            throw new BusinessRuleException('RF-114', "El plan está {$plan->status}: su presupuesto no puede aceptarse.", status: 409);
        }

        $this->record($budget, $data, $actor, $ip, $userAgent);

        Budget::query()
            ->where('treatment_plan_id', $plan->id)
            ->where('status', 'emitido')
            ->whereKeyNot($budget->id)
            ->update(['status' => 'reemplazado', 'replaced_at' => now()]);

        PlanItem::query()
            ->whereIn('id', $budget->lines()->pluck('plan_item_id'))
            ->where('status', 'propuesto')
            ->update(['status' => 'aceptado']);

        $this->plans->markAccepted($plan);
        $this->notifyAcceptance($budget, $plan);

        return $budget;
    }

    /**
     * Paso 6 (SRS §11.10 FA-1): rechazo con motivo opcional; el plan sigue `propuesto`.
     *
     * @param  DecisionData  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    private function reject(Budget $budget, array $data, User $actor, ?string $ip, ?string $userAgent): Budget
    {
        $budget->forceFill([
            'rejection_reason' => $data['rejection_reason'] ?? null,
            'rejection_detail' => $data['rejection_detail'] ?? null,
        ]);

        return $this->record($budget, $data, $actor, $ip, $userAgent);
    }

    /**
     * Estado final, evidencia de la decisión (RF-122) y su sello (DD-46).
     *
     * @param  DecisionData  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    private function record(Budget $budget, array $data, User $actor, ?string $ip, ?string $userAgent): Budget
    {
        $signerDocumentHash = $this->verifiedSignerHash($budget->patient, $data);
        $signedFile = isset($data['signed_file']) ? $this->files->storeUpload($data['signed_file'], $actor) : null;

        $budget->forceFill([
            'status' => $data['decision'],
            'decided_at' => now(),
            'decision_channel' => 'presencial',
            'decision_by_user_id' => $actor->id,
            'decision_signer' => $data['signer'],
            'decision_signer_document_hash' => $signerDocumentHash,
            'decision_ip' => $ip,
            'decision_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 300),
            'signed_file_id' => $signedFile?->id,
        ]);
        $budget->setRelation('decisionBy', $actor)->setRelation('signedFile', $signedFile);
        $budget->decision_evidence_hmac = $this->sealer->seal($budget->decisionEvidencePayload());
        $budget->save();

        $this->audit->record(AuditEvent::BudgetDecided, $budget, ['status'], ['decision' => $data['decision'], 'channel' => 'presencial']);

        return $budget;
    }

    /**
     * §5.4.3 paso 4, presencial: el titular adulto o el representante legal vigente, con su número
     * de documento verificado contra la ficha. Devuelve la huella del documento (índice ciego).
     *
     * @param  DecisionData  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    private function verifiedSignerHash(Patient $patient, array $data): ?string
    {
        if ($data['signer'] === 'titular') {
            if ($patient->isMinorOn()) {
                // RN-12, SRS §11.10 FE-5.
                throw new BusinessRuleException('RN-12', 'La decisión corresponde al representante legal.', status: 403);
            }

            $expected = $patient->document_number;
            $hash = $patient->document_hash;
            $mismatch = 'El documento no coincide con el del titular.';
        } else {
            /** @var LegalRepresentative|null $representative */
            $representative = $patient->representatives()->whereNull('valid_until')->latest('valid_from')->first();

            if ($representative === null) {
                throw ValidationException::withMessages(['signer' => 'El paciente no tiene un representante legal vigente.']);
            }

            $expected = $representative->document_number;
            $hash = $representative->document_hash;
            $mismatch = 'El documento no coincide con el del representante legal vigente.';
        }

        if ($expected === null || PatientIdentity::normalizedNumber($data['signer_document_number']) !== PatientIdentity::normalizedNumber($expected)) {
            throw ValidationException::withMessages(['signer_document_number' => $mismatch]);
        }

        return $hash;
    }

    /**
     * Paso 7 de CUS-37 (SDD §4.8): aviso in-app al odontólogo del plan y a la recepción activa.
     */
    private function notifyAcceptance(Budget $budget, TreatmentPlan $plan): void
    {
        $recipients = User::query()
            ->where('tenant_id', $budget->tenant_id)
            ->where('status', 'activo')
            ->where(fn ($query) => $query->whereKey($plan->created_by)->orWhere('role', 'receptionist'))
            ->get();

        foreach ($recipients as $recipient) {
            $this->notifications->notifyInApp(NotificationEvent::PresupuestoAceptado, $recipient, data: [
                'number' => $budget->number,
                'total' => $budget->total,
            ], links: [
                'budget' => rtrim((string) config('app.spa_url'), '/')."/app/presupuestos/{$budget->uuid}",
            ], dedupeKey: "presupuesto_aceptado:{$budget->uuid}:{$recipient->uuid}");
        }
    }

    private function expiredException(Budget $budget): BusinessRuleException
    {
        $clock = ClinicClock::for(TenantContext::tenantOrFail());
        $date = $budget->expires_at?->copy()->setTimezone($clock->timezone())->format('d/m/Y');

        return new BusinessRuleException('RN-35', "El presupuesto venció el {$date}.", status: 409);
    }
}
