"""Modelo de una versión: XGBoost + calibración isotónica (DD-12) y contribuciones TreeSHAP.

Artefactos de `models/<version>/` (DI-18), todos en JSON (sin pickle):
- `model.json`: el booster de XGBoost.
- `calibration.json`: puntos de la regresión isotónica (probabilidad cruda → calibrada).
- `metadata.json`: versión, conjunto, semilla, hiperparámetros, métricas y umbrales.
"""

import json
from pathlib import Path
from typing import Any

import numpy as np
import xgboost as xgb

from denticore_ml.features import FEATURES

MODEL_FILE = "model.json"
CALIBRATION_FILE = "calibration.json"
METADATA_FILE = "metadata.json"


class RiskModel:
    def __init__(
        self,
        booster: xgb.Booster,
        calibration_x: np.ndarray,
        calibration_y: np.ndarray,
        metadata: dict[str, Any] | None = None,
    ) -> None:
        self.booster = booster
        self.calibration_x = np.asarray(calibration_x, dtype=float)
        self.calibration_y = np.asarray(calibration_y, dtype=float)
        self.metadata: dict[str, Any] = metadata or {}

    @classmethod
    def load(cls, directory: Path) -> "RiskModel":
        booster = xgb.Booster()
        booster.load_model(str(directory / MODEL_FILE))
        calibration = json.loads((directory / CALIBRATION_FILE).read_text(encoding="utf-8"))
        metadata = json.loads((directory / METADATA_FILE).read_text(encoding="utf-8"))
        return cls(booster, np.array(calibration["x"]), np.array(calibration["y"]), metadata)

    def save(self, directory: Path) -> None:
        directory.mkdir(parents=True, exist_ok=True)
        self.booster.save_model(str(directory / MODEL_FILE))
        calibration = {"x": self.calibration_x.tolist(), "y": self.calibration_y.tolist()}
        (directory / CALIBRATION_FILE).write_text(json.dumps(calibration), encoding="utf-8")
        (directory / METADATA_FILE).write_text(
            json.dumps(self.metadata, indent=2, ensure_ascii=False), encoding="utf-8"
        )

    def margin(self, matrix: np.ndarray) -> np.ndarray:
        """Log-odds del modelo (antes de calibrar) = valor base + Σ contribuciones, en float64.

        XGBoost calcula en float32: su `output_margin` difiere de esa suma en ~1e-6. Definir las
        log-odds desde TreeSHAP hace que RNF-168 (error ≤ 1e-6) se cumpla por construcción y que
        la probabilidad salga del mismo valor que se explica."""
        contributions, base_value = self.contributions(matrix)
        result: np.ndarray = base_value + contributions.sum(axis=1)
        return result

    def predict_proba(self, matrix: np.ndarray) -> np.ndarray:
        """Probabilidad calibrada: isotónica sobre la probabilidad cruda del booster."""
        raw = 1.0 / (1.0 + np.exp(-self.margin(matrix)))
        calibrated: np.ndarray = np.interp(raw, self.calibration_x, self.calibration_y)
        clipped: np.ndarray = np.clip(calibrated, 0.0, 1.0)
        return clipped

    def contributions(self, matrix: np.ndarray) -> tuple[np.ndarray, float]:
        """TreeSHAP exacto de XGBoost: una contribución por variable y el valor base, de modo que
        `base + Σ contribuciones = margin` (RNF-168)."""
        values: np.ndarray = self.booster.predict(self._dmatrix(matrix), pred_contribs=True).astype(float)
        return values[:, :-1], float(values[0, -1])

    @staticmethod
    def _dmatrix(matrix: np.ndarray) -> xgb.DMatrix:
        return xgb.DMatrix(np.asarray(matrix, dtype=float), missing=np.nan, feature_names=list(FEATURES))
