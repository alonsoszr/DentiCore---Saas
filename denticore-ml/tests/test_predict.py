"""`POST /v1/predict` (TASK-077; SDD §4.6; RF-167, RNF-174, RES-04, CA-55.6)."""

from typing import Any

import pytest
from fastapi.testclient import TestClient

from conftest import MODEL_VERSION, predict_request
from denticore_ml.features import FEATURES


def test_predicts_a_calibrated_probability_with_one_contribution_per_variable(
    predict_client: TestClient, auth: dict[str, str]
) -> None:
    request = predict_request()

    response = predict_client.post("/v1/predict", json=request, headers=auth)

    assert response.status_code == 200
    body = response.json()
    assert body["contract_version"] == "1.0"
    assert body["request_ref"] == request["request_ref"]
    assert body["model_version"] == MODEL_VERSION
    assert 0 <= body["probability"] <= 1
    # DD-12: confianza = probabilidad calibrada de la clase predicha.
    assert body["confidence"] == max(body["probability"], 1 - body["probability"])
    assert isinstance(body["base_value"], float)
    individual = body["explanation"]["individual"]
    assert [item["feature"] for item in individual] == list(FEATURES)
    assert [item["value"] for item in individual] == [request["features"][name] for name in FEATURES]
    assert sorted(item["feature"] for item in body["explanation"]["global"]) == sorted(FEATURES)
    assert all(item["mean_abs_shap"] >= 0 for item in body["explanation"]["global"])


def test_accepts_an_adult_without_family_structure_and_with_permanent_dentition(
    predict_client: TestClient, auth: dict[str, str]
) -> None:
    request = predict_request(
        age_group="adulto",
        features={"age_years": 34, "cpod": 7, "ceod": None, "family_structure": None, "employment_status": "formal"},
    )

    response = predict_client.post("/v1/predict", json=request, headers=auth)

    assert response.status_code == 200
    ceod = response.json()["explanation"]["individual"][2]
    assert ceod["feature"] == "ceod" and ceod["value"] is None


def test_returns_422_for_out_of_domain_variables(predict_client: TestClient, auth: dict[str, str]) -> None:
    response = predict_client.post(
        "/v1/predict", json=predict_request(features={"plaque_index_pct": 120.0}), headers=auth
    )

    assert response.status_code == 422
    assert response.headers["content-type"] == "application/problem+json"
    assert response.json() == {
        "type": "https://denticore.pe/problems/ml/out-of-domain",
        "title": "Variables fuera de dominio",
        "status": 422,
        "errors": {"plaque_index_pct": ["Debe estar entre 0 y 100."]},
    }


@pytest.mark.parametrize(
    ("request_body", "field", "message"),
    [
        (predict_request(features={"cpod": 33}), "cpod", "Debe estar entre 0 y 32."),
        (predict_request(features={"ceod": -1}), "ceod", "Debe estar entre 0 y 20."),
        (predict_request(features={"active_lesions": 40}), "active_lesions", "Debe estar entre 0 y 32."),
        (predict_request(features={"age_years": 121}), "age_years", "Debe estar entre 0 y 120."),
        (predict_request(features={"plaque_index_pct": 42.55}), "plaque_index_pct", "Admite como máximo 1 decimal."),
        (predict_request(features={"sugar_between_meals": "5"}), "sugar_between_meals", "Valor no admitido."),
        (predict_request(features={"fluoride_toothpaste": "si"}), "fluoride_toothpaste", "Tipo de dato no válido."),
        (predict_request(features={"age_years": 9.5}), "age_years", "Tipo de dato no válido."),
        # CA-55.6, RES-04: sin datos que identifiquen al paciente.
        (predict_request(features={"document_number": "45678912"}), "document_number", "Variable no admitida."),
        (predict_request(patient_name="Ana"), "patient_name", "Variable no admitida."),
        (predict_request(), "brushing_per_day", "Es obligatorio."),
        (predict_request(age_group="adulto"), "age_group", "No corresponde a la edad."),
        (predict_request(features={"family_structure": None}), "family_structure", "Es obligatorio para menores."),
        (
            predict_request(age_group="adulto", features={"age_years": 30, "family_structure": "biparental"}),
            "family_structure",
            "Solo se registra para menores.",
        ),
        (predict_request(features={"cpod": None, "ceod": None}), "cpod", "Indique CPOD o ceod según la dentición."),
        (predict_request(contract_version="2.0"), "contract_version", "Versión de contrato no admitida."),
        (predict_request(request_ref="no-es-uuid"), "request_ref", "Tipo de dato no válido."),
    ],
)
def test_rejects_each_domain_violation(
    predict_client: TestClient, auth: dict[str, str], request_body: dict[str, Any], field: str, message: str
) -> None:
    if field == "brushing_per_day":
        del request_body["features"]["brushing_per_day"]

    response = predict_client.post("/v1/predict", json=request_body, headers=auth)

    assert response.status_code == 422
    assert response.json()["errors"][field] == [message]


def test_returns_404_for_an_unavailable_model_version(predict_client: TestClient, auth: dict[str, str]) -> None:
    for version in ("9.9.9", "../1.0.0"):
        response = predict_client.post("/v1/predict", json=predict_request(model_version=version), headers=auth)

        assert response.status_code == 404
        assert response.json()["type"] == "https://denticore.pe/problems/ml/model-not-found"


def test_requires_the_service_key(predict_client: TestClient) -> None:
    assert predict_client.post("/v1/predict", json=predict_request()).status_code == 401
