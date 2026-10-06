"""Versiones del modelo en `models/<version>/` (DI-18).

Con bucket configurado, una versión que no está en la carpeta local se descarga del bucket la
primera vez que se pide: publicar un modelo no exige desplegar el motor ni Laravel (RNF-145).
"""

import json
import re
import threading
from pathlib import Path
from typing import Any

from denticore_ml.model import METADATA_FILE, RiskModel
from denticore_ml.storage import S3Client

# SemVer (model_versions.version); impide salir de la carpeta de modelos.
VERSION_PATTERN = re.compile(r"^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$")
PREFIX = "models"


class ModelRegistry:
    def __init__(self, models_dir: Path, *, client: S3Client | None = None, bucket: str = "") -> None:
        self._models_dir = models_dir
        self._client = client if bucket else None
        self._bucket = bucket
        self._loaded: dict[str, RiskModel] = {}
        self._lock = threading.Lock()

    def versions(self) -> list[str]:
        """Versiones presentes en la carpeta local (cargadas o listas para cargar)."""
        if not self._models_dir.is_dir():
            return []
        return sorted(
            entry.name for entry in self._models_dir.iterdir() if entry.is_dir() and (entry / METADATA_FILE).is_file()
        )

    def get(self, version: str) -> RiskModel | None:
        if not VERSION_PATTERN.fullmatch(version):
            return None
        with self._lock:
            if version not in self._loaded:
                directory = self._models_dir / version
                if not (directory / METADATA_FILE).is_file() and not self._download(version):
                    return None
                self._loaded[version] = RiskModel.load(directory)
            return self._loaded[version]

    def catalog(self) -> list[dict[str, Any]]:
        """Metadatos de cada versión disponible (CUS-61), sin la importancia global."""
        if self._client is not None:
            for version in self._remote_versions():
                if not (self._models_dir / version / METADATA_FILE).is_file():
                    self._download(version)
        return [self._metadata(version) for version in self.versions()]

    def _metadata(self, version: str) -> dict[str, Any]:
        metadata: dict[str, Any] = json.loads((self._models_dir / version / METADATA_FILE).read_text(encoding="utf-8"))
        metadata.pop("global_importance", None)
        return metadata

    def _remote_versions(self) -> list[str]:
        assert self._client is not None  # noqa: S101 - solo se llama con bucket configurado
        response = self._client.list_objects_v2(Bucket=self._bucket, Prefix=f"{PREFIX}/", Delimiter="/")
        names = (
            item.get("Prefix", "").removeprefix(f"{PREFIX}/").rstrip("/") for item in response.get("CommonPrefixes", [])
        )
        return sorted(name for name in names if VERSION_PATTERN.fullmatch(name))

    def _download(self, version: str) -> bool:
        if self._client is None:
            return False
        objects = self._client.list_objects_v2(Bucket=self._bucket, Prefix=f"{PREFIX}/{version}/").get("Contents", [])
        keys = [item["Key"] for item in objects if "Key" in item]
        if f"{PREFIX}/{version}/{METADATA_FILE}" not in keys:
            return False
        directory = self._models_dir / version
        directory.mkdir(parents=True, exist_ok=True)
        # metadata.json al final: su presencia marca la versión como completa.
        for key in sorted(keys, key=lambda name: name.endswith(METADATA_FILE)):
            name = key.rsplit("/", 1)[-1]
            body = self._client.get_object(Bucket=self._bucket, Key=key)["Body"].read()
            (directory / name).write_bytes(body)
        return True
