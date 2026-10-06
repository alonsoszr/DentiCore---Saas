"""Autenticación del motor con `X-ML-Service-Key` (TASK-074; SDD §4.6; RNF-099)."""

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from denticore_ml.config import Settings
from denticore_ml.main import create_app


@pytest.mark.parametrize("headers", [{}, {"X-ML-Service-Key": "otra-clave"}, {"X-ML-Service-Key": ""}])
def test_rejects_requests_without_a_valid_service_key(client: TestClient, headers: dict[str, str]) -> None:
    response = client.get("/v1/health", headers=headers)

    assert response.status_code == 401
    assert response.headers["content-type"] == "application/problem+json"
    assert response.json() == {
        "type": "https://denticore.pe/problems/ml/unauthorized",
        "title": "Clave de servicio inválida",
        "status": 401,
    }


def test_rejects_every_request_when_no_key_is_configured(models_dir: Path) -> None:
    app = create_app(Settings(service_key="", models_dir=models_dir))

    with TestClient(app) as unconfigured:
        assert unconfigured.get("/v1/health", headers={"X-ML-Service-Key": ""}).status_code == 401


def test_reads_the_key_and_the_models_directory_from_the_environment(
    monkeypatch: pytest.MonkeyPatch, models_dir: Path
) -> None:
    monkeypatch.setenv("ML_SERVICE_KEY", "clave-del-entorno")
    monkeypatch.setenv("ML_MODELS_DIR", str(models_dir))

    settings = Settings.from_env()

    assert settings == Settings(service_key="clave-del-entorno", models_dir=models_dir)
