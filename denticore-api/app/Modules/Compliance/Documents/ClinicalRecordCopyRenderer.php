<?php

namespace App\Modules\Compliance\Documents;

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Odontogram\Services\OdontogramStateService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Risk\Http\Resources\RiskPredictionResource;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Treatment\Documents\BudgetPdfRenderer;
use App\Modules\Treatment\Models\PerformedProcedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Files\DocumentRenderer;
use App\Support\Files\GeneratedDocument;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Copia completa de la historia clínica (RF-180; CUS-62, RN-70): ficha, consentimientos,
 * odontograma inicial y vigente con el gráfico NTS 188, historial por pieza, atenciones con notas,
 * diagnósticos y adendas, planes, presupuestos, procedimientos y predicciones. Reúne los datos de
 * los módulos construidos; MS-11 agrega los abonos y MS-12 las sugerencias de IA.
 */
class ClinicalRecordCopyRenderer implements DocumentRenderer
{
    /**
     * Filas del gráfico por cuadrante FDI (NTS 188): permanentes superiores, temporales superiores,
     * temporales inferiores y permanentes inferiores, de derecha a izquierda del paciente.
     *
     * @var list<list<int>>
     */
    public const CHART_ROWS = [
        [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28],
        [55, 54, 53, 52, 51, 61, 62, 63, 64, 65],
        [85, 84, 83, 82, 81, 71, 72, 73, 74, 75],
        [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38],
    ];

    public function __construct(private OdontogramStateService $odontogram) {}

    public function html(GeneratedDocument $document): string
    {
        $patient = $this->patient($document);
        $clock = ClinicClock::for($patient->tenant);
        $initial = $this->odontogram->initial($patient);
        $current = $this->odontogram->current($patient);

        return view('documents.clinical-record-copy', [
            'clinic' => $patient->tenant,
            'patient' => $patient,
            'generatedOn' => $clock->now()->format('d/m/Y H:i'),
            'consents' => $patient->consents,
            'initialEntries' => $initial['entries'] ?? null,
            'initialChart' => $initial === null ? null : self::chart($initial['entries']),
            'currentEntries' => $current,
            'currentChart' => self::chart($current),
            'toothHistory' => $this->toothHistory($patient),
            'attentions' => Attention::query()
                ->where('patient_id', $patient->id)
                ->with(['dentist', 'signer', 'note', 'diagnoses.cie10', 'addenda.author'])
                ->orderBy('opened_at')
                ->orderBy('id')
                ->get(),
            'plans' => TreatmentPlan::query()
                ->where('patient_id', $patient->id)
                ->with(['creator', 'items.procedure', 'budgets'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
            'procedures' => PerformedProcedure::query()
                ->where('patient_id', $patient->id)
                ->with(['planItem.procedure', 'dentist'])
                ->orderBy('performed_at')
                ->orderBy('id')
                ->get(),
            'predictions' => RiskPrediction::query()
                ->where('patient_id', $patient->id)
                ->orderBy('predicted_at')
                ->orderBy('id')
                ->get(),
            'riskLegend' => RiskPredictionResource::LEGEND,
            'lowConfidence' => RiskPredictionResource::LOW_CONFIDENCE,
            'date' => fn (?DateTimeInterface $instant): string => self::local($instant, $clock, 'd/m/Y'),
            'dateTime' => fn (?DateTimeInterface $instant): string => self::local($instant, $clock, 'd/m/Y H:i'),
            'money' => fn (string $amount): string => BudgetPdfRenderer::money($amount),
        ])->render();
    }

    public function fileName(GeneratedDocument $document): string
    {
        return 'historia-clinica-'.$document->uuid.'.pdf';
    }

    /**
     * Siglas y color de cada pieza con hallazgos para el gráfico NTS 188: rojo para lo que
     * requiere tratamiento, azul para lo que está en buen estado (RN-17).
     *
     * @param  Collection<int, OdontogramEntry>  $entries
     * @return array<int, list<array{label: string, color: string}>>
     */
    public static function chart(Collection $entries): array
    {
        $chart = [];

        foreach ($entries as $entry) {
            $label = $entry->findingState->acronym ?? $entry->finding->acronym ?? $entry->finding->code ?? '';
            $chart[$entry->tooth][] = ['label' => $label, 'color' => $entry->color ?? $entry->findingState->color ?? 'azul'];
        }

        return $chart;
    }

    /**
     * Instante en la zona de la clínica. Las columnas `date` (nacimiento, vigencias) no pasan por
     * aquí: se formatean tal cual para no correrlas un día.
     */
    private static function local(?DateTimeInterface $instant, ClinicClock $clock, string $format): string
    {
        return $instant === null ? '' : CarbonImmutable::instance($instant)->setTimezone($clock->timezone())->format($format);
    }

    /**
     * CUS-24 (RF-081): el historial de cada pieza con entradas, en orden de pieza.
     *
     * @return array<int, Collection<int, OdontogramEntry>>
     */
    private function toothHistory(Patient $patient): array
    {
        $teeth = OdontogramEntry::query()
            ->where('patient_id', $patient->id)
            ->distinct()
            ->orderBy('tooth')
            ->pluck('tooth');

        $history = [];
        foreach ($teeth as $tooth) {
            $history[(int) $tooth] = $this->odontogram->toothHistory($patient, (int) $tooth);
        }

        return $history;
    }

    private function patient(GeneratedDocument $document): Patient
    {
        return Patient::query()
            ->with(['tenant', 'representatives', 'consents.purposeRevocations', 'consents.legalRepresentative'])
            ->findOrFail($document->documentable_id);
    }
}
