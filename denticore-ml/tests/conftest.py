"""Fixtures comunes: la aplicación se crea con una configuración explícita, sin leer el entorno."""

import uuid
from collections.abc import Iterator
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient

from denticore_ml import dataset, training
from denticore_ml.config import Settings
from denticore_ml.main import create_app

SERVICE_KEY = "clave-de-prueba"
MODEL_VERSION = "1.0.0"


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


@pytest.fixture(scope="session")
def trained_models_dir(tmp_path_factory: pytest.TempPathFactory) -> Path:
    """Una versión entrenada con un conjunto pequeño, compartida por las pruebas del contrato."""
    directory = tmp_path_factory.mktemp("trained")
    frame = dataset.generate(rows=3000, seed=21)
    training.train(frame, version=MODEL_VERSION, seed=21, out_dir=directory, code_commit="pruebas")
    return directory


@pytest.fixture
def predict_client(trained_models_dir: Path) -> Iterator[TestClient]:
    app = create_app(Settings(service_key=SERVICE_KEY, models_dir=trained_models_dir))
    with TestClient(app) as test_client:
        yield test_client


def predict_request(**overrides: Any) -> dict[str, Any]:
    """Solicitud válida de SDD §4.6 (menor de 9 años, dentición mixta)."""
    features = {
        "age_years": 9,
        "cpod": 0,
        "ceod": 4,
        "plaque_index_pct": 42.5,
        "active_lesions": 2,
        "sugar_between_meals": "3",
        "fluoride_toothpaste": True,
        "brushing_per_day": "2",
        "dental_visits_per_year": "1",
        "education_level": "secundaria",
        "employment_status": "informal",
        "family_structure": "monoparental",
        **overrides.pop("features", {}),
    }
    return {
        "contract_version": "1.0",
        "request_ref": str(uuid.UUID("5f0e2c1b-7a44-4c9e-9f7e-3d6c0a1b2e90")),
        "model_version": MODEL_VERSION,
        "age_group": "menor",
        "features": features,
        **overrides,
    }
