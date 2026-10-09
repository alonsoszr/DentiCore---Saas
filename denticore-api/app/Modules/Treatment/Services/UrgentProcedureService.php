<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Treatment\Models\PerformedProcedure;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Procedimiento de urgencia (SDD §5.5; CUS-39 FA-1; RF-012, RF-128, RN-38): en una sola
 * transacción crea el plan (`origin = urgencia`) con el ítem, lo propone, emite el presupuesto,
 * registra la aceptación presencial y el procedimiento. Si un paso falla, no queda nada. Si el
 * procedimiento exige consentimiento informado, falla con 422: se registra con el flujo normal.
 *
 * @phpstan-type UrgentData array{procedure_id: string, tooth?: int|null, surfaces?: list<string>|null, quantity?: int, observations?: string|null, signer: 'titular'|'representante', signer_document_number: string}
 */
class UrgentProcedureService
{
    public function __construct(
        private TreatmentPlanService $plans,
        private BudgetService $budgets,
        private BudgetIssuer $issuer,
        private BudgetDecisionService $decisions,
        private PerformedProcedureService $performed,
    ) {}

    /**
     * @param  UrgentData  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function perform(Attention $attention, array $data, User $dentist, ?string $ip, ?string $userAgent): PerformedProcedure
    {
        return DB::transaction(function () use ($attention, $data, $dentist, $ip, $userAgent): PerformedProcedure {
            $attention = Attention::query()->whereKey($attention->id)->lockForUpdate()->with('patient')->firstOrFail();

            if ($attention->status !== 'abierta') {
                throw new BusinessRuleException('RF-126', 'La atención está cerrada; abra una nueva atención.', status: 409);
            }

            $quantity = $data['quantity'] ?? 1;
            $plan = $this->createPlan($attention, $data, $quantity, $dentist);
            $plan = $this->plans->propose($plan);

            $budget = $this->issuer->issue($this->budgets->createDraft($plan, null), $dentist);
            $this->decisions->decide($budget, [
                'decision' => 'aceptado',
                'signer' => $data['signer'],
                'signer_document_number' => $data['signer_document_number'],
            ], $dentist, $ip, $userAgent);

            return $this->performed->record($plan->items()->sole(), $attention, $quantity, $data['observations'] ?? null, $dentist);
        });
    }

    /**
     * Plan con el único ítem de la urgencia; los errores del ítem se informan con los nombres de
     * campo de esta solicitud (`tooth`, no `items.0.tooth`).
     *
     * @param  UrgentData  $data
     *
     * @throws ValidationException
     */
    private function createPlan(Attention $attention, array $data, int $quantity, User $dentist): TreatmentPlan
    {
        $procedureName = Procedure::query()->where('uuid', $data['procedure_id'])->value('name') ?? '';

        try {
            return $this->plans->create($attention->patient, [
                'title' => Str::limit("Urgencia: {$procedureName}", 150, ''),
                'items' => [[
                    'procedure_id' => $data['procedure_id'],
                    'tooth' => $data['tooth'] ?? null,
                    'surfaces' => $data['surfaces'] ?? [],
                    'quantity' => $quantity,
                ]],
            ], $dentist, 'urgencia');
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[Str::after($key, 'items.0.')] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
