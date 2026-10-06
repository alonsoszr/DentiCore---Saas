<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\AttentionDiagnosis;
use App\Modules\Odontogram\Models\ClinicalNote;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Nota de atención y diagnósticos CIE-10 (SDD §5.2; CUS-80; RF-084, RF-085, RN-77, RN-78).
 * Solo cambian con la atención `abierta` y la nota sin firmar; después, la información
 * posterior va en una adenda (AddendumService).
 */
class ClinicalNoteService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * PUT idempotente de la nota `borrador`: las secciones que no llegan quedan vacías.
     *
     * @param  array<string, string|null>  $sections
     *
     * @throws BusinessRuleException
     */
    public function save(Attention $attention, array $sections): ClinicalNote
    {
        return DB::transaction(function () use ($attention, $sections): ClinicalNote {
            $this->lockOpen($attention);

            $note = ClinicalNote::query()->where('attention_id', $attention->id)->lockForUpdate()->first()
                ?? (new ClinicalNote)->forceFill(['attention_id' => $attention->id, 'status' => 'borrador']);

            if ($note->status === 'firmada') {
                throw $this->closedAttention();
            }

            $note->forceFill(collect(ClinicalNote::SECTIONS)->mapWithKeys(fn (string $section) => [$section => $sections[$section] ?? null])->all());
            // Secciones que cambiaron; en una nota nueva, las que llegaron con contenido.
            $changed = array_values(array_filter(
                ClinicalNote::SECTIONS,
                fn (string $section) => $note->exists ? $note->isDirty($section) : $note->getAttribute($section) !== null,
            ));
            $note->save();

            $this->audit->record(AuditEvent::NoteSaved, $attention, changedFields: $changed);

            return $note;
        });
    }

    /**
     * Diagnóstico de la nota (`origin = nota`), con la atención abierta.
     *
     * @throws BusinessRuleException
     */
    public function addDiagnosis(Attention $attention, string $code, string $type, User $author): AttentionDiagnosis
    {
        return DB::transaction(function () use ($attention, $code, $type, $author): AttentionDiagnosis {
            $this->lockOpen($attention);

            $diagnosis = new AttentionDiagnosis;
            $diagnosis->forceFill([
                'attention_id' => $attention->id,
                'cie10_code' => $code,
                'type' => $type,
                'origin' => 'nota',
                'created_by' => $author->id,
            ])->save();

            // RN-67: la bitácora no guarda el código CIE-10 (dato clínico), solo el campo.
            $this->audit->record(AuditEvent::DiagnosisAdded, $attention, changedFields: ['diagnoses']);

            return $diagnosis;
        });
    }

    /**
     * Quita un diagnóstico de la nota mientras la atención sigue abierta (RN-78).
     *
     * @throws BusinessRuleException
     */
    public function removeDiagnosis(Attention $attention, AttentionDiagnosis $diagnosis): void
    {
        DB::transaction(function () use ($attention, $diagnosis): void {
            $this->lockOpen($attention);

            $diagnosis->delete();

            $this->audit->record(AuditEvent::NoteSaved, $attention, changedFields: ['diagnoses']);
        });
    }

    /**
     * @throws BusinessRuleException
     */
    private function lockOpen(Attention $attention): void
    {
        $status = Attention::query()->whereKey($attention->id)->lockForUpdate()->value('status');

        if ($status !== 'abierta') {
            throw $this->closedAttention();
        }
    }

    private function closedAttention(): BusinessRuleException
    {
        return new BusinessRuleException('RN-78', 'La atención está cerrada: registre la información en una adenda.', status: 409);
    }
}
