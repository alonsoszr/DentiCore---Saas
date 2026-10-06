<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\ClinicalNote;
use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentGate;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Http\BusinessRuleException;
use App\Support\Time\ClinicClock;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de la atención (SDD §5.2; CUS-25, CUS-26, CUS-27; RF-082, RF-094, RF-096,
 * RN-20, RN-77, RN-78). La vinculación con la cita (`en_atencion`, `atendida`) llega con la
 * agenda (TASK-064) y los mensajes de control periódico y encuesta con sus módulos.
 */
class AttentionService
{
    public function __construct(
        private ConsentGate $consents,
        private EvidenceSealer $sealer,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * CUS-25: abre la atención del odontólogo. No exige consentimiento (abrirla no es un dato
     * clínico); si falta o está desactualizado, devuelve el aviso para la respuesta (RN-10, RN-15).
     *
     * @return array{attention: Attention, consent_warning: array{rule: string, detail: string}|null}
     *
     * @throws BusinessRuleException
     */
    public function open(Patient $patient, User $dentist): array
    {
        match ($patient->archive_status) {
            'bloqueado' => throw new BusinessRuleException('RF-184', 'La ficha del paciente está bloqueada: no admite nuevas atenciones.'),
            'fusionado' => throw new BusinessRuleException('RF-075', 'La ficha del paciente se fusionó con otra: abra la atención en la ficha principal.'),
            default => null,
        };

        $attention = DB::transaction(function () use ($patient, $dentist): Attention {
            // Serializa las aperturas del paciente: la 2.ª atención abierta y el odontograma
            // inicial se deciden sin carreras (índices únicos como respaldo).
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();

            $alreadyOpen = Attention::query()
                ->where('patient_id', $patient->id)
                ->where('dentist_id', $dentist->id)
                ->where('status', 'abierta')
                ->exists();

            if ($alreadyOpen) {
                throw new BusinessRuleException('RF-082', 'El paciente ya tiene una atención abierta con usted.', status: 409);
            }

            $isFirst = ! InitialOdontogram::query()->where('patient_id', $patient->id)->exists();

            $attention = new Attention;
            $attention->forceFill([
                'patient_id' => $patient->id,
                'dentist_id' => $dentist->id,
                'status' => 'abierta',
                'is_first_attention' => $isFirst,
                'closed_by_system' => false,
                'opened_at' => now(),
            ])->save();

            if ($isFirst) {
                (new InitialOdontogram)->forceFill([
                    'patient_id' => $patient->id,
                    'attention_id' => $attention->id,
                    'status' => 'abierto',
                ])->save();
            }

            // RN-68: una nueva atención saca la ficha del archivo pasivo.
            Patient::query()->whereKey($patient->id)->where('archive_status', 'pasivo')->update(['archive_status' => 'activo']);

            $attention->setRelation('patient', $patient);
            $this->audit->record(AuditEvent::AttentionOpened, $attention);

            return $attention;
        });

        return ['attention' => $attention, 'consent_warning' => $this->consentWarning($patient)];
    }

    /**
     * CUS-26: cierra y firma la atención del odontólogo a cargo (SDD §5.2, RF-094).
     *
     * @throws BusinessRuleException
     */
    public function close(Attention $attention, User $signer): Attention
    {
        return DB::transaction(function () use ($attention, $signer): Attention {
            $attention = $this->lockOpen($attention)
                ?? throw new BusinessRuleException('RF-094', 'La atención ya está cerrada.', status: 409);

            $missing = $this->missingForClosing($attention);

            if ($missing !== []) {
                throw new BusinessRuleException(
                    'RN-77',
                    'Para cerrar la atención registre el motivo de consulta y al menos un diagnóstico CIE-10.',
                    $missing,
                );
            }

            $this->sign($attention, $signer, closedBySystem: false, initialClosedBy: 'cierre_atencion');
            $this->audit->record(AuditEvent::AttentionClosed, $attention);

            return $attention;
        });
    }

    /**
     * CUS-27 (RF-096, RN-20, RN-77): cierra las atenciones abiertas de la clínica cuyo día local
     * ya llegó a las 23:59. Las que cumplen RN-77 se firman a nombre del odontólogo a cargo; las
     * demás quedan `cerrada_incompleta` y se le avisa para que las complete con una adenda.
     * Repetirlo no duplica efectos.
     *
     * @return int Atenciones cerradas.
     */
    public function autoClose(Tenant $tenant): int
    {
        $clock = ClinicClock::for($tenant);
        $localNow = $clock->now();
        $today = $localNow->toDateString();
        $cutoff = $localNow->format('H:i') >= '23:59'
            ? $clock->startOfLocalDay($localNow->addDay()->toDateString())
            : $clock->startOfLocalDay($today);

        $closed = 0;

        Attention::query()
            ->where('status', 'abierta')
            ->where('opened_at', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$closed): void {
                $closed += DB::transaction(fn (): int => $this->autoCloseOne($id));
            });

        return $closed;
    }

