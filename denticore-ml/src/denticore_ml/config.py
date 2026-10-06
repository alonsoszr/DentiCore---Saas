"""Configuración del motor, leída del entorno."""

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Settings:
    """`service_key`: valor esperado de `X-ML-Service-Key` (vacío = rechaza todo).
    `models_dir`: carpeta local con una subcarpeta por versión del modelo (`models/<version>/`)."""

    service_key: str
    models_dir: Path

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            service_key=os.environ.get("ML_SERVICE_KEY", ""),
            models_dir=Path(os.environ.get("ML_MODELS_DIR", "models")),
        )
