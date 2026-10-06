<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrar una corrección (SDD §5.3; CUS-23; RF-093, RN-22, RN-23): una entrada nueva de tipo
 * `correccion` que referencia a la original, que queda intacta. Una entrada admite una sola
 * corrección; una corrección errónea se corrige a sí misma (SRS §11.6 FA-1).
 *
 * La corrección se asocia a la atención de la entrada original (`attention_id` es obligatorio y
 * CUS-23 no exige una atención abierta).
 */
class OdontogramCorrectionService
{
    public function __construct(private OdontogramEntryService $entries, private AuditLogger $audit) {}

    /**
     * @param  array{kind: 'anulacion'|'reemplazo', reason: string, tooth?: int|null, tooth_end?: int|null, surfaces?: list<string>|null, finding_code?: string|null, state_code?: string|null}  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function correct(OdontogramEntry $original, array $data, User $author): OdontogramEntry
    {
        return DB::transaction(function () use ($original, $data, $author): OdontogramEntry {
            // Serializa las correcciones del paciente: la tabla particionada no admite UNIQUE
            // sobre corrects_entry_id (SDD §2.6). Es la misma llave que la cadena de hashes.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["odontogram_entries:{$original->patient_id}"]);

            if (OdontogramEntry::query()->where('corrects_entry_id', $original->id)->exists()) {
                throw new BusinessRuleException('RN-23', 'Esta entrada ya tiene una corrección; corrija la corrección.', status: 409);
            }

            $correction = new OdontogramEntry;
            $correction->forceFill([
                'patient_id' => $original->patient_id,
                'chain_patient_id' => $original->patient_id,
                'attention_id' => $original->attention_id,
                'entry_type' => 'correccion',
                ...$this->correctedData($original, $data),
                'origin' => 'manual',
                'corrects_entry_id' => $original->id,
                'correction_kind' => $data['kind'],
                'correction_reason' => $data['reason'],
                'author_id' => $author->id,
                'author_cop' => $author->cop_number,
                'recorded_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::OdontogramEntryCorrected, $correction, meta: ['kind' => $data['kind']]);

            return $correction;
        });
    }

    /**
     * Anulación: la pieza y las superficies de la original, sin hallazgo. Reemplazo: los datos
     * correctos, validados como en CUS-22.
     *
     * @param  array{kind: string, tooth?: int|null, tooth_end?: int|null, surfaces?: list<string>|null, finding_code?: string|null, state_code?: string|null}  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function correctedData(OdontogramEntry $original, array $data): array
    {
        if ($data['kind'] === 'anulacion') {
            return [
                'tooth' => $original->tooth,
                'tooth_end' => $original->tooth_end,
                'surfaces' => $original->surfaces,
                'finding_id' => null,
                'finding_state_id' => null,
                'color' => null,
            ];
        }

        $replacement = [
            'tooth' => (int) ($data['tooth'] ?? 0),
            'tooth_end' => $data['tooth_end'] ?? null,
            'surfaces' => $data['surfaces'] ?? [],
            'finding_code' => (string) ($data['finding_code'] ?? ''),
            'state_code' => (string) ($data['state_code'] ?? ''),
        ];
        [$finding, $state] = $this->entries->validatedFinding($replacement);

        return $this->entries->findingColumns($replacement, $finding, $state);
    }
}
