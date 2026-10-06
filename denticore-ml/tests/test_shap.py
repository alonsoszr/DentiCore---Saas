"""T-140: base_value + Σ SHAP reproduce las log-odds del modelo (RNF-168, error ≤ 1e-6)."""

from pathlib import Path

import numpy as np
from fastapi.testclient import TestClient

from conftest import MODEL_VERSION, predict_request
from denticore_ml.features import encode
from denticore_ml.model import RiskModel


def test_reproduces_the_model_log_odds_from_base_value_plus_contributions(
    predict_client: TestClient, auth: dict[str, str], trained_models_dir: Path
) -> None:
    model = RiskModel.load(trained_models_dir / MODEL_VERSION)

    for features in (
        {},
        {"active_lesions": 9, "sugar_between_meals": "4+", "fluoride_toothpaste": False},
        {"plaque_index_pct": 0.0, "ceod": 0, "brushing_per_day": "3+"},
    ):
        request = predict_request(features=features)
        body = predict_client.post("/v1/predict", json=request, headers=auth).json()

        log_odds = model.margin(np.array([encode(request["features"])]))[0]
        reconstructed = body["base_value"] + sum(item["shap"] for item in body["explanation"]["individual"])
        assert abs(reconstructed - log_odds) <= 1e-6
