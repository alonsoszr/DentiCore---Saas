<?php

namespace App\Modules\Risk\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Captura de variables de riesgo (SDD §5.8.1; CUS-54; RF-161, RF-162, RF-164, RN-58). Las secciones
 * llegan ya validadas y autorizadas por rol (RiskVariableSetPolicy):
 *
 * - Se escribe sobre el conjunto más reciente del paciente. Si está bloqueado por una predicción
 *   se crea otro con `supersedes_id` que copia las secciones no editadas y su captura (RF-164).
 * - Las secciones clínica y conductual registran al odontólogo en `clinical_captured_*`; la
 *   sociodemográfica a la recepción en `sociodemographic_captured_*`.
 * - El grupo etario y la edad se recalculan al capturar (DD-12, RN-12) y `is_complete` con
 *   RiskVariableValidator.
 */
class RiskVariableSetService
{
    public const SECTIONS = ['clinical', 'behavioral', 'sociodemographic'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function latest(Patient $patient): ?RiskVariableSet
    {
        return RiskVariableSet::query()->where('patient_id', $patient->id)->latest('id')->first();
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     *
     * @throws BusinessRuleException
     */
    public function upsert(Patient $patient, array $sections, User $user, ?RiskVariableSet $target = null): RiskVariableSet
    {
        $set = DB::transaction(function () use ($patient, $sections, $user, $target) {
            $current = $this->latest($patient);

            if ($target !== null && $current?->id !== $target->id) {
                throw new BusinessRuleException('RF-164', 'Este registro de variables fue reemplazado; edite el más reciente.', status: 409);
            }

            $set = $current === null || $current->isLocked() ? $this->successor($patient, $current) : $current;

            foreach ($sections as $section => $values) {
                $set->{$section} = $values;
            }

            if (array_intersect(['clinical', 'behavioral'], array_keys($sections)) !== []) {
                $set->clinical_captured_by = $user->id;
                $set->clinical_captured_at = now()->utc();
            }
            if (isset($sections['sociodemographic'])) {
                $set->sociodemographic_captured_by = $user->id;
                $set->sociodemographic_captured_at = now()->utc();
            }

            $set->age_years = $patient->ageYears();
            $set->age_group = $patient->isMinorOn() ? 'menor' : 'adulto';
            $set->is_complete = RiskVariableValidator::missing($set) === [];
            $set->save();

            return $set;
        });

        $this->audit->record(AuditEvent::RiskVariablesCaptured, $set, changedFields: array_keys($sections));

        return $set;
    }

    /** Conjunto nuevo; si reemplaza a uno bloqueado, copia sus secciones y su captura (RF-164). */
    private function successor(Patient $patient, ?RiskVariableSet $previous): RiskVariableSet
    {
        $set = new RiskVariableSet;
        $set->forceFill(['patient_id' => $patient->id]);

        if ($previous !== null) {
            $set->forceFill([
                'supersedes_id' => $previous->id,
                ...$previous->only([
                    'clinical', 'behavioral', 'sociodemographic',
                    'clinical_captured_by', 'clinical_captured_at', 'sociodemographic_captured_by', 'sociodemographic_captured_at',
                ]),
            ]);
        }

        return $set;
    }
}
