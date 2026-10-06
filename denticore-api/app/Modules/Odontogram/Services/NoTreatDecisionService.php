<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\FindingNoTreatDecision;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Models\PlanItem;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgos pendientes de decisión y decisión de no tratar (SDD §5.4; CUS-34; RF-112, RF-113,
 * RN-27). Un hallazgo rojo vigente queda resuelto si lo atiende un ítem vigente (no descartado,
 * de un plan no cancelado) o si tiene una decisión de no tratar.
 */
class NoTreatDecisionService
{
    public function __construct(private OdontogramStateService $state, private AuditLogger $audit) {}

    /**
     * RF-112: hallazgos rojos del estado vigente (RN-24) sin ítem de plan ni decisión de no tratar.
     *
     * @return Collection<int, OdontogramEntry>
     */
    public function pending(Patient $patient): Collection
    {
        $red = $this->state->current($patient)->where('color', 'rojo');
        $resolved = array_flip($this->resolved($red->pluck('uuid')->all()));

        return $red->reject(fn (OdontogramEntry $entry) => isset($resolved[$entry->uuid]))->values();
    }

    /**
     * RF-113: registra la decisión sobre un hallazgo rojo vigente y pendiente; motivo ≥ 10
     * caracteres (lo valida la solicitud y el CHECK de la BD).
     *
     * @throws BusinessRuleException
     */
    public function record(OdontogramEntry $entry, string $reason, User $author): FindingNoTreatDecision
    {
        return DB::transaction(function () use ($entry, $reason, $author): FindingNoTreatDecision {
            // Misma llave que los registros del odontograma y los vínculos del plan del paciente.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["odontogram_entries:{$entry->patient_id}"]);

            $isCurrentRed = $this->state->current($entry->patient)
                ->contains(fn (OdontogramEntry $current) => $current->id === $entry->id && $current->color === 'rojo');

            if (! $isCurrentRed) {
                throw new BusinessRuleException('RN-27', 'Solo se decide no tratar un hallazgo rojo vigente del odontograma.');
            }

            if (FindingNoTreatDecision::query()->where('odontogram_entry_uuid', $entry->uuid)->exists()) {
                throw new BusinessRuleException('RN-27', 'El hallazgo ya tiene una decisión de no tratar.', status: 409);
            }

            if ($this->linked([$entry->uuid]) !== []) {
                throw new BusinessRuleException('RN-27', 'El hallazgo ya está vinculado a un ítem del plan de tratamiento.', status: 409);
            }

            $decision = new FindingNoTreatDecision;
            $decision->forceFill([
                'odontogram_entry_uuid' => $entry->uuid,
                'patient_id' => $entry->patient_id,
                'reason' => $reason,
                'decided_by' => $author->id,
            ])->save();

            $this->audit->record(AuditEvent::FindingNoTreat, $decision);

            return $decision;
        });
    }

    /**
     * Uuids de las entradas con decisión de no tratar o con un ítem vigente.
     *
     * @param  list<string>  $uuids
     * @return list<string>
     */
    private function resolved(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $decided = FindingNoTreatDecision::query()->whereIn('odontogram_entry_uuid', $uuids)->pluck('odontogram_entry_uuid')->all();

        return array_values(array_unique([...$decided, ...$this->linked($uuids)]));
    }

    /**
     * Uuids de las entradas que atiende un ítem no descartado de un plan no cancelado (RN-27).
     *
     * @param  list<string>  $uuids
     * @return list<string>
     */
    private function linked(array $uuids): array
    {
        return PlanItem::query()
            ->join('plan_item_findings', 'plan_item_findings.plan_item_id', '=', 'plan_items.id')
            ->whereIn('plan_item_findings.odontogram_entry_uuid', $uuids)
            ->where('plan_items.status', '<>', 'descartado')
            ->whereHas('plan', fn (Builder $plan) => $plan->where('status', '<>', 'cancelado'))
            ->pluck('plan_item_findings.odontogram_entry_uuid')
            ->all();
    }
}
