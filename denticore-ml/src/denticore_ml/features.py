"""Variables del modelo y sus dominios (SDD §2.14.2, §4.6; DD-12).

Las 12 variables de `features` en el orden en que entran al modelo. Las categóricas se
codifican por su posición en el dominio y la ausencia (`None`) como NaN, que XGBoost trata de
forma nativa (CPOD/ceod según la dentición, DI-20; estructura familiar solo en menores).
"""

import math
from collections.abc import Mapping

FEATURES: tuple[str, ...] = (
    "age_years",
    "cpod",
    "ceod",
    "plaque_index_pct",
    "active_lesions",
    "sugar_between_meals",
    "fluoride_toothpaste",
    "brushing_per_day",
    "dental_visits_per_year",
    "education_level",
    "employment_status",
    "family_structure",
)

AGE_GROUPS: tuple[str, ...] = ("menor", "adulto")

CATEGORIES: dict[str, tuple[str, ...]] = {
    "sugar_between_meals": ("0", "1", "2", "3", "4+"),
    "brushing_per_day": ("0", "1", "2", "3+"),
    "dental_visits_per_year": ("<1", "1", "2+"),
    "education_level": ("sin_estudios", "primaria", "secundaria", "tecnica", "universitaria"),
    "employment_status": ("formal", "informal", "desempleado", "otra"),
    "family_structure": ("biparental", "monoparental", "extendida", "otra"),
}

# Rangos enteros inclusivos. La edad sigue el límite de la ficha del paciente (≤ 120 años).
INTEGER_RANGES: dict[str, tuple[int, int]] = {
    "age_years": (0, 120),
    "cpod": (0, 32),
    "ceod": (0, 20),
    "active_lesions": (0, 32),
}

PLAQUE_RANGE: tuple[float, float] = (0.0, 100.0)

FeatureValue = int | float | str | bool | None


def encode(features: Mapping[str, FeatureValue]) -> list[float]:
    """Vector numérico del modelo; supone valores ya validados contra su dominio."""
    return [_encode_one(name, features.get(name)) for name in FEATURES]


def _encode_one(name: str, value: FeatureValue) -> float:
    if value is None:
        return math.nan
    if name in CATEGORIES:
        return float(CATEGORIES[name].index(str(value)))
    return float(value)
