<?php

namespace App\Modules\Patients\Documents;

use App\Modules\Patients\Models\Consent;
use App\Support\Files\DocumentRenderer;
use App\Support\Files\GeneratedDocument;
use App\Support\Time\ClinicClock;

/**
 * Constancia PDF del consentimiento de datos (RF-066; DD-28, DD-46): finalidades otorgadas,
 * otorgante, canal, fecha en la zona de la clínica, versión y huella del texto, sello de
 * evidencia y el texto presentado.
 */
class ConsentCertificateRenderer implements DocumentRenderer
{
    /** Etiquetas de las finalidades de RN-11. */
    private const PURPOSE_LABELS = [
        'purpose_care' => '(a) Atención odontológica',
        'purpose_notifications' => '(b) Notificaciones',
        'purpose_ai' => '(c) Asistencia de IA generativa',
        'purpose_risk' => '(d) Predicción de riesgo',
        'purpose_surveys' => '(e) Encuestas',
    ];

    private const CHANNEL_LABELS = ['presencial' => 'Presencial', 'portal' => 'Portal del paciente', 'papel' => 'Formulario en papel'];

    public function html(GeneratedDocument $document): string
    {
        $consent = $this->consent($document);
        $clock = ClinicClock::for($consent->tenant);

        return view('documents.consent-certificate', [
            'consent' => $consent,
            'clinic' => $consent->tenant,
            'patient' => $consent->patient,
            'representative' => $consent->legalRepresentative,
            'grantedAt' => $consent->granted_at->copy()->setTimezone($clock->timezone())->format('d/m/Y H:i'),
            'purposes' => collect(self::PURPOSE_LABELS)->map(fn (string $label, string $column) => [
                'label' => $label,
                'granted' => (bool) $consent->getAttribute($column),
            ])->values()->all(),
            'channel' => self::CHANNEL_LABELS[$consent->channel] ?? $consent->channel,
        ])->render();
    }

    public function fileName(GeneratedDocument $document): string
    {
        return "constancia-consentimiento-{$this->consent($document)->uuid}.pdf";
    }

    private function consent(GeneratedDocument $document): Consent
    {
        return Consent::query()
            ->with(['tenant', 'patient', 'legalRepresentative'])
            ->findOrFail($document->documentable_id);
    }
}
