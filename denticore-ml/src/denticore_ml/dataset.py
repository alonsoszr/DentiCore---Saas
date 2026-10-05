"""Conjunto de datos sintético del modelo de riesgo (TASK-075; PQ-06, DD-12, OUT-06).

Generador determinista: la misma semilla produce el mismo conjunto y el mismo `dataset_hash`
(RNF-170). Las distribuciones y los efectos citan la base de conocimiento (A1–A4) o son un
supuesto declarado; el detalle está en `docs/dataset_card.md`. Un AUC sobre estos datos no
demuestra validez clínica (OUT-06).

Uso: `python -m denticore_ml.dataset --rows 20000 --seed 20261005 --out build/dataset.csv`
"""

import argparse
import hashlib
import math
from collections.abc import Mapping, Sequence
from pathlib import Path

import numpy as np
import pandas as pd

from denticore_ml.features import CATEGORIES, FEATURES

DATASET_VERSION = "1.0"
DEFAULT_ROWS = 20_000
DEFAULT_SEED = 20_261_005
TARGET = "new_lesion_12m"

# Supuesto: incidencia a 12 meses de al menos una lesión nueva (variable objetivo de DD-12).
TARGET_INCIDENCE = 0.30

# Supuesto (DI-20 no fija edades): temporal < 6 años ≤ mixta < 13 años ≤ permanente.
MIXED_DENTITION_FROM = 6
PERMANENT_DENTITION_FROM = 13

# A2 (Cairo, n = 1056, tabla 1): 0–10 20 %, 11–20 25 %, 21–30 20 %, 31–40 16 %, 41–50 10 %,
# 51–60 7 %. Supuestos: desde 3 años y el 2 % restante entre 61 y 80.
AGE_BINS: tuple[tuple[int, int, float], ...] = (
    (3, 10, 0.20),
    (11, 20, 0.25),
    (21, 30, 0.20),
    (31, 40, 0.16),
    (41, 50, 0.10),
    (51, 60, 0.07),
    (61, 80, 0.02),
)

# Frecuencias por grupo etario, en el orden de CATEGORIES.
SUGAR_PROBS = {
    # A1 (adolescentes, tabla 1, sin faltantes): baja 28,9 %, moderada 33,8 %, alta 37,3 %.
    # Supuesto: baja → "0"/"1", moderada → "2", alta → "3"/"4+".
    "menor": (0.10, 0.19, 0.34, 0.22, 0.15),
    # A2 (dulces): rara vez 20 %, habitual 50 %, frecuente 30 %, con el mismo reparto.
    "adulto": (0.08, 0.12, 0.50, 0.18, 0.12),
}
BRUSHING_PROBS = {
    # A1: una vez o ninguna 42 %, dos o más 58 % (sin faltantes). Supuesto: reparto interno.
    "menor": (0.02, 0.40, 0.45, 0.13),
    # A2: una vez 10 %, dos 40 %, tres 50 %. Supuesto: 2 % sin cepillado (tomado de "3+").
    "adulto": (0.02, 0.10, 0.40, 0.48),
}
# Supuesto: sin cifra comparable en A1–A4 (A2 solo informa control alguna vez, 95 %).
VISITS_PROBS = (0.40, 0.40, 0.20)
# A2: primaria 17 %, secundaria (junior + senior) 66 %, superior 17 %. Supuestos: 3 % sin
# estudios y la secundaria repartida en secundaria 50 % y técnica 13 %.
EDUCATION_PROBS = (0.03, 0.17, 0.50, 0.13, 0.17)
# A2 (ocupación, normalizado sobre el 91 % informado): empleados 55 %, emprendedores 11 %,
# desempleo 7 %, estudiantes 27 %. Supuesto: correspondencia con formal/informal/desempleado/otra.
EMPLOYMENT_PROBS = (0.55, 0.11, 0.07, 0.27)
# Supuesto: sin cifra en A1–A4.
FAMILY_PROBS = (0.60, 0.20, 0.15, 0.05)
# Supuesto: sin cifra en A1–A4 (A3 y A4 la señalan como predictor).
FLUORIDE_TOOTHPASTE_PROB = 0.85

