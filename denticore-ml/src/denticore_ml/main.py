"""Aplicación FastAPI del motor ML. Se sirve con `uvicorn denticore_ml.main:create_app --factory`."""

from typing import Any

from fastapi import APIRouter, Depends, FastAPI, Request
from starlette.exceptions import HTTPException as StarletteHTTPException

from denticore_ml import CONTRACT_VERSION
from denticore_ml.config import Settings
from denticore_ml.problems import ProblemError, handle_http_error, handle_problem
from denticore_ml.registry import ModelRegistry
from denticore_ml.security import require_service_key


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings.from_env()
    # Sin documentación interactiva: el contrato publicado es SDD §4.6.
    app = FastAPI(title="DentiCore ML", docs_url=None, redoc_url=None, openapi_url=None)
    app.state.settings = settings
    app.state.registry = ModelRegistry(settings.models_dir)

    app.add_exception_handler(ProblemError, handle_problem)
    app.add_exception_handler(StarletteHTTPException, handle_http_error)

    router = APIRouter(prefix="/v1", dependencies=[Depends(require_service_key)])

    @router.get("/health")
    def health(request: Request) -> dict[str, Any]:
        """Estado y versiones del modelo disponibles."""
        registry: ModelRegistry = request.app.state.registry
        return {"status": "ok", "contract_version": CONTRACT_VERSION, "loaded_versions": registry.versions()}

    app.include_router(router)
    return app
