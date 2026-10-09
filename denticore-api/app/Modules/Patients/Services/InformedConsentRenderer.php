<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\InformedConsentTemplateVersion;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Models\PlanItem;

/**
 * Texto del consentimiento informado (DD-28, DD-42, RF-073): completa la plantilla asociada al
 * procedimiento con los datos del paciente, el ítem, los riesgos y alternativas que escribe la
 * recepción y el odontólogo que informa.
 */
class InformedConsentRenderer
{
    /**
     * @param  array{riesgos: string, alternativas: string}  $fills
     * @return array{text: string, text_sha256: string}
     */
    public function render(InformedConsentTemplateVersion $version, Patient $patient, PlanItem $item, ?User $informedBy, array $fills): array
    {
        $text = strtr($version->body, [
            '{{paciente}}' => trim("{$patient->first_name} {$patient->last_name}"),
            '{{procedimiento}}' => $item->procedure->name,
            '{{pieza}}' => $item->tooth === null ? '' : (string) $item->tooth,
            '{{riesgos}}' => $fills['riesgos'],
            '{{alternativas}}' => $fills['alternativas'],
            '{{odontologo}}' => $informedBy === null ? '' : trim((string) $informedBy->name),
        ]);

        return ['text' => $text, 'text_sha256' => hash('sha256', $text)];
    }
}