# Efectos en log-odds. A1: OR crudos de la tabla 1 (prevalencia de caries no tratada).
EFFECT_SUGAR = (0.0, 0.0, math.log(4.41), math.log(6.53), math.log(6.53))  # A1: moderada, alta vs baja
EFFECT_LOW_BRUSHING = math.log(2.20)  # A1: ≤ 1 vez al día vs ≥ 2
EFFECT_NO_FLUORIDE = math.log(2.66)  # A1: sin agua fluorada (supuesto: aplicado a la pasta)
EFFECT_LOW_EDUCATION = math.log(1.21)  # A1: escolaridad de los padres ≤ 4 años
EFFECT_UNEMPLOYED = math.log(1.58)  # A1: bajo la línea de pobreza (supuesto: desempleo)
EFFECT_INFORMAL = math.log(1.58) / 2  # Supuesto: mitad del efecto anterior
EFFECT_FEW_VISITS = math.log(1.5)  # Supuesto (A3: visitas no rutinarias como predictor)
EFFECT_SINGLE_PARENT = math.log(1.3)  # Supuesto
# Supuestos clínicos (A3: la experiencia de caries es el mejor predictor y la placa ≥ 15 % de
# superficies predice lesiones nuevas). Sin cifras transferibles, se fijan para que un XGBoost
# sobre el conjunto alcance un AUC cercano al 0,79 combinado del metaanálisis A4.
EFFECT_CARIES_INDEX = 0.25  # por diente cariado, perdido u obturado (CPOD + ceod)
EFFECT_ACTIVE_LESION = 0.60  # por lesión activa
EFFECT_PLAQUE_10PCT = 0.25  # por cada 10 puntos del índice de placa


def generate(rows: int = DEFAULT_ROWS, seed: int = DEFAULT_SEED) -> pd.DataFrame:
    """Columnas: `age_group`, las 12 variables de `FEATURES` y la variable objetivo."""
    rng = np.random.default_rng(seed)

    age = _ages(rng, rows)
    minor = age < 18
    group = np.where(minor, "menor", "adulto")

    has_ceod = age < PERMANENT_DENTITION_FROM
    has_cpod = age >= MIXED_DENTITION_FROM
    ceod = np.clip(rng.poisson(1.5, rows), 0, 20)  # Supuesto
    cpod = np.clip(rng.poisson(0.5 + 0.12 * np.maximum(age - MIXED_DENTITION_FROM, 0)), 0, 32)  # Supuesto
    plaque = np.round(np.clip(rng.normal(35.0, 18.0, rows), 0.0, 100.0), 1)  # Supuesto
    active_lesions = np.clip(rng.poisson(0.8, rows), 0, 32)  # Supuesto

    sugar = _by_group(rng, minor, SUGAR_PROBS)
    brushing = _by_group(rng, minor, BRUSHING_PROBS)
    fluoride = rng.random(rows) < FLUORIDE_TOOTHPASTE_PROB
    visits = rng.choice(len(VISITS_PROBS), rows, p=VISITS_PROBS)
    education = rng.choice(len(EDUCATION_PROBS), rows, p=EDUCATION_PROBS)
    employment = rng.choice(len(EMPLOYMENT_PROBS), rows, p=EMPLOYMENT_PROBS)
    family = rng.choice(len(FAMILY_PROBS), rows, p=FAMILY_PROBS)

    caries_index = np.where(has_cpod, cpod, 0) + np.where(has_ceod, ceod, 0)
    linear = (
        EFFECT_CARIES_INDEX * caries_index
        + EFFECT_ACTIVE_LESION * active_lesions
        + EFFECT_PLAQUE_10PCT * plaque / 10
        + np.asarray(EFFECT_SUGAR)[sugar]
        + EFFECT_LOW_BRUSHING * (brushing <= 1)
        + EFFECT_NO_FLUORIDE * ~fluoride
        + EFFECT_FEW_VISITS * (visits == 0)
        + EFFECT_LOW_EDUCATION * (education <= 1)
        + EFFECT_UNEMPLOYED * (employment == 2)
        + EFFECT_INFORMAL * (employment == 1)
        + EFFECT_SINGLE_PARENT * (minor & (family == 1))
    )
    probability = _sigmoid(linear + _intercept(linear, TARGET_INCIDENCE))
    target = (rng.random(rows) < probability).astype(int)

    return pd.DataFrame(
        {
            "age_group": group,
            "age_years": age,
            "cpod": pd.Series(cpod, dtype="Int64").where(has_cpod),
            "ceod": pd.Series(ceod, dtype="Int64").where(has_ceod),
            "plaque_index_pct": plaque,
            "active_lesions": active_lesions,
            "sugar_between_meals": _labels("sugar_between_meals", sugar),
            "fluoride_toothpaste": fluoride,
            "brushing_per_day": _labels("brushing_per_day", brushing),
            "dental_visits_per_year": _labels("dental_visits_per_year", visits),
            "education_level": _labels("education_level", education),
            "employment_status": _labels("employment_status", employment),
            "family_structure": pd.Series(_labels("family_structure", family)).where(minor, None),
            TARGET: target,
        },
        columns=["age_group", *FEATURES, TARGET],
    )


