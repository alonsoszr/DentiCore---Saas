<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Odontogram\Services\ClinicalValidator;
use App\Modules\Odontogram\Services\OdontogramStateService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan de tratamiento (SDD §5.4; CUS-33, CUS-40; RF-110, RF-111, RF-114, RF-129, RN-26, RN-27).
 * Los estados siguen SRS §5.5.2: una transición no definida responde 409 (RF-114). El plan y sus
 * ítems solo se editan en `borrador`; `aceptado` y `en_ejecucion` llegan con el presupuesto
 * (TASK-059) y el procedimiento realizado (TASK-061).
 *
 * @phpstan-type ItemData array{procedure_id: string, tooth?: int|null, surfaces?: list<string>|null, quantity?: int, session_number?: int|null, observations?: string|null, finding_ids?: list<string>}
 */
class TreatmentPlanService
{
    public function __construct(
        private ClinicalValidator $validator,
        private OdontogramStateService $odontogram,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{title: string, items?: list<ItemData>}  $data
     *
     * @throws ValidationException
     */
    public function create(Patient $patient, array $data, User $author): TreatmentPlan
    {
        return DB::transaction(function () use ($patient, $data, $author): TreatmentPlan {
            $plan = new TreatmentPlan;
            $plan->forceFill([
                'patient_id' => $patient->id,
                'title' => $data['title'],
                'status' => 'borrador',
                'origin' => 'manual',
                'created_by' => $author->id,
            ])->save();

            $this->insertItems($plan, $data['items'] ?? []);
            $this->audit->record(AuditEvent::PlanCreated, $plan);

            return $plan;
        });
    }

    /**
     * @param  array{title?: string}  $data
     *
     * @throws BusinessRuleException
     */
    public function update(TreatmentPlan $plan, array $data): TreatmentPlan
    {
        return DB::transaction(function () use ($plan, $data): TreatmentPlan {
            $plan = $this->lockedDraft($plan);
            $plan->forceFill(array_intersect_key($data, ['title' => true]))->save();

            return $plan;
        });
    }

    /**
     * RF-110, RF-111: agrega ítems `propuesto` al plan en borrador, vinculados a los hallazgos
     * rojos vigentes que atienden.
     *
     * @param  list<ItemData>  $items
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function addItems(TreatmentPlan $plan, array $items): TreatmentPlan
    {
        return DB::transaction(function () use ($plan, $items): TreatmentPlan {
            $plan = $this->lockedDraft($plan);
            $this->insertItems($plan, $items);

            return $plan;
        });
    }

    /**
     * @param  array{procedure_id?: string, tooth?: int|null, surfaces?: list<string>|null, quantity?: int, session_number?: int|null, observations?: string|null}  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function updateItem(PlanItem $item, array $data): PlanItem
    {
        return DB::transaction(function () use ($item, $data): PlanItem {
            $this->lockedDraft($item->plan);
            $this->ensureProposed($item);

            $procedure = array_key_exists('procedure_id', $data) ? $this->activeProcedure($data['procedure_id']) : $item->procedure;
            $tooth = array_key_exists('tooth', $data) ? $data['tooth'] : $item->tooth;
            $surfaces = array_key_exists('surfaces', $data) ? $data['surfaces'] ?? [] : $item->surfaces;
            $errors = $procedure === null
                ? ['procedure_id' => 'El procedimiento no existe en el catálogo de la clínica.']
                : $this->validator->procedureSite($tooth, $surfaces, $procedure->requires_tooth, $procedure->requires_surface);

            if ($errors !== [] || $procedure === null) {
                throw ValidationException::withMessages($errors);
            }

            $item->forceFill([
                ...array_intersect_key($data, array_flip(['quantity', 'session_number', 'observations'])),
                'procedure_id' => $procedure->id,
                'tooth' => $tooth,
                'surfaces' => $surfaces,
            ])->save();

            return $item;
        });
    }

    /**
     * Quita un ítem `propuesto` del plan en borrador y sus vínculos con hallazgos, que vuelven a
     * quedar pendientes de decisión (RN-27).
     *
     * @throws BusinessRuleException
     */
    public function deleteItem(PlanItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $this->lockedDraft($item->plan);
            $this->ensureProposed($item);

            if (BudgetLine::query()->where('plan_item_id', $item->id)->exists()) {
                throw new BusinessRuleException('RF-114', 'El ítem figura en un presupuesto del plan: descártelo en lugar de quitarlo.', status: 409);
            }

            $item->findings()->detach();
            $item->delete();
        });
    }

    /**
     * Borrador → propuesto, con al menos un ítem `propuesto` (SRS §5.5.2).
     *
     * @throws BusinessRuleException
     */
    public function propose(TreatmentPlan $plan): TreatmentPlan
    {
        return DB::transaction(function () use ($plan): TreatmentPlan {
            $plan = $this->lockedForTransition($plan, 'propuesto');

            if (! $plan->items()->where('status', 'propuesto')->exists()) {
                throw new BusinessRuleException('RF-114', 'Agregue al menos un ítem al plan antes de proponerlo.');
            }

            return $this->changeStatus($plan, 'propuesto');
        });
    }

    /**
     * Propuesto → borrador, sin un presupuesto `emitido` vigente del plan (SRS §5.5.2).
     *
     * @throws BusinessRuleException
     */
    public function reopen(TreatmentPlan $plan): TreatmentPlan
    {
        return DB::transaction(function () use ($plan): TreatmentPlan {
            $plan = $this->lockedForTransition($plan, 'borrador');

            if ($plan->budgets()->where('status', 'emitido')->where('expires_at', '>', now())->exists()) {
                throw new BusinessRuleException('RF-114', 'El plan tiene un presupuesto emitido vigente: no puede volver a editarse.', status: 409);
            }

            return $this->changeStatus($plan, 'borrador');
        });
    }

    /**
     * CUS-40 (RF-129): `propuesto` o `aceptado` → `descartado` con motivo. Si era el último ítem
     * pendiente de un plan en ejecución, el plan se completa (CA-39.2).
     *
     * @throws BusinessRuleException
     */
    public function discardItem(PlanItem $item, string $reason): PlanItem
    {
        return DB::transaction(function () use ($item, $reason): PlanItem {
            $plan = TreatmentPlan::query()->whereKey($item->treatment_plan_id)->lockForUpdate()->firstOrFail();
            $item = PlanItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (in_array($plan->status, ['completado', 'cancelado'], true) || ! in_array($item->status, ['propuesto', 'aceptado'], true)) {
                throw new BusinessRuleException('RF-114', "Transición no definida del ítem: {$item->status} → descartado.", status: 409);
            }

            $item->forceFill(['status' => 'descartado', 'discard_reason' => $reason])->save();

            $finished = ! $plan->items()->whereNotIn('status', ['realizado', 'descartado'])->exists();

            if ($plan->status === 'en_ejecucion' && $finished) {
                $plan->completed_at = now();
                $this->changeStatus($plan, 'completado');
            }

            return $item;
        });
    }

    /**
     * RF-129: lo que quedaría al cancelar (ítems y valor realizados frente al monto aceptado).
     *
     * @return array{items_total: int, items_performed: int, performed_amount: string, accepted_amount: string|null}
     *
     * @throws BusinessRuleException
     */
    public function cancellationPreview(TreatmentPlan $plan): array
    {
        $this->ensureTransition($plan, 'cancelado');

        return $plan->load(['items', 'acceptedBudget.lines'])->progress();
    }

    /**
     * CUS-40 (RF-129): cualquier estado no final → `cancelado`, con motivo.
     *
     * @throws BusinessRuleException
     */
    public function cancel(TreatmentPlan $plan, string $reason): TreatmentPlan
    {
        return DB::transaction(function () use ($plan, $reason): TreatmentPlan {
            $plan = $this->lockedForTransition($plan, 'cancelado');
            $plan->forceFill(['cancel_reason' => $reason, 'cancelled_at' => now()]);

            return $this->changeStatus($plan, 'cancelado');
        });
    }

    /**
     * @param  list<ItemData>  $items
     *
     * @throws ValidationException
     */
    private function insertItems(TreatmentPlan $plan, array $items): void
    {
        if ($items === []) {
            return;
        }

        $findingIds = collect($items)->pluck('finding_ids')->flatten()->filter()->all();
        $redFindings = $findingIds === [] ? collect() : $this->currentRedFindings($plan);
        $errors = [];
        $rows = [];

        foreach ($items as $index => $data) {
            $procedure = $this->activeProcedure($data['procedure_id']);
            $tooth = $data['tooth'] ?? null;
            $surfaces = $data['surfaces'] ?? [];

            $itemErrors = $procedure === null
                ? ['procedure_id' => 'El procedimiento no existe en el catálogo de la clínica.']
                : $this->validator->procedureSite($tooth, $surfaces, $procedure->requires_tooth, $procedure->requires_surface);

            foreach ($data['finding_ids'] ?? [] as $findingId) {
                if (! $redFindings->has($findingId)) {
                    $itemErrors['finding_ids'] = 'El hallazgo no es un hallazgo rojo vigente del paciente.';
                }
            }

            foreach ($itemErrors as $field => $message) {
                $errors["items.{$index}.{$field}"] = $message;
            }

            $rows[] = [$data, $procedure, $tooth, $surfaces];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $position = (int) $plan->items()->max('position');

        foreach ($rows as [$data, $procedure, $tooth, $surfaces]) {
            $item = new PlanItem;
            $item->forceFill([
                'treatment_plan_id' => $plan->id,
                'position' => ++$position,
                'procedure_id' => $procedure?->id,
                'tooth' => $tooth,
                'surfaces' => $surfaces,
                'quantity' => $data['quantity'] ?? 1,
                'session_number' => $data['session_number'] ?? null,
                'observations' => $data['observations'] ?? null,
                'status' => 'propuesto',
                'origin' => 'manual',
            ])->save();

            $item->findings()->attach(array_values(array_unique($data['finding_ids'] ?? [])), ['tenant_id' => $plan->tenant_id]);
        }
    }

    /**
     * RN-27: hallazgos rojos del estado vigente del paciente del plan, por uuid. Bloquea los
     * registros del odontograma del paciente para no cruzarse con una decisión de no tratar.
     *
     * @return Collection<string, OdontogramEntry>
     */
    private function currentRedFindings(TreatmentPlan $plan): Collection
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["odontogram_entries:{$plan->patient_id}"]);

        return $this->odontogram->current($plan->patient)->where('color', 'rojo')->keyBy('uuid')->toBase();
    }

    private function activeProcedure(string $uuid): ?Procedure
    {
        return Procedure::query()->where('uuid', $uuid)->where('is_active', true)->first();
    }

    /**
     * @throws BusinessRuleException
     */
    private function lockedDraft(TreatmentPlan $plan): TreatmentPlan
    {
        $plan = TreatmentPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

        if ($plan->status !== 'borrador') {
            throw new BusinessRuleException('RF-114', 'Solo se edita un plan en borrador; vuelva a editarlo primero.', status: 409);
        }

        return $plan;
    }

    /**
     * @throws BusinessRuleException
     */
    private function ensureProposed(PlanItem $item): void
    {
        if ($item->status !== 'propuesto') {
            throw new BusinessRuleException('RF-114', "El ítem está {$item->status}: no puede modificarse.", status: 409);
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function lockedForTransition(TreatmentPlan $plan, string $status): TreatmentPlan
    {
        $plan = TreatmentPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
        $this->ensureTransition($plan, $status);

        return $plan;
    }

    /**
     * @throws BusinessRuleException
     */
    private function ensureTransition(TreatmentPlan $plan, string $status): void
    {
        if (! $plan->canTransitionTo($status)) {
            throw new BusinessRuleException('RF-114', "Transición no definida del plan: {$plan->status} → {$status}.", status: 409);
        }
    }

    private function changeStatus(TreatmentPlan $plan, string $status): TreatmentPlan
    {
        $from = $plan->status;
        $plan->forceFill(['status' => $status])->save();
        $this->audit->record(AuditEvent::PlanStatusChanged, $plan, ['status'], ['from' => $from, 'to' => $status]);

        return $plan;
    }
}
