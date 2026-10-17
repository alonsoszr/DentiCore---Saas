# Ficha técnica del modelo de riesgo de caries 1.0.0 (provisional)

## Uso previsto

Estimar la probabilidad de al menos una lesión de caries nueva en 12 meses para priorizar la prevención (DD-12). Apoya la decisión del odontólogo: **no es un diagnóstico**.

Contrato del motor: versión 1.0 (SDD §4.6).

## Población

- Conjunto sintético versión 1.0, 20000 filas, `dataset_hash` 2a463110f5bed87e58222e75fb976d5344540270189fd6847b1ccf603041c1e0.
- Pacientes sintéticos de 3 a 80 años, menores y adultos, con las distribuciones de estudios de Brasil y Egipto (`docs/dataset_card.md`); no se tomaron de una población peruana.
- Validación: 4000 filas (adulto 2536, menor 1464).
- Un AUC sobre datos sintéticos no demuestra validez clínica (OUT-06).

## Variables

`age_years`, `cpod`, `ceod`, `plaque_index_pct`, `active_lesions`, `sugar_between_meals`, `fluoride_toothpaste`, `brushing_per_day`, `dental_visits_per_year`, `education_level`, `employment_status`, `family_structure`

## Métricas de validación

- AUC-ROC: 0.7760 (mínimo para activar: 0.75; RN-84)
- Brier: 0.1666
- ECE (10 intervalos): 0.0130
- Umbrales: medio ≥ 0.3, alto ≥ 0.6

## Métricas por subgrupo (RNF-167)

| Subgrupo | Valor | n | AUC | Tasa de nivel alto |
| :-- | :-- | --: | --: | --: |
| age_group | adulto | 2536 | 0.7781 | 0.135 |
| age_group | menor | 1464 | 0.7726 | 0.086 |
| education_level | primaria | 737 | 0.7924 | 0.140 |
| education_level | secundaria | 2008 | 0.7690 | 0.118 |
| education_level | sin_estudios | 116 | 0.8175 | 0.103 |
| education_level | tecnica | 502 | 0.7717 | 0.117 |
| education_level | universitaria | 637 | 0.7769 | 0.091 |
| employment_status | desempleado | 261 | 0.7605 | 0.172 |
| employment_status | formal | 2247 | 0.7872 | 0.116 |
| employment_status | informal | 427 | 0.7597 | 0.141 |
| employment_status | otra | 1065 | 0.7596 | 0.097 |

## Entrenamiento (RNF-170)

- Semilla: 20261005; commit: 1cf970b; entrenado: 2026-10-17T14:30:29+00:00.
- Hiperparámetros: `{"colsample_bytree": 0.9, "learning_rate": 0.05, "max_depth": 3, "min_child_weight": 1.0, "n_estimators": 300, "reg_lambda": 1.0, "subsample": 0.9}`.
- XGBoost (60 %), calibración isotónica (20 %) y validación (20 %), con partición estratificada.

## Limitaciones

- Entrenado solo con datos sintéticos (PQ-06); requiere validación clínica prospectiva (OUT-06).
- Los supuestos del conjunto condicionan las métricas y la importancia de las variables.
- La población sintética no representa a los pacientes de las clínicas del Perú.
