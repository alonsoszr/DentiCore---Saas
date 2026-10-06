"""Aplicación FastAPI del motor ML. Se sirve con `uvicorn denticore_ml.main:create_app --factory`.

El motor no accede a la BD de Laravel ni persiste las solicitudes o sus variables (RES-04).
"""

from typing import Any

import numpy as np
from fastapi import APIRouter, Depends, FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from denticore_ml import CONTRACT_VERSION
from denticore_ml.config import Settings
from denticore_ml.contract import (
    Explanation,
    GlobalContribution,
    IndividualContribution,
    PredictRequest,
    PredictResponse,
    domain_errors,
    validation_errors,
)
from denticore_ml.features import FEATURES, encode
from denticore_ml.problems import ProblemError, handle_http_error, handle_problem, problem_response
from denticore_ml.registry import ModelRegistry
from denticore_ml.security import require_service_key
from denticore_ml.storage import s3_client_from_env

OUT_OF_DOMAIN = ("out-of-domain", "Variables fuera de dominio")


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings.from_env()
    # Sin documentación interactiva: el contrato publicado es SDD §4.6.
    app = FastAPI(title="DentiCore ML", docs_url=None, redoc_url=None, openapi_url=None)
    app.state.settings = settings
    client = s3_client_from_env(settings.s3_endpoint) if settings.s3_bucket else None
    app.state.registry = ModelRegistry(settings.models_dir, client=client, bucket=settings.s3_bucket)

    app.add_exception_handler(ProblemError, handle_problem)
    app.add_exception_handler(StarletteHTTPException, handle_http_error)
    app.add_exception_handler(RequestValidationError, _handle_validation)

    router = APIRouter(prefix="/v1", dependencies=[Depends(require_service_key)])

    @router.get("/health")
    def health(request: Request) -> dict[str, Any]:
        """Estado y versiones del modelo disponibles."""
        registry: ModelRegistry = request.app.state.registry
        return {"status": "ok", "contract_version": CONTRACT_VERSION, "loaded_versions": registry.versions()}

    @router.get("/models")
    def models(request: Request) -> dict[str, Any]:
        """Versiones disponibles con sus métricas (CUS-61)."""
        registry: ModelRegistry = request.app.state.registry
        return {"models": registry.catalog()}

    @router.post("/predict")
    def predict(payload: PredictRequest, request: Request) -> dict[str, Any]:
        """Probabilidad calibrada, confianza y explicación SHAP (RF-167, RNF-168, RNF-169)."""
        errors = domain_errors(payload)
        if errors:
            raise ProblemError(422, *OUT_OF_DOMAIN, errors)

        registry: ModelRegistry = request.app.state.registry
        model = registry.get(payload.model_version)
        if model is None:
            raise ProblemError(404, "model-not-found", "Versión del modelo no disponible")

        values = payload.features.model_dump()
        vector = np.array([encode(values)])
        probability = float(model.predict_proba(vector)[0])
        contributions, base_value = model.contributions(vector)

        response = PredictResponse(
            contract_version=CONTRACT_VERSION,
            request_ref=payload.request_ref,
            model_version=payload.model_version,
            probability=probability,
            confidence=max(probability, 1.0 - probability),
            base_value=base_value,
            explanation=Explanation(
                global_=[GlobalContribution(**item) for item in model.metadata.get("global_importance", [])],
                individual=[
                    IndividualContribution(feature=name, value=values[name], shap=float(shap))
                    for name, shap in zip(FEATURES, contributions[0], strict=True)
                ],
            ),
        )
        return response.model_dump(mode="json", by_alias=True)

    app.include_router(router)
    return app


async def _handle_validation(_: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, RequestValidationError)  # noqa: S101 - registrado solo para este error
    return problem_response(422, *OUT_OF_DOMAIN, validation_errors(list(exc.errors())))
