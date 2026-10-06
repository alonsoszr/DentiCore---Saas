<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrar hallazgos (SDD §5.3; CUS-22; RF-087 a RF-091, RN-16 a RN-22, RN-25). El rol, el COP,
 * el consentimiento (a) y el paciente no bloqueado los verifican los middleware de la ruta.
 * Cada hallazgo es una entrada nueva e inmutable; la BD calcula su cadena de hashes.
 */
class OdontogramEntryService
{
    public function __construct(private ClinicalValidator $validator, private AuditLogger $audit) {}

    /**
     * @param  array{tooth: int, tooth_end?: int|null, surfaces?: list<string>|null, finding_code: string, state_code: string, note?: string|null}  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function record(Attention $attention, array $data, User $author): OdontogramEntry
    {
        return DB::transaction(function () use ($attention, $data, $author): OdontogramEntry {
            $status = Attention::query()->whereKey($attention->id)->lockForUpdate()->value('status');

            if ($status !== 'abierta') {
                throw new BusinessRuleException('RF-087', 'La atención está cerrada; abra una nueva atención.', status: 409);
            }

            [$finding, $state] = $this->validatedFinding($data);

            // RN-20, RN-21: inicial mientras el odontograma inicial de esta atención siga abierto.
            $initial = InitialOdontogram::query()->where('patient_id', $attention->patient_id)->lockForUpdate()->first();
            $isInitial = $initial !== null && $initial->status === 'abierto' && $initial->attention_id === $attention->id;

            $entry = new OdontogramEntry;
            $entry->forceFill([
                'patient_id' => $attention->patient_id,
                'chain_patient_id' => $attention->patient_id,
                'attention_id' => $attention->id,
                'initial_odontogram_id' => $isInitial ? $initial->id : null,
                'entry_type' => $isInitial ? 'inicial' : 'evolucion',
                ...$this->findingColumns($data, $finding, $state),
                'origin' => 'manual',
                'note' => $data['note'] ?? null,
                'author_id' => $author->id,
                'author_cop' => $author->cop_number,
                'recorded_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::OdontogramEntryAdded, $entry);

            return $entry;
        });
    }

    /**
     * Hallazgo y estado del catálogo por sus códigos, validados con ClinicalValidator (SDD §5.3
     * paso 3; RN-16 a RN-18, RN-25). Un código que no es del catálogo NTS 188 se rechaza.
     *
     * @param  array{tooth: int, tooth_end?: int|null, surfaces?: list<string>|null, finding_code: string, state_code: string}  $data
     * @return array{FindingCatalog, FindingState}
     *
     * @throws ValidationException
     */
    public function validatedFinding(array $data): array
    {
        $finding = FindingCatalog::query()->where('code', $data['finding_code'])->first();
        $state = $finding?->states()->where('code', $data['state_code'])->first();

        $errors = $this->validator->finding((int) $data['tooth'], $this->toothEnd($data), $data['surfaces'] ?? [], $finding, $state);

        if ($errors !== [] || $finding === null || $state === null) {
            throw ValidationException::withMessages($errors);
        }

        return [$finding, $state];
    }

    /**
     * Columnas del hallazgo ya validado; el color lo determina el estado (RN-17).
     *
     * @param  array{tooth: int, tooth_end?: int|null, surfaces?: list<string>|null}  $data
     * @return array<string, mixed>
     */
    public function findingColumns(array $data, FindingCatalog $finding, FindingState $state): array
    {
        return [
            'tooth' => (int) $data['tooth'],
            'tooth_end' => $this->toothEnd($data),
            'surfaces' => $data['surfaces'] ?? [],
            'finding_id' => $finding->id,
            'finding_state_id' => $state->id,
            'color' => $state->color,
        ];
    }

    /**
     * @param  array{tooth_end?: int|null}  $data
     */
    private function toothEnd(array $data): ?int
    {
        return isset($data['tooth_end']) ? (int) $data['tooth_end'] : null;
    }
}
