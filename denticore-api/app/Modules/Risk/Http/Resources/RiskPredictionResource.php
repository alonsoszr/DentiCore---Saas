<?php

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Services\RiskFeatureLabels;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Predicción de riesgo de caries con su explicación (SDD §4.3.9, §5.8.3; CUS-56; RN-62, RN-63,
 * RF-168). Visibilidad por rol (§3.5): el Administrador de Clínica ve nivel, confianza, explicación
 * global y leyendas; solo el odontólogo recibe la probabilidad, las 3 contribuciones a favor y en
 * contra y la explicación individual. `low_confidence` marca una confianza menor que 0,60. Las
 * contribuciones a favor y en contra omiten las variables sin valor (CPOD/ceod fuera de la
 * dentición y la estructura familiar de un adulto, DI-20): no aplican al paciente.
 *
 * @mixin RiskPrediction
 */
class RiskPredictionResource extends ApiResource
{
    public const LEGEND = 'Herramienta de apoyo; no constituye diagnóstico';

    public const LOW_CONFIDENCE = 0.60;

    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        $user = $request->user();
        $isDentist = $user instanceof User && $user->role === 'dentist';
        $individual = collect($this->explanation_individual);
        $applicable = $individual->filter(fn (array $item) => $item['value'] !== null);

        return [
            /** @var 'vigente'|'reemplazada'|'vencida' */
            'status' => $this->status,
            /** @var 'bajo'|'medio'|'alto' */
            'level' => $this->level,
            'confidence' => $this->confidence,
            'low_confidence' => (float) $this->confidence < self::LOW_CONFIDENCE,
            'stale' => $this->isStale(),
            'model_version' => $this->modelVersion->version,
            'thresholds' => ['medium' => $this->threshold_medium, 'high' => $this->threshold_high],
            'predicted_at' => $this->predicted_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'legend' => self::LEGEND,
            /** @var list<array{feature: string, label: string, mean_abs_shap: string}> */
            'explanation_global' => array_map(fn (array $item) => [
                'feature' => $item['feature'],
                'label' => RiskFeatureLabels::label($item['feature']),
                'mean_abs_shap' => sprintf('%.3f', $item['mean_abs_shap']),
            ], $this->explanation_global),
            'alert_id' => $this->alert?->uuid,
            'probability' => $this->when($isDentist, fn () => $this->probability),
            /** @var list<array{feature: string, label: string, value: string, shap: string}> */
            'top_increasing' => $this->when($isDentist, fn () => $applicable->filter(fn (array $item) => $item['shap'] > 0)
                ->sortByDesc('shap')->take(3)->map(fn (array $item) => $this->contribution($item))->values()->all()),
            /** @var list<array{feature: string, label: string, value: string, shap: string}> */
            'top_decreasing' => $this->when($isDentist, fn () => $applicable->filter(fn (array $item) => $item['shap'] < 0)
                ->sortBy('shap')->take(3)->map(fn (array $item) => $this->contribution($item))->values()->all()),
            /** @var list<array{feature: string, label: string, value: string, shap: string}> */
            'explanation_individual' => $this->when($isDentist, fn () => $individual->map(fn (array $item) => $this->contribution($item))->values()->all()),
        ];
    }

    /**
     * @param  array{feature: string, value: string|null, shap: float|int}  $item
     * @return array{feature: string, label: string, value: string, shap: string}
     */
    private function contribution(array $item): array
    {
        return [
            'feature' => $item['feature'],
            'label' => RiskFeatureLabels::label($item['feature']),
            'value' => RiskFeatureLabels::value($item['feature'], $item['value']),
            'shap' => sprintf('%.3f', $item['shap']),
        ];
    }
}
