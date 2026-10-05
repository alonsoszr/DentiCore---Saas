"""Versiones del modelo disponibles en `models/<version>/` (DI-18)."""

from pathlib import Path

METADATA_FILE = "metadata.json"


class ModelRegistry:
    """Una versión es una subcarpeta con `metadata.json`; el resto se ignora."""

    def __init__(self, models_dir: Path) -> None:
        self._models_dir = models_dir

    def versions(self) -> list[str]:
        if not self._models_dir.is_dir():
            return []
        return sorted(
            entry.name for entry in self._models_dir.iterdir() if entry.is_dir() and (entry / METADATA_FILE).is_file()
        )
