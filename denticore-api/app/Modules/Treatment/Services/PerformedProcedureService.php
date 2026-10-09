<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Odontogram\Services\ClinicalValidator;
use App\Modules\Odontogram\Services\OdontogramEntryService;
use App\Modules\Odontogram\Services\OdontogramStateService;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Models\PerformedProcedure;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registrar un procedimiento realizado (SDD §5.5; CUS-39; RF-126, RF-127, RF-130, RN-38, RN-39,
 * RN-76). En una transacción: el ítem aceptado, la atención abierta, la cantidad pendiente, el
 * consentimiento informado si aplica y la pieza presente; luego el procedimiento, su entrada de
 * evolución en el odontograma y el avance del plan.
 */
class PerformedProcedureService
{
    public const ABSENT_TOOTH_MESSAGE = 'La pieza figura como ausente en el odontograma; corrija el odontograma si corresponde.';

    public function __construct(
        private TreatmentPlanService $plans,
        private OdontogramEntryService $entries,
        private OdontogramStateService $odontogram,
        private ClinicalValidator $validator,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function record(PlanItem $item, Attention $attention, int $quantity, ?string $observations, User $dentist): PerformedProcedure
    {
        return DB::transaction(function () use ($item, $attention, $quantity, $observations, $dentist): PerformedProcedure {
            // Paso 1 (CA-39.3, RN-38).
            $item = PlanItem::query()->whereKey($item->id)->lockForUpdate()->with('procedure.resultingFinding', 'procedure.resultingFindingState')->firstOrFail();

            if ($item->status !== 'aceptado') {
                throw new BusinessRuleException('RN-38', 'El ítem no pertenece a un presupuesto aceptado.', status: 409);
            }

            $plan = TreatmentPlan::query()->whereKey($item->treatment_plan_id)->lockForUpdate()->with('patient')->firstOrFail();

            // Paso 2 (SRS §11.11 FE-4): atención abierta del paciente del plan.
            $attention = Attention::query()->whereKey($attention->id)->lockForUpdate()->firstOrFail();

            if ($attention->status !== 'abierta') {
                throw new BusinessRuleException('RF-126', 'La atención está cerrada; abra una nueva atención.', status: 409);
            }

            if ($attention->patient_id !== $plan->patient_id) {
                throw ValidationException::withMessages(['attention_id' => 'La atención no es del paciente del plan.']);
            }

            // Paso 3 (CA-39.4).
            $pending = $item->quantity - $item->performed_quantity;

            if ($quantity > $pending) {
                throw ValidationException::withMessages(['quantity' => "La cantidad supera la pendiente del ítem ({$pending})."]);
            }

            $consent = $this->usableInformedConsent($item);

            // Paso 5 (SRS §11.11 FE-3).
            if ($item->tooth !== null && $this->isAbsent($plan->patient, $item->tooth)) {
                throw new BusinessRuleException('RF-126', self::ABSENT_TOOTH_MESSAGE);
            }

            // Paso 6: el uuid de la entrada se fija antes porque el procedimiento es inmutable.
            $procedure = $item->procedure;
            $createsEntry = $procedure->resultingFinding !== null && $procedure->resultingFindingState !== null && $item->tooth !== null;

            $performed = new PerformedProcedure;
            $performed->forceFill([
                'patient_id' => $plan->patient_id,
                'plan_item_id' => $item->id,
                'attention_id' => $attention->id,
                'dentist_id' => $dentist->id,
                'quantity' => $quantity,
                'performed_at' => now(),
                'observations' => $observations,
                'informed_consent_id' => $consent?->id,
                'odontogram_entry_uuid' => $createsEntry ? (string) Str::uuid() : null,
            ])->save();

            // Paso 7 (RN-39, CA-39.1): sin hallazgo resultante no hay entrada (SRS §11.11 FA-2).
            if ($createsEntry && $performed->odontogram_entry_uuid !== null) {
                $this->entries->record($attention, [
                    'tooth' => (int) $item->tooth,
                    'surfaces' => $item->surfaces,
                    'finding_code' => $procedure->resultingFinding->code,
                    'state_code' => $procedure->resultingFindingState->code,
                ], $dentist, origin: 'procedimiento', performedProcedureId: $performed->id, uuid: $performed->odontogram_entry_uuid);
            }

            $consent?->forceFill(['status' => 'utilizado', 'used_at' => now()])->save();

            // Paso 8 (CA-39.2, RF-130).
            $performedQuantity = $item->performed_quantity + $quantity;
            $item->forceFill([
                'performed_quantity' => $performedQuantity,
                'status' => $performedQuantity === $item->quantity ? 'realizado' : 'aceptado',
            ])->save();
            $this->plans->recordProgress($plan);

            $this->audit->record(AuditEvent::ProcedurePerformed, $performed, meta: ['quantity' => $quantity]);

            return $performed;
        });
    }

    /**
     * Paso 4 (RN-76, RF-127): si el procedimiento lo exige, un consentimiento informado vigente del
     * ítem, firmado antes del procedimiento; se marca `utilizado` al registrarlo.
     *
     * @throws BusinessRuleException
     */
    private function usableInformedConsent(PlanItem $item): ?InformedConsent
    {
        if (! $item->procedure->requires_informed_consent) {
            return null;
        }

        $consent = InformedConsent::query()
            ->where('plan_item_id', $item->id)
            ->where('status', 'vigente')
            ->where('signed_at', '<=', now())
            ->orderByDesc('signed_at')
            ->lockForUpdate()
            ->first();

        return $consent ?? throw new BusinessRuleException('RN-76', 'Registre el consentimiento informado antes del procedimiento.');
    }

    /**
     * La pieza está ausente en el estado vigente (RN-24): un hallazgo `AUSENTE` en ella o un
     * `EDENTULO_TOTAL` cuyo tramo la incluye (NTS 188 §6.1.20 y §6.1.7).
     */
    private function isAbsent(Patient $patient, int $tooth): bool
    {
        return $this->odontogram->current($patient)->contains(fn (OdontogramEntry $entry): bool => match ($entry->finding?->code) {
            'AUSENTE' => $entry->tooth === $tooth,
            'EDENTULO_TOTAL' => $entry->tooth_end !== null && $this->validator->spanContains($entry->tooth, $entry->tooth_end, $tooth),
            default => false,
        });
    }
}
