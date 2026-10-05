"""`GET /v1/health` con las versiones cargadas (TASK-074; SDD §4.6)."""

import json
from pathlib import Path

from fastapi.testclient import TestClient

from denticore_ml.config import Settings
from denticore_ml.main import create_app


def test_reports_the_status_and_the_contract_without_models(client: TestClient, auth: dict[str, str]) -> None:
    response = client.get("/v1/health", headers=auth)

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "contract_version": "1.0", "loaded_versions": []}


def test_lists_the_versions_found_in_the_models_directory(models_dir: Path, auth: dict[str, str]) -> None:
    for version in ("1.0.0", "1.1.0"):
        (models_dir / version).mkdir()
        (models_dir / version / "metadata.json").write_text(json.dumps({"version": version}), encoding="utf-8")
    # Un directorio sin metadatos no es una versión.
    (models_dir / "incompleta").mkdir()

    with TestClient(create_app(Settings(service_key="clave", models_dir=models_dir))) as client:
        response = client.get("/v1/health", headers={"X-ML-Service-Key": "clave"})

    assert response.json()["loaded_versions"] == ["1.0.0", "1.1.0"]


def test_answers_unknown_routes_with_a_problem(client: TestClient, auth: dict[str, str]) -> None:
    response = client.get("/v1/desconocida", headers=auth)

    assert response.status_code == 404
    assert response.headers["content-type"] == "application/problem+json"
    assert response.json()["status"] == 404
