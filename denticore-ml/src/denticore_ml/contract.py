"""Contrato 1.0 del motor (SDD §4.6; RF-167, RNF-174, RES-04, CA-55.6).

La solicitud solo admite las 12 variables de §2.14.2 con su dominio: ningún campo adicional
(nombre, documento, UUID del paciente, fecha de nacimiento) pasa la validación. El esquema JSON
publicado está en `contract/contract-1.0.schema.json` y se regenera con
`python -m denticore_ml.contract`.
"""

import json
from pathlib import Path
from typing import Annotated, Any, Literal
from uuid import UUID

from pydantic import AfterValidator, BaseModel, ConfigDict, Field, ValidationError

CONTRACT_MAJOR = "1"
SCHEMA_FILE = Path(__file__).resolve().parents[2] / "contract" / "contract-1.0.schema.json"


def _between(low: float, high: float) -> AfterValidator:
    def check(value: float) -> float:
        if not low <= value <= high:
            raise ValueError(f"Debe estar entre {low:g} y {high:g}.")
        return value

    return AfterValidator(check)


def _bounds(low: float, high: float, **extra: float) -> Any:
    """Rango en el esquema JSON publicado; la validación la hace `_between` con el mensaje en español."""
    return Field(json_schema_extra={"minimum": low, "maximum": high, **extra})


def _one_decimal(value: float) -> float:
    if abs(round(value * 10) - value * 10) > 1e-9:
        raise ValueError("Admite como máximo 1 decimal.")
    return value


class Features(BaseModel):
    """Las 12 variables, todas presentes; `cpod`, `ceod` y `family_structure` admiten `null`."""

    model_config = ConfigDict(extra="forbid", strict=True)

    age_years: Annotated[int, _between(0, 120), _bounds(0, 120)]
    cpod: Annotated[int, _between(0, 32), _bounds(0, 32)] | None
    ceod: Annotated[int, _between(0, 20), _bounds(0, 20)] | None
    plaque_index_pct: Annotated[float, _between(0, 100), AfterValidator(_one_decimal), _bounds(0, 100, multipleOf=0.1)]
    active_lesions: Annotated[int, _between(0, 32), _bounds(0, 32)]
    sugar_between_meals: Literal["0", "1", "2", "3", "4+"]
    fluoride_toothpaste: bool
    brushing_per_day: Literal["0", "1", "2", "3+"]
    dental_visits_per_year: Literal["<1", "1", "2+"]
    education_level: Literal["sin_estudios", "primaria", "secundaria", "tecnica", "universitaria"]
    employment_status: Literal["formal", "informal", "desempleado", "otra"]
    family_structure: Literal["biparental", "monoparental", "extendida", "otra"] | None


class PredictRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    contract_version: Annotated[str, Field(max_length=10)]
    request_ref: Annotated[UUID, Field(strict=False)]
    model_version: Annotated[str, Field(min_length=1, max_length=30)]
    age_group: Literal["menor", "adulto"]
    features: Features


class GlobalContribution(BaseModel):
    feature: str
    mean_abs_shap: float


class IndividualContribution(BaseModel):
    feature: str
    value: int | float | str | bool | None
    shap: float


class Explanation(BaseModel):
    global_: list[GlobalContribution] = Field(alias="global")
    individual: list[IndividualContribution]

    model_config = ConfigDict(populate_by_name=True, serialize_by_alias=True)


class PredictResponse(BaseModel):
    contract_version: str
    request_ref: UUID
    model_version: str
    probability: Annotated[float, Field(ge=0, le=1)]
    confidence: Annotated[float, Field(ge=0, le=1)]
    base_value: float
    explanation: Explanation


def domain_errors(request: PredictRequest) -> dict[str, list[str]]:
    """Reglas entre campos: versión de contrato, grupo etario y dentición (DI-20, RN-12)."""
    errors: dict[str, list[str]] = {}
    features = request.features

    if request.contract_version.split(".")[0] != CONTRACT_MAJOR:
        errors["contract_version"] = ["Versión de contrato no admitida."]
    if (features.age_years < 18) != (request.age_group == "menor"):
        errors["age_group"] = ["No corresponde a la edad."]
    if request.age_group == "menor" and features.family_structure is None:
        errors["family_structure"] = ["Es obligatorio para menores."]
    if request.age_group == "adulto" and features.family_structure is not None:
        errors["family_structure"] = ["Solo se registra para menores."]
    if features.cpod is None and features.ceod is None:
        errors["cpod"] = ["Indique CPOD o ceod según la dentición."]
    return errors


_MESSAGES = {
    "missing": "Es obligatorio.",
    "extra_forbidden": "Variable no admitida.",
    "literal_error": "Valor no admitido.",
}


def validation_errors(error: ValidationError | list[Any]) -> dict[str, list[str]]:
    """Errores de pydantic con el nombre de la variable como clave y el mensaje en español."""
    items = error.errors() if isinstance(error, ValidationError) else error
    result: dict[str, list[str]] = {}
    for item in items:
        location = [str(part) for part in item["loc"] if part not in ("body", "features")]
        # Una unión con None informa la rama: `cpod.int` → `cpod`.
        field = location[0] if location else "body"
        result.setdefault(field, [])
        message = _message(item)
        if message not in result[field]:
            result[field].append(message)
    return result


def _message(item: Any) -> str:
    kind = str(item["type"])
    if kind == "value_error":
        return str(item["ctx"]["error"])
    return _MESSAGES.get(kind, "Tipo de dato no válido.")


def contract_schema() -> dict[str, Any]:
    return {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "Contrato del motor ML de DentiCore",
        "version": "1.0",
        "request": PredictRequest.model_json_schema(),
        "response": PredictResponse.model_json_schema(mode="serialization"),
    }


def main() -> None:  # pragma: no cover - herramienta de mantenimiento
    SCHEMA_FILE.parent.mkdir(parents=True, exist_ok=True)
    SCHEMA_FILE.write_text(json.dumps(contract_schema(), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


if __name__ == "__main__":  # pragma: no cover
    main()
