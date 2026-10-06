"""Errores del motor como `application/problem+json` (SDD §4.6, «Errores del motor»)."""

from typing import Any

from fastapi import Request
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

PROBLEM_BASE = "https://denticore.pe/problems/ml/"

_TITLES = {404: "Recurso no encontrado", 405: "Método no permitido"}


class ProblemError(Exception):
    """Error con su tipo (`slug`), título, estado y, en 422, los errores por variable."""

    def __init__(self, status: int, slug: str, title: str, errors: dict[str, list[str]] | None = None) -> None:
        super().__init__(title)
        self.status = status
        self.slug = slug
        self.title = title
        self.errors = errors


def problem_response(status: int, slug: str, title: str, errors: dict[str, list[str]] | None = None) -> JSONResponse:
    body: dict[str, Any] = {"type": PROBLEM_BASE + slug, "title": title, "status": status}
    if errors is not None:
        body["errors"] = errors
    return JSONResponse(body, status_code=status, media_type="application/problem+json")


async def handle_problem(_: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, ProblemError)  # noqa: S101 - FastAPI solo lo llama con ProblemError
    return problem_response(exc.status, exc.slug, exc.title, exc.errors)


async def handle_http_error(_: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, StarletteHTTPException)  # noqa: S101 - registrado solo para HTTPException
    title = _TITLES.get(exc.status_code, "Error")
    return problem_response(exc.status_code, title.lower().replace(" ", "-"), title)