def to_csv(frame: pd.DataFrame) -> str:
    """CSV canónico: el mismo conjunto produce siempre los mismos bytes."""
    return frame.to_csv(index=False, lineterminator="\n", float_format="%.1f")


def dataset_hash(frame: pd.DataFrame) -> str:
    return hashlib.sha256(to_csv(frame).encode("utf-8")).hexdigest()


def file_hash(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main(argv: Sequence[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description="Genera el conjunto sintético e imprime su dataset_hash.")
    parser.add_argument("--rows", type=int, default=DEFAULT_ROWS)
    parser.add_argument("--seed", type=int, default=DEFAULT_SEED)
    parser.add_argument("--out", type=Path, required=True)
    args = parser.parse_args(argv)

    frame = generate(rows=args.rows, seed=args.seed)
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_bytes(to_csv(frame).encode("utf-8"))
    print(dataset_hash(frame))


def _ages(rng: np.random.Generator, rows: int) -> np.ndarray:
    bins = rng.choice(len(AGE_BINS), rows, p=[weight for _, _, weight in AGE_BINS])
    low = np.array([start for start, _, _ in AGE_BINS])[bins]
    high = np.array([end for _, end, _ in AGE_BINS])[bins]
    return rng.integers(low, high + 1)


def _by_group(rng: np.random.Generator, minor: np.ndarray, probs: Mapping[str, tuple[float, ...]]) -> np.ndarray:
    size = len(minor)
    for_minors = rng.choice(len(probs["menor"]), size, p=probs["menor"])
    for_adults = rng.choice(len(probs["adulto"]), size, p=probs["adulto"])
    return np.where(minor, for_minors, for_adults)


def _labels(name: str, codes: np.ndarray) -> np.ndarray:
    labels: np.ndarray = np.asarray(CATEGORIES[name], dtype=object)[codes]
    return labels


def _sigmoid(values: np.ndarray) -> np.ndarray:
    result: np.ndarray = 1.0 / (1.0 + np.exp(-values))
    return result


def _intercept(linear: np.ndarray, target: float) -> float:
    """Intercepto que lleva la probabilidad media a `target` (bisección determinista)."""
    low, high = -20.0, 20.0
    for _ in range(100):
        middle = (low + high) / 2
        if float(_sigmoid(linear + middle).mean()) < target:
            low = middle
        else:
            high = middle
    return (low + high) / 2


if __name__ == "__main__":  # pragma: no cover
    main()
