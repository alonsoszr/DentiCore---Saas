"""Autenticación de servicio a servicio con `X-ML-Service-Key` (SDD §4.6; RNF-099)."""

import hmac
from typing import Annotated

from fastapi import Header, Request

from denticore_ml.config import Settings
from denticore_ml.problems import ProblemError


def require_service_key(
    request: Request,
    x_ml_service_key: Annotated[str | None, Header()] = None,
) -> None:
    """Compara en tiempo constante; sin clave configurada se rechaza toda solicitud."""
    settings: Settings = request.app.state.settings
    expected = settings.service_key.encode()
    received = (x_ml_service_key or "").encode()

    if not expected or not hmac.compare_digest(received, expected):
        raise ProblemError(401, "unauthorized", "Clave de servicio inválida")
