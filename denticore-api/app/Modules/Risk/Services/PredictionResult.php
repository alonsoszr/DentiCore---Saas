<?php

namespace App\Modules\Risk\Services;

use App\Modules\Risk\Models\RiskPrediction;

/**
 * Resultado de CUS-55: una predicción nueva (201), la misma de hace menos de 60 s (DD-27, 200) o
 * «Predicción no disponible» si el motor no respondió o no hay versión activa (RN-64, FE-4).
 */
final readonly class PredictionResult
{
    public function __construct(public ?RiskPrediction $prediction, public bool $created = false) {}

    public static function unavailable(): self
    {
        return new self(null);
    }

    public function isUnavailable(): bool
    {
        return $this->prediction === null;
    }
}
