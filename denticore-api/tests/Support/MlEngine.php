<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Motor ML simulado según el contrato 1.0 (SDD §4.6). La respuesta devuelve el `request_ref` y la
 * versión recibidos y una contribución SHAP por cada variable enviada; `$overrides` cambia
 * cualquier clave (p. ej. `probability` o `contract_version`).
 */
final class MlEngine
{
    public const URL = '*/v1/predict';

    /** Descarta las respuestas simuladas anteriores: `Http::fake` acumula y gana la primera. */
    public static function reset(): void
    {
        Http::swap(new Factory);
    }

    /**
     * Las 12 variables de un adulto sintético (RES-08).
     *
     * @return array<string, mixed>
     */
    public static function features(): array
    {
        return [
            'age_years' => 34, 'cpod' => 3, 'ceod' => null, 'plaque_index_pct' => 42.5, 'active_lesions' => 2,
            'sugar_between_meals' => '3', 'fluoride_toothpaste' => true, 'brushing_per_day' => '2',
            'dental_visits_per_year' => '1', 'education_level' => 'secundaria', 'employment_status' => 'informal',
            'family_structure' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function predicts(float $probability = 0.2, array $overrides = []): void
    {
        Http::fake([self::URL => fn (Request $request) => Http::response(self::response($request->data(), $probability, $overrides))]);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function response(array $request, float $probability, array $overrides = []): array
    {
        $features = (array) ($request['features'] ?? []);
        $shap = [
            'active_lesions' => 0.521, 'sugar_between_meals' => 0.304, 'plaque_index_pct' => 0.12, 'cpod' => 0.08,
            'fluoride_toothpaste' => -0.188, 'brushing_per_day' => -0.11, 'dental_visits_per_year' => -0.05,
        ];

        return [
            'contract_version' => '1.0',
            'request_ref' => $request['request_ref'] ?? null,
            'model_version' => $request['model_version'] ?? null,
            'probability' => $probability,
            'confidence' => max($probability, 1 - $probability),
            'base_value' => -0.847298,
            'explanation' => [
                'global' => [['feature' => 'sugar_between_meals', 'mean_abs_shap' => 0.412], ['feature' => 'active_lesions', 'mean_abs_shap' => 0.377]],
                'individual' => array_map(
                    fn (string $feature) => ['feature' => $feature, 'value' => $features[$feature], 'shap' => $shap[$feature] ?? 0.01],
                    array_keys($features),
                ),
            ],
            ...$overrides,
        ];
    }

    /**
     * Catálogo de `GET /v1/models` con una versión y las métricas de la ficha (RN-84).
     *
     * @param  array<string, mixed>  $metrics
     */
    public static function publishes(string $version, array $metrics = []): void
    {
        Http::fake(['*/v1/models' => Http::response(['models' => [[
            'version' => $version,
            'contract_version' => '1.0',
            'trained_at' => '2026-10-05T12:00:00+00:00',
            'dataset_version' => '1.0',
            'dataset_hash' => str_repeat('a', 64),
            'dataset_rows' => 20000,
            'seed' => 20261005,
            'code_commit' => str_repeat('b', 40),
            'hyperparameters' => ['max_depth' => 4],
            'thresholds' => ['medium' => 0.3, 'high' => 0.6],
            'metrics' => ['auc_validation' => 0.7812, 'brier_score' => 0.1601, 'ece' => 0.0151, ...$metrics],
            'subgroup_metrics' => ['age_group' => ['menor' => ['auc' => 0.77, 'n' => 1500]]],
        ]]])]);
    }
}
