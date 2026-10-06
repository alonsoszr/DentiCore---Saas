"""T-141: la misma entrada y versión producen la misma salida (RNF-169)."""

from fastapi.testclient import TestClient

from conftest import predict_request


def test_returns_identical_output_for_1000_repetitions_of_the_same_input(
    predict_client: TestClient, auth: dict[str, str]
) -> None:
    request = predict_request()
    first = predict_client.post("/v1/predict", json=request, headers=auth).content

    for _ in range(999):
        assert predict_client.post("/v1/predict", json=request, headers=auth).content == first
