<?php

namespace App\Modules\Risk\Services;

/**
 * Respuesta validada de `POST /v1/predict` (SDD §4.6): probabilidad calibrada, confianza,
 * log-odds base y explicación global e individual, con la referencia de un solo uso enviada.
 */
final readonly class EnginePrediction
{
    /**
     * @param  list<array{feature: string, mean_abs_shap: float}>  $explanationGlobal
     * @param  list<array{feature: string, value: mixed, shap: float}>  $explanationIndividual
     */
    public function __construct(
        public string $requestRef,
        public float $probability,
        public float $confidence,
        public float $baseValue,
        public array $explanationGlobal,
        public array $explanationIndividual,
        public int $latencyMs,
    ) {}
}
