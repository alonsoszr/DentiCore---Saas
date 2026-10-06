"""Entrenamiento, calibración y artefactos del modelo (TASK-076; DD-12, DI-18, RN-59, RN-84,
RNF-165, RNF-166, RNF-167, RNF-170, RNF-171)."""

import json
from collections.abc import Iterator
from pathlib import Path

import boto3
import numpy as np
import pytest
import xgboost as xgb
from moto import mock_aws

from denticore_ml import dataset, training
from denticore_ml.features import FEATURES
from denticore_ml.model import RiskModel

VERSION = "1.0.0"


@pytest.fixture(scope="module")
def trained(tmp_path_factory: pytest.TempPathFactory) -> tuple[Path, training.TrainingResult]:
    out = tmp_path_factory.mktemp("models")
    frame = dataset.generate(rows=dataset.DEFAULT_ROWS, seed=dataset.DEFAULT_SEED)
    result = training.train(frame, version=VERSION, seed=dataset.DEFAULT_SEED, out_dir=out, code_commit="abc123")
    return out / VERSION, result


def test_reaches_the_minimum_validation_auc(trained: tuple[Path, training.TrainingResult]) -> None:
    _, result = trained

    assert result.metrics["auc_validation"] >= 0.75
    assert 0 < result.metrics["brier_score"] < 0.25
    assert 0 <= result.metrics["ece"] < 0.1


def test_writes_the_version_artifacts_with_the_dataset_hash(trained: tuple[Path, training.TrainingResult]) -> None:
    directory, result = trained
    metadata = json.loads((directory / "metadata.json").read_text(encoding="utf-8"))

    assert sorted(path.name for path in directory.iterdir()) == [
        "calibration.json",
        "metadata.json",
        "model.json",
        "model_card.md",
    ]
    expected_hash = dataset.dataset_hash(dataset.generate(rows=dataset.DEFAULT_ROWS, seed=dataset.DEFAULT_SEED))
    assert metadata["dataset_hash"] == expected_hash == result.dataset_hash
    assert metadata["version"] == VERSION
    assert metadata["dataset_version"] == dataset.DATASET_VERSION
    assert metadata["seed"] == dataset.DEFAULT_SEED
    assert metadata["code_commit"] == "abc123"
    assert metadata["features"] == list(FEATURES)
    assert metadata["thresholds"] == {"medium": 0.30, "high": 0.60}
    assert metadata["metrics"] == result.metrics
    assert set(metadata["hyperparameters"]) >= {"n_estimators", "max_depth", "learning_rate"}
    # Explicación global: media del |SHAP| de cada variable en la validación, de mayor a menor.
    importance = metadata["global_importance"]
    assert [item["feature"] for item in importance] == sorted(FEATURES, key=lambda f: -_mean_abs(importance, f))
    assert set(metadata["subgroup_metrics"]) == {"age_group", "education_level", "employment_status"}


def test_drafts_the_model_card(trained: tuple[Path, training.TrainingResult]) -> None:
    directory, result = trained
    card = (directory / "model_card.md").read_text(encoding="utf-8")

    assert VERSION in card and result.dataset_hash in card
    assert "no es un diagnóstico" in card and "OUT-06" in card
    assert f"{result.metrics['auc_validation']:.4f}" in card


def test_reloads_the_model_with_the_same_predictions(trained: tuple[Path, training.TrainingResult]) -> None:
    directory, _ = trained
    model = RiskModel.load(directory)
    frame = dataset.generate(rows=50, seed=99)
    matrix = training.feature_matrix(frame)

    probabilities = model.predict_proba(matrix)
    contributions, base_value = model.contributions(matrix)

    assert probabilities.shape == (50,)
    assert ((probabilities >= 0) & (probabilities <= 1)).all()
    assert contributions.shape == (50, len(FEATURES))
    # RNF-168: base + Σ SHAP = log-odds del modelo, que coinciden con el booster salvo float32.
    np.testing.assert_allclose(base_value + contributions.sum(axis=1), model.margin(matrix), atol=1e-6)
    booster_margin = model.booster.predict(xgb.DMatrix(matrix, feature_names=list(FEATURES)), output_margin=True)
    np.testing.assert_allclose(model.margin(matrix), booster_margin, atol=1e-5)


def test_retraining_with_the_same_inputs_reproduces_the_metrics(
    tmp_path: Path, trained: tuple[Path, training.TrainingResult]
) -> None:
    _, first = trained
    frame = dataset.generate(rows=dataset.DEFAULT_ROWS, seed=dataset.DEFAULT_SEED)

    again = training.train(frame, version=VERSION, seed=dataset.DEFAULT_SEED, out_dir=tmp_path, code_commit="abc123")

    assert again.metrics["auc_validation"] == pytest.approx(first.metrics["auc_validation"], abs=0.005)


@pytest.fixture
def bucket() -> Iterator[str]:
    with mock_aws():
        boto3.client("s3", region_name="us-east-1").create_bucket(Bucket="denticore")
        yield "denticore"


def test_publishes_the_artifacts_under_models_version(
    bucket: str, trained: tuple[Path, training.TrainingResult]
) -> None:
    directory, result = trained
    client = boto3.client("s3", region_name="us-east-1")

    training.publish(directory, client=client, bucket=bucket)

    keys = sorted(item["Key"] for item in client.list_objects_v2(Bucket=bucket)["Contents"])
    assert keys == [
        f"models/{VERSION}/{name}" for name in ("calibration.json", "metadata.json", "model.json", "model_card.md")
    ]
    metadata = json.loads(client.get_object(Bucket=bucket, Key=f"models/{VERSION}/metadata.json")["Body"].read())
    assert metadata["dataset_hash"] == result.dataset_hash


def test_trains_from_the_command_line(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    training.main(["--version", "0.1.0", "--rows", "2000", "--seed", "5", "--out", str(tmp_path), "--commit", "def456"])

    metadata = json.loads((tmp_path / "0.1.0" / "metadata.json").read_text(encoding="utf-8"))
    assert metadata["code_commit"] == "def456"
    assert "auc_validation" in capsys.readouterr().out


def _mean_abs(importance: list[dict[str, float | str]], feature: str) -> float:
    return float(next(item["mean_abs_shap"] for item in importance if item["feature"] == feature))
