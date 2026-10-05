<?php

namespace App\Modules\Patients\Services;

use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Punto único de decisión sobre el consentimiento de datos (SDD §5.10; RN-10, RN-13, RN-14,
 * RN-15). Cada uso de una finalidad la consulta en el momento, por lo que una revocación tiene
 * efecto inmediato.
 */
class ConsentGate
{
    /** CA-64.3: estados de archivo con los que ninguna finalidad está habilitada. */
    public const DISABLED_ARCHIVE_STATUSES = ['bloqueado', 'fusionado'];

    private ?int $templateVersion = null;

    /**
     * Versión vigente de la plantilla de plataforma (`platform_settings.consent.current_version`).
     * Se lee una vez por solicitud o job: la clase se registra como `scoped`.
     */
    public function currentTemplateVersion(): int
    {
        return $this->templateVersion ??= (int) json_decode((string) DB::table('platform_settings')
            ->where('key', 'consent.current_version')
            ->value('value'));
    }

    /**
     * Consentimiento `vigente` que habilita al paciente. RN-13: el que otorgó un representante
     * deja de habilitarlo cuando cumple 18 años; desde entonces necesita uno propio.
     */
    public function current(Patient $patient): ?Consent
    {
        /** @var Consent|null $consent */
        $consent = $patient->relationLoaded('currentConsent') ? $patient->currentConsent : $patient->currentConsent()->first();

        if ($consent === null || ($consent->granted_by === 'representante' && ! $patient->isMinorOn())) {
            return null;
        }

        return $consent;
    }

    public function hasCurrent(Patient $patient): bool
    {
        return $this->current($patient) !== null;
    }

    /**
     * RN-15, RF-067: el consentimiento vigente usa una versión anterior de la plantilla.
     */
    public function outdated(Patient $patient): bool
    {
        $consent = $this->current($patient);

        return $consent !== null && $consent->consent_template_version < $this->currentTemplateVersion();
    }

    /**
     * La finalidad (`atencion`, `notificaciones`, `ia`, `prediccion`, `encuestas`) está otorgada
     * en el consentimiento vigente y no fue revocada; el paciente no está bloqueado ni fusionado.
     */
    public function allows(Patient $patient, string $purpose): bool
    {
        $column = Consent::PURPOSES[$purpose] ?? throw new InvalidArgumentException("Finalidad desconocida: {$purpose}.");

        if (in_array($patient->archive_status, self::DISABLED_ARCHIVE_STATUSES, true)) {
            return false;
        }

        $consent = $this->current($patient);

        if ($consent === null || $consent->getAttribute($column) !== true) {
            return false;
        }

        return ! DB::table('consent_purpose_revocations')
            ->where('tenant_id', $consent->tenant_id)
            ->where('consent_id', $consent->id)
            ->where('purpose', $purpose)
            ->exists();
    }
}
