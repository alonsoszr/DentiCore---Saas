<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Services\ConsentGate;
use App\Modules\Platform\Services\ClinicSettingsService;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\BudgetLine;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Files\DocumentService;
use App\Support\Files\GeneratedDocument;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Emisión del presupuesto (SDD §5.4.2 pasos 4 a 8; CUS-35; RF-116, RF-118, RN-32, RN-33, RN-35,
 * DD-18, DD-23). Todo ocurre en una transacción: si un procedimiento está inactivo no se emite
 * nada y el borrador queda igual. Emitido, la BD rechaza cambios de montos y líneas (RN-34).
 */
class BudgetIssuer
{
    public function __construct(
        private BudgetService $budgets,
        private ClinicSettingsService $settings,
        private DocumentService $documents,
        private NotificationService $notifications,
        private ConsentGate $consents,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws BusinessRuleException
     */
    public function issue(Budget $budget, User $actor): Budget
    {
        return DB::transaction(function () use ($budget, $actor): Budget {
            $budget = $this->budgets->lockedDraft($budget);
            $lines = $budget->lines()->with('procedure')->get();
            $this->ensureActiveProcedures($lines->all());

            // Paso 5 (RN-33): precio vigente congelado en cada línea y parámetros de la clínica.
            foreach ($lines as $line) {
                $line->forceFill(['unit_price' => $line->procedure->price])->save();
            }

            $settings = $this->settings->current();
            $budget->forceFill([
                'prices_include_igv' => $settings->prices_include_igv,
                'igv_rate' => $this->budgets->platformIgvRate(),
                'validity_days' => $settings->budget_validity_days,
                'terms_snapshot' => $settings->budget_terms,
            ]);
            $this->budgets->recalculate($budget);

            // Pasos 6 y 7 (DD-23, RN-35): número correlativo de la clínica y vencimiento al fin
            // del día local de emisión + vigencia.
            $clock = ClinicClock::for(TenantContext::tenantOrFail());
            $issuedAt = now();
            $expiryDate = CarbonImmutable::parse($clock->localDate($issuedAt))->addDays($settings->budget_validity_days)->toDateString();

            $budget->forceFill([
                'number' => $this->nextNumber(),
                'issued_at' => $issuedAt,
                'expires_at' => $clock->endOfLocalDay($expiryDate),
                'issued_by' => $actor->id,
                'dentist_id' => $budget->plan->created_by,
                'pdf_document_id' => $this->requestPdf($budget, $actor)->id,
                'status' => 'emitido',
            ])->save();

            $this->notifyPatient($budget);
            $this->audit->record(AuditEvent::BudgetIssued, $budget, meta: ['number' => $budget->number]);

            return $budget;
        });
    }

    /**
     * FE-4: tras agotar los reintentos, el PDF fallido se vuelve a pedir; el presupuesto apunta al
     * documento nuevo. Solo se regenera un PDF `fallido`.
     *
     * @throws BusinessRuleException
     */
    public function regeneratePdf(Budget $budget, User $actor): GeneratedDocument
    {
        return DB::transaction(function () use ($budget, $actor): GeneratedDocument {
            $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->with('pdfDocument')->firstOrFail();

            if ($budget->status === 'borrador' || $budget->pdfDocument?->status !== 'fallido') {
                throw new BusinessRuleException('RF-118', 'Solo se regenera el PDF de un presupuesto emitido cuya generación falló.', status: 409);
            }

            $document = $this->requestPdf($budget, $actor);
            $budget->forceFill(['pdf_document_id' => $document->id])->save();

            return $document;
        });
    }

    /**
     * Paso 4 (RN-32): la lista completa de líneas con procedimiento inactivo; si hay alguna, 422 y
     * no cambia nada.
     *
     * @param  list<BudgetLine>  $lines
     *
     * @throws BusinessRuleException
     */
    private function ensureActiveProcedures(array $lines): void
    {
        $errors = [];

        foreach ($lines as $line) {
            if (! $line->procedure->is_active) {
                $errors["lines.{$line->uuid}"] = ["El procedimiento «{$line->procedure->name}» está inactivo en el catálogo."];
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleException('RN-32', 'El presupuesto tiene procedimientos inactivos: no se emitió ninguna parte.', $errors);
        }
    }

    /**
     * Paso 6 (DD-23): `P-%06d` desde la secuencia de la clínica, bajo bloqueo de fila.
     */
    private function nextNumber(): string
    {
        $sequence = DB::table('document_sequences')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('doc_type', 'presupuesto')
            ->lockForUpdate()
            ->first(['id', 'last_value']);

        $next = (int) $sequence->last_value + 1;
        DB::table('document_sequences')->where('id', $sequence->id)->update(['last_value' => $next, 'updated_at' => now()]);

        return sprintf('P-%06d', $next);
    }

    /**
     * Paso 8 (DD-18): el PDF se genera en la cola `documents` con 3 intentos (GenerateDocumentJob
     * con BudgetPdfRenderer), a partir del mensaje de outbox de esta transacción.
     */
    private function requestPdf(Budget $budget, User $actor): GeneratedDocument
    {
        return $this->documents->request($budget, 'presupuesto', $actor);
    }

    /**
     * Paso 8 (CUS-52): `presupuesto_emitido` solo si el paciente otorgó la finalidad
     * «notificaciones»; el correo no lleva datos clínicos ni de identificación (RNF-110).
     */
    private function notifyPatient(Budget $budget): void
    {
        $patient = $budget->patient;

        if (! $this->consents->allows($patient, 'notificaciones')) {
            return;
        }

        $clock = ClinicClock::for(TenantContext::tenantOrFail());

        $this->notifications->sendEmail(NotificationEvent::PresupuestoEmitido, $patient, data: [
            'number' => $budget->number,
            'total' => $budget->total,
            'expires_on' => $budget->expires_at?->copy()->setTimezone($clock->timezone())->format('d/m/Y'),
        ], links: [
            'budgets' => rtrim((string) config('app.spa_url'), '/').'/c/'.TenantContext::tenantOrFail()->slug.'/portal/presupuestos',
        ], dedupeKey: "presupuesto_emitido:{$budget->uuid}");
    }
}