    private function autoCloseOne(int $attentionId): int
    {
        $attention = $this->lockOpen(Attention::query()->findOrFail($attentionId));

        if ($attention === null) {
            return 0;
        }

        if ($this->missingForClosing($attention) === []) {
            $this->sign($attention, $attention->dentist, closedBySystem: true, initialClosedBy: 'cierre_automatico');
        } else {
            $attention->forceFill([
                'status' => 'cerrada_incompleta',
                'closed_at' => now(),
                'closed_by_system' => true,
            ])->save();
            $this->closeInitialOdontogram($attention, 'cierre_automatico');

            $this->notifications->notifyInApp(
                NotificationEvent::AtencionCierreIncompleto,
                $attention->dentist,
                ['attention_uuid' => $attention->uuid],
                dedupeKey: "atencion_cierre_incompleto:{$attention->uuid}",
            );
        }

        $this->audit->record(AuditEvent::AttentionAutoClosed, $attention, meta: ['status' => $attention->status]);

        return 1;
    }

    /**
     * La atención bloqueada para actualizar, o null si ya no está `abierta`.
     */
    private function lockOpen(Attention $attention): ?Attention
    {
        $locked = Attention::query()->whereKey($attention->id)->lockForUpdate()->firstOrFail();

        return $locked->status === 'abierta' ? $locked : null;
    }

    /**
     * RN-77: motivo de consulta y al menos un diagnóstico CIE-10.
     *
     * @return array<string, list<string>>
     */
    private function missingForClosing(Attention $attention): array
    {
        $missing = [];

        $hasChiefComplaint = trim((string) $attention->note?->chief_complaint) !== ''
            || $attention->addenda()->whereRaw("trim(coalesce(chief_complaint, '')) <> ''")->exists();

        if (! $hasChiefComplaint) {
            $missing['chief_complaint'] = ['Registre el motivo de consulta.'];
        }

        if (! $attention->diagnoses()->exists()) {
            $missing['diagnoses'] = ['Registre al menos un diagnóstico CIE-10.'];
        }

        return $missing;
    }

    /**
     * CUS-81 (RN-77, SDD §5.2 adenda): tras una adenda, una atención `cerrada_incompleta` con
     * motivo de consulta y al menos un diagnóstico pasa a `cerrada`, firmada por el autor de la
     * adenda. La nota original no cambia y la atención conserva su fecha de cierre. Se invoca
     * dentro de la transacción de la adenda.
     */
    public function completeWithAddendum(Attention $attention, User $author): bool
    {
        $attention = Attention::query()->whereKey($attention->id)->lockForUpdate()->firstOrFail();

        if ($attention->status !== 'cerrada_incompleta' || $this->missingForClosing($attention) !== []) {
            return false;
        }

        $this->sign($attention, $author, closedBySystem: $attention->closed_by_system, initialClosedBy: null, signNote: false);
        $this->audit->record(AuditEvent::AttentionClosed, $attention);

        return true;
    }

    /**
     * SDD §5.2 pasos 3–5 y 7: firma la nota y la atención, sella la evidencia, cierra el
     * odontograma inicial y registra la última atención del paciente.
     *
     * @param  'cierre_atencion'|'cierre_automatico'|null  $initialClosedBy  Null si ya se cerró.
     * @param  bool  $signNote  False cuando la atención ya no está abierta: la BD bloquea la nota (RN-78).
     */
    private function sign(Attention $attention, User $signer, bool $closedBySystem, ?string $initialClosedBy, bool $signNote = true): void
    {
        $now = now();

        if ($signNote) {
            ClinicalNote::query()->where('attention_id', $attention->id)->update(['status' => 'firmada', 'signed_at' => $now]);
        }

        $attention->forceFill([
            'status' => 'cerrada',
            'closed_at' => $attention->closed_at ?? $now,
            'closed_by_system' => $closedBySystem,
            'signed_by' => $signer->id,
            'signer_cop' => $signer->cop_number,
            'signed_at' => $now,
        ]);
        $attention->setRelation('signer', $signer);
        $attention->load(['note', 'diagnoses', 'addenda', 'patient']);
        $attention->evidence_hmac = $this->sealer->seal($attention->evidencePayload());
        $attention->save();

        if ($initialClosedBy !== null) {
            $this->closeInitialOdontogram($attention, $initialClosedBy);
        }
        Patient::query()->whereKey($attention->patient_id)->update(['last_attention_at' => $now]);
    }

    private function closeInitialOdontogram(Attention $attention, string $closedBy): void
    {
        InitialOdontogram::query()
            ->where('attention_id', $attention->id)
            ->where('status', 'abierto')
            ->update(['status' => 'cerrado', 'closed_at' => now(), 'closed_by' => $closedBy]);
    }

    /**
     * @return array{rule: string, detail: string}|null
     */
    private function consentWarning(Patient $patient): ?array
    {
        if (! $this->consents->allows($patient, 'atencion')) {
            return ['rule' => 'RN-10', 'detail' => 'El paciente no tiene un consentimiento de datos vigente: no podrá registrar datos clínicos.'];
        }

        if ($this->consents->outdated($patient)) {
            return ['rule' => 'RN-15', 'detail' => 'El consentimiento del paciente usa una versión anterior de la plantilla: renuévelo.'];
        }

        return null;
    }
}
