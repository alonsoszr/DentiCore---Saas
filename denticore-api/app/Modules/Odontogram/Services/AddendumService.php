<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\AttentionAddendum;
use App\Modules\Odontogram\Models\AttentionDiagnosis;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Adendas (SDD §5.2; CUS-81; RF-097, RN-77, RN-78): información posterior al cierre, con autor y
 * su COP, sin modificar la nota original. Completa una atención `cerrada_incompleta`.
 */
class AddendumService
{
    public function __construct(private AttentionService $attentions, private AuditLogger $audit) {}

    /**
     * @param  array{text: string, chief_complaint?: string|null, diagnoses?: list<array{cie10_code: string, type: string}>}  $data
     *
     * @throws BusinessRuleException
     */
    public function add(Attention $attention, array $data, User $author): AttentionAddendum
    {
        return DB::transaction(function () use ($attention, $data, $author): AttentionAddendum {
            $status = Attention::query()->whereKey($attention->id)->lockForUpdate()->value('status');

            if (! in_array($status, ['cerrada', 'cerrada_incompleta'], true)) {
                throw new BusinessRuleException('RN-78', 'La atención sigue abierta: registre la información en la nota.', status: 409);
            }

            $chiefComplaint = trim((string) ($data['chief_complaint'] ?? ''));

            $addendum = new AttentionAddendum;
            $addendum->forceFill([
                'attention_id' => $attention->id,
                'text' => $data['text'],
                'chief_complaint' => $chiefComplaint === '' ? null : $chiefComplaint,
                'author_id' => $author->id,
                'author_cop' => $author->cop_number,
            ])->save();

            foreach ($data['diagnoses'] ?? [] as $diagnosis) {
                (new AttentionDiagnosis)->forceFill([
                    'attention_id' => $attention->id,
                    'cie10_code' => $diagnosis['cie10_code'],
                    'type' => $diagnosis['type'],
                    'origin' => 'adenda',
                    'addendum_id' => $addendum->id,
                    'created_by' => $author->id,
                ])->save();
            }

            $this->audit->record(AuditEvent::AddendumAdded, $attention, changedFields: ($data['diagnoses'] ?? []) === [] ? [] : ['diagnoses']);
            $this->attentions->completeWithAddendum($attention, $author);

            return $addendum;
        });
    }
}
