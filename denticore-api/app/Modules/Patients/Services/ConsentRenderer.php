<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Texto del consentimiento (SDD §5.10 «Preparar texto»; DD-28, DD-42, RF-047, CA-17.5): completa
 * la plantilla de plataforma con los datos de la clínica, del Oficial de Datos Personales, de
 * las transferencias y del titular. Las finalidades (c) y (d) se omiten si la clínica no puede
 * ofrecerlas.
 */
class ConsentRenderer
{
    /** Finalidades opcionales y la línea de la plantilla que las describe. */
    private const PURPOSE_LINES = ['purpose_ai' => '(c)', 'purpose_risk' => '(d)'];

    /**
     * Columnas de finalidad que se pueden otorgar en la clínica. (c) solo si el plan incluye IA
     * y la clínica la activó; (d) solo si el plan incluye la predicción de riesgo (RN-11, RN-53).
     *
     * @return list<string>
     */
    public function availablePurposes(Tenant $tenant): array
    {
        $plan = $tenant->plan;
        $aiEnabled = $plan?->includes_ai === true && $tenant->clinicSettings?->ai_enabled === true;

        return array_values(array_filter([
            'purpose_care',
            'purpose_notifications',
            $aiEnabled ? 'purpose_ai' : null,
            $plan?->includes_risk === true ? 'purpose_risk' : null,
            'purpose_surveys',
        ]));
    }

    /**
     * @return array{text: string, text_sha256: string}
     */
    public function render(int $version, Tenant $tenant, Patient $patient): array
    {
        $body = (string) DB::table('consent_templates')->where('version', $version)->value('body');
        $available = $this->availablePurposes($tenant);

        $lines = array_filter(
            preg_split('/\R/', $body) ?: [],
            fn (string $line) => ! collect(self::PURPOSE_LINES)
                ->contains(fn (string $marker, string $column) => str_starts_with(ltrim($line), $marker) && ! in_array($column, $available, true)),
        );

        $text = strtr(implode("\n", $lines), [
            '{{clinica.razon_social}}' => $tenant->legal_name ?? $tenant->name,
            '{{clinica.ruc}}' => (string) $tenant->ruc,
            '{{clinica.direccion}}' => (string) $tenant->address,
            '{{oficial.contacto}}' => $this->officerContact($tenant),
            '{{titular.nombre}}' => trim("{$patient->first_name} {$patient->last_name}"),
            '{{transferencias}}' => (string) config('services.consent.transfers'),
        ]);

        return ['text' => $text, 'text_sha256' => hash('sha256', $text)];
    }

    /**
     * RF-047: «Nombre (correo)» de los Oficiales designados; sin designación, el correo de
     * contacto de la clínica.
     */
    private function officerContact(Tenant $tenant): string
    {
        $officers = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_data_officer', true)
            ->where('status', '!=', 'inactivo')
            ->orderBy('name')
            ->get(['name', 'email']);

        if ($officers->isEmpty()) {
            return (string) $tenant->contact_email;
        }

        return $officers->map(fn (User $officer) => "{$officer->name} ({$officer->email})")->implode('; ');
    }
}
