"""Fixtures comunes: la aplicación se crea con una configuración explícita, sin leer el entorno."""

from collections.abc import Iterator
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from denticore_ml.config import Settings
from denticore_ml.main import create_app

SERVICE_KEY = "clave-de-prueba"


@pytest.fixture
def models_dir(tmp_path: Path) -> Path:
    directory = tmp_path / "models"
    directory.mkdir()
    return directory


@pytest.fixture
def client(models_dir: Path) -> Iterator[TestClient]:
    app = create_app(Settings(service_key=SERVICE_KEY, models_dir=models_dir))
    with TestClient(app) as test_client:
        yield test_client


@pytest.fixture
def auth() -> dict[str, str]:
    return {"X-ML-Service-Key": SERVICE_KEY}
