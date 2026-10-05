"""`GET /v1/models` y carga de artefactos desde S3 (TASK-077; SDD §4.6, DI-18; CUS-61)."""

import json
from collections.abc import Iterator
from pathlib import Path

import boto3
import pytest
from fastapi.testclient import TestClient
from moto import mock_aws

from conftest import MODEL_VERSION, SERVICE_KEY, predict_request
from denticore_ml import training
from denticore_ml.config import Settings
from denticore_ml.contract import contract_schema
from denticore_ml.main import create_app

SCHEMA_FILE = Path(__file__).resolve().parents[1] / "contract" / "contract-1.0.schema.json"


def test_lists_the_available_versions_with_their_metrics(predict_client: TestClient, auth: dict[str, str]) -> None:
    response = predict_client.get("/v1/models", headers=auth)

    assert response.status_code == 200
    [model] = response.json()["models"]
    assert model["version"] == MODEL_VERSION
    assert {"dataset_hash", "seed", "code_commit", "hyperparameters", "metrics", "thresholds"} <= set(model)
    assert model["metrics"]["auc_validation"] > 0.5


@pytest.fixture
def remote_bucket(trained_models_dir: Path) -> Iterator[str]:
    with mock_aws():
        client = boto3.client("s3", region_name="us-east-1")
        client.create_bucket(Bucket="denticore")
        training.publish(trained_models_dir / MODEL_VERSION, client=client, bucket="denticore")
        yield "denticore"


def test_downloads_a_published_version_from_the_bucket_on_demand(remote_bucket: str, tmp_path: Path) -> None:
    settings = Settings(service_key=SERVICE_KEY, models_dir=tmp_path / "cache", s3_bucket=remote_bucket)
    headers = {"X-ML-Service-Key": SERVICE_KEY}

    with TestClient(create_app(settings)) as client:
        assert client.get("/v1/health", headers=headers).json()["loaded_versions"] == []
        assert [m["version"] for m in client.get("/v1/models", headers=headers).json()["models"]] == [MODEL_VERSION]
        assert client.post("/v1/predict", json=predict_request(), headers=headers).status_code == 200
        assert (
            client.post("/v1/predict", json=predict_request(model_version="2.0.0"), headers=headers).status_code == 404
        )

    assert (tmp_path / "cache" / MODEL_VERSION / "model.json").is_file()


def test_publishes_the_json_schema_of_contract_1_0() -> None:
    committed = json.loads(SCHEMA_FILE.read_text(encoding="utf-8"))

    assert committed == contract_schema(), (
        "contract-1.0.schema.json está desactualizado: python -m denticore_ml.contract"
    )
