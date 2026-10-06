"""Configuración del motor, leída del entorno."""

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Settings:
    """`service_key`: valor esperado de `X-ML-Service-Key` (vacío = rechaza todo).
    `models_dir`: carpeta local con una subcarpeta por versión del modelo (`models/<version>/`).
    `s3_bucket`/`s3_endpoint`: bucket de los artefactos (DI-18); vacío = solo la carpeta local."""

    service_key: str
    models_dir: Path
    s3_bucket: str = ""
    s3_endpoint: str = ""

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            service_key=os.environ.get("ML_SERVICE_KEY", ""),
            models_dir=Path(os.environ.get("ML_MODELS_DIR", "models")),
            s3_bucket=os.environ.get("ML_S3_BUCKET", ""),
            s3_endpoint=os.environ.get("ML_S3_ENDPOINT", ""),
        )
