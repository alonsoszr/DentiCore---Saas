<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Files\DocumentService;
use App\Support\Files\GeneratedDocument;
use Illuminate\Support\Facades\DB;

/**
 * Copia de la historia clínica (SDD §4.3.10, §4.3.11; CUS-62, RF-180, RN-70): pide el PDF
 * `copia_hc` a la cola `documents`, como el del presupuesto, y deja `export.requested` en la
 * bitácora (RN-67). Se permite con la clínica `suspendida` (`tenant.readonly_ok`).
 */
class ClinicalRecordCopyService
{
    public const KIND = 'copia_hc';

    public function __construct(
        private DocumentService $documents,
        private AuditLogger $audit,
    ) {}

    public function request(Patient $patient, User $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($patient, $requester): GeneratedDocument {
            $document = $this->documents->request($patient, self::KIND, $requester);
            $this->audit->record(AuditEvent::ExportRequested, $patient, meta: ['kind' => self::KIND], actor: $requester);

            return $document;
        });
    }
}
