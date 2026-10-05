# Ficha del conjunto de datos sintético del modelo de riesgo de caries

Versión del conjunto: 1.0

Generador: `src/denticore_ml/dataset.py` (TASK-075). Semilla por defecto `20261005`, 20 000 filas.

```bash
python -m denticore_ml.dataset --rows 20000 --seed 20261005 --out build/dataset.csv
# imprime el dataset_hash (SHA-256 del CSV canónico)
```

## Advertencia (OUT-06, PQ-06, RR-03)

Los datos son **sintéticos**: no provienen de pacientes reales (RES-08). Las distribuciones y los efectos se tomaron de la base de conocimiento del proyecto o son supuestos declarados. Un AUC sobre este conjunto **no demuestra validez clínica**. La validación clínica prospectiva queda fuera del alcance (OUT-06). El modelo entrenado con estos datos no es un diagnóstico.

## Fuentes

| Clave | Artículo (`docs/Articulos/`) | Población |
| :-- | :-- | :-- |
| A1 | *Machine learning to predict untreated dental caries in adolescents* (2024), tabla 1 | 615 adolescentes de 12 años, Mato Grosso do Sul, Brasil |
| A2 | *Analysis of Machine Learning-Based Dental Caries Risk Prediction Model at Cairo Hospital, Egypt*, tabla 1 | 1056 pacientes de 0 a 60 años |
| A3 | *Early Childhood Predictors for Dental Caries: A Machine Learning Approach* | Cohorte de 1 a 5 años con 10 años de seguimiento, Brasil |
| A4 | *Risk prediction models for dental caries in children and adolescents: a systematic review and meta-analysis* | 6 modelos combinados: AUC 0,79 (IC 95 %: 0,73 a 0,84) |

"Supuesto" marca un valor sin cifra transferible en A1–A4.

## Variable objetivo

`new_lesion_12m`: al menos una lesión de caries nueva en 12 meses (DD-12). La incidencia media es **0,30** (supuesto). El intercepto del modelo generador se ajusta por bisección para alcanzarla.

## Variables

Columnas del CSV: `age_group`, las 12 variables del contrato (SDD §4.6) y `new_lesion_12m`.

| Variable | Distribución | Fuente |
| :-- | :-- | :-- |
| `age_years` | Por tramos: 3–10 20 %, 11–20 25 %, 21–30 20 %, 31–40 16 %, 41–50 10 %, 51–60 7 %, 61–80 2 % | A2 (el piso de 3 años y el tramo 61–80 son supuestos) |
| `age_group` | `menor` si la edad es menor de 18 años; si no, `adulto` | RN-12 |
| `cpod` | Poisson(0,5 + 0,12 × (edad − 6)), desde los 6 años | Supuesto |
| `ceod` | Poisson(1,5), hasta los 12 años | Supuesto |
| Dentición | Temporal < 6 años ≤ mixta < 13 años ≤ permanente. La temporal tiene solo `ceod`, la permanente solo `cpod` y la mixta ambos (DI-20) | Supuesto (DI-20 no fija edades) |
| `plaque_index_pct` | Normal(35, 18) recortada a 0–100, con 1 decimal | Supuesto |
| `active_lesions` | Poisson(0,8) | Supuesto |
| `sugar_between_meals` | Menor: 0,10 / 0,19 / 0,34 / 0,22 / 0,15. Adulto: 0,08 / 0,12 / 0,50 / 0,18 / 0,12 | A1 (consumo bajo, moderado o alto de alimentos no saludables) y A2 (dulces). El reparto entre categorías es un supuesto |
| `fluoride_toothpaste` | Verdadero en el 85 % | Supuesto (A3 y A4 la señalan como predictor frecuente) |
| `brushing_per_day` | Menor: 0,02 / 0,40 / 0,45 / 0,13. Adulto: 0,02 / 0,10 / 0,40 / 0,48 | A1 (≤ 1 vez al día 42 %) y A2 (1, 2 o 3 veces al día). Los repartos son supuestos |
| `dental_visits_per_year` | `<1` 40 %, `1` 40 %, `2+` 20 % | Supuesto |
| `education_level` | Sin estudios 3 %, primaria 17 %, secundaria 50 %, técnica 13 %, universitaria 17 % | A2 (primaria 17 %, secundaria 66 %, superior 17 %). Sin estudios y el reparto de la secundaria son supuestos |
| `employment_status` | Formal 55 %, informal 11 %, desempleado 7 %, otra 27 % | A2 (empleados, emprendedores, desempleo y estudiantes, normalizados). La correspondencia de categorías es un supuesto |
| `family_structure` | Solo menores: biparental 60 %, monoparental 20 %, extendida 15 %, otra 5 % | Supuesto |

## Modelo generador

La variable objetivo sale de una Bernoulli sobre σ(β₀ + Σ efectos). Los efectos están en log-odds.

| Efecto | Valor | Fuente |
| :-- | :-- | :-- |
| Azúcar entre comidas `2` | ln 4,41 | A1: OR crudo, consumo moderado vs bajo |
| Azúcar entre comidas `3` o `4+` | ln 6,53 | A1: OR crudo, consumo alto vs bajo |
| Cepillado `0` o `1` al día | ln 2,20 | A1: OR crudo, ≤ 1 vez vs ≥ 2 |
| Sin pasta fluorada | ln 2,66 | A1: OR crudo sin agua fluorada. Aplicarlo a la pasta es un supuesto |
| Educación sin estudios o primaria | ln 1,21 | A1: escolaridad de los padres ≤ 4 años |
| Desempleado | ln 1,58 | A1: bajo la línea de pobreza. Usarlo como desempleo es un supuesto |
| Empleo informal | ln 1,58 / 2 | Supuesto |
| Visitas `<1` al año | ln 1,5 | Supuesto (A3: las visitas no rutinarias son predictor) |
| Menor en familia monoparental | ln 1,3 | Supuesto |
| CPOD + ceod | 0,25 por diente | Supuesto (A3: la experiencia previa es el mejor predictor) |
| Lesiones activas | 0,60 por lesión | Supuesto |
| Índice de placa | 0,25 por cada 10 puntos | Supuesto (A3: placa ≥ 15 % de superficies) |

**Criterio de los efectos clínicos.** Los tres últimos efectos no tienen cifras transferibles. Se fijaron para que un XGBoost entrenado sobre el conjunto alcance un AUC de validación cercano al 0,79 combinado de A4. Con la semilla por defecto, el AUC es ≈ 0,78 (75/25 estratificado). Este AUC refleja el supuesto y no la exactitud clínica.

## Reproducibilidad (RNF-170)

- El generador usa `numpy.random.default_rng(semilla)`. La misma semilla y la misma versión del código producen el mismo CSV y el mismo `dataset_hash`.
- El CSV canónico se escribe sin índice, con saltos `\n`, decimales a 1 cifra y nulos vacíos.
- Cualquier cambio de distribución o de efecto requiere una nueva versión de esta ficha.
