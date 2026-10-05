"""Entrenamiento de una versión del modelo de riesgo (TASK-076; DD-12, DI-18, RN-84, RNF-170).

XGBoost sobre el 60 % del conjunto, calibración isotónica sobre otro 20 % y métricas sobre el
20 % restante (AUC, Brier, ECE y por subgrupo). Los artefactos quedan en `<out>/<version>/` y,
con `--upload`, en el bucket bajo `models/<version>/`.

Uso: `python -m denticore_ml.training --version 1.0.0 --out models [--upload]`
"""

import argparse
import json
import os
from collections.abc import Hashable, Mapping, Sequence
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd
import xgboost as xgb
from sklearn.isotonic import IsotonicRegression
from sklearn.metrics import brier_score_loss, roc_auc_score
from sklearn.model_selection import train_test_split

from denticore_ml import dataset
from denticore_ml.features import FEATURES, FeatureValue, encode
from denticore_ml.model import RiskModel
from denticore_ml.storage import S3Client, s3_client_from_env

DEFAULT_HYPERPARAMETERS: dict[str, float | int] = {
    "n_estimators": 300,
    "max_depth": 3,
    "learning_rate": 0.05,
    "subsample": 0.9,
    "colsample_bytree": 0.9,
    "min_child_weight": 1.0,
    "reg_lambda": 1.0,
}

# DD-12, RN-59: bajo < 0,30 ≤ medio < 0,60 ≤ alto; versionados con el modelo.
THRESHOLDS = {"medium": 0.30, "high": 0.60}

SUBGROUPS = ("age_group", "education_level", "employment_status")

CARD_FILE = "model_card.md"


@dataclass(frozen=True)
class TrainingResult:
    version: str
    dataset_hash: str
    metrics: dict[str, float]


def feature_matrix(frame: pd.DataFrame) -> np.ndarray:
    """Las 12 variables codificadas, en el orden de `FEATURES` (nulos → NaN)."""
    rows = frame[list(FEATURES)].astype(object).where(frame[list(FEATURES)].notna(), None).to_dict("records")
    return np.array([encode(_as_features(row)) for row in rows], dtype=float)


def train(
    frame: pd.DataFrame,
    *,
    version: str,
    seed: int,
    out_dir: Path,
    code_commit: str,
    hyperparameters: dict[str, float | int] | None = None,
) -> TrainingResult:
    params = {**DEFAULT_HYPERPARAMETERS, **(hyperparameters or {})}
    matrix = feature_matrix(frame)
    target = frame[dataset.TARGET].to_numpy()

    indices = np.arange(len(target))
    train_idx, rest = train_test_split(indices, test_size=0.4, random_state=seed, stratify=target)
    calibration_idx, validation_idx = train_test_split(rest, test_size=0.5, random_state=seed, stratify=target[rest])

    booster = xgb.train(
        {
            "objective": "binary:logistic",
            "eval_metric": "logloss",
            "tree_method": "hist",
            "max_depth": params["max_depth"],
            "eta": params["learning_rate"],
            "subsample": params["subsample"],
            "colsample_bytree": params["colsample_bytree"],
            "min_child_weight": params["min_child_weight"],
            "lambda": params["reg_lambda"],
            "seed": seed,
            "nthread": 1,
        },
        xgb.DMatrix(matrix[train_idx], label=target[train_idx], missing=np.nan, feature_names=list(FEATURES)),
        num_boost_round=int(params["n_estimators"]),
    )

    uncalibrated = RiskModel(booster, np.array([0.0, 1.0]), np.array([0.0, 1.0]))
    isotonic = IsotonicRegression(out_of_bounds="clip", y_min=0.0, y_max=1.0)
    isotonic.fit(uncalibrated.predict_proba(matrix[calibration_idx]), target[calibration_idx])
    model = RiskModel(booster, isotonic.X_thresholds_, isotonic.y_thresholds_)

    probabilities = model.predict_proba(matrix[validation_idx])
    validation_target = target[validation_idx]
    metrics = {
        "auc_validation": round(float(roc_auc_score(validation_target, probabilities)), 4),
        "brier_score": round(float(brier_score_loss(validation_target, probabilities)), 4),
        "ece": round(expected_calibration_error(validation_target, probabilities), 4),
    }

    contributions, _ = model.contributions(matrix[validation_idx])
    mean_abs = sorted(
        zip(FEATURES, (round(float(value), 6) for value in np.abs(contributions).mean(axis=0)), strict=True),
        key=lambda pair: -pair[1],
    )
    importance = [{"feature": name, "mean_abs_shap": value} for name, value in mean_abs]

    dataset_hash = dataset.dataset_hash(frame)
    model.metadata = {
        "version": version,
        "contract_version": "1.0",
        "trained_at": datetime.now(UTC).isoformat(timespec="seconds"),
        "dataset_version": dataset.DATASET_VERSION,
        "dataset_hash": dataset_hash,
        "dataset_rows": len(frame),
        "seed": seed,
        "code_commit": code_commit,
        "hyperparameters": params,
        "features": list(FEATURES),
        "thresholds": THRESHOLDS,
        "metrics": metrics,
        "subgroup_metrics": _subgroup_metrics(frame.iloc[validation_idx], validation_target, probabilities),
        "global_importance": importance,
    }

    directory = out_dir / version
    model.save(directory)
    (directory / CARD_FILE).write_text(model_card(model.metadata), encoding="utf-8")

    return TrainingResult(version=version, dataset_hash=dataset_hash, metrics=metrics)


def expected_calibration_error(target: np.ndarray, probabilities: np.ndarray, bins: int = 10) -> float:
    """ECE con 10 intervalos de igual ancho (RNF-166)."""
    edges = np.linspace(0.0, 1.0, bins + 1)
    which = np.clip(np.digitize(probabilities, edges[1:-1]), 0, bins - 1)
    total = 0.0
    for current in range(bins):
        mask = which == current
        if mask.any():
            total += abs(float(probabilities[mask].mean()) - float(target[mask].mean())) * mask.sum() / len(target)
    return total


def publish(directory: Path, *, client: S3Client, bucket: str, prefix: str = "models") -> list[str]:
    """Sube los artefactos de la versión a `<prefix>/<version>/` (DI-18)."""
    keys = []
    for path in sorted(directory.iterdir()):
        key = f"{prefix}/{directory.name}/{path.name}"
        client.put_object(Bucket=bucket, Key=key, Body=path.read_bytes())
        keys.append(key)
    return keys


def model_card(metadata: dict[str, Any]) -> str:
    """Borrador de la ficha técnica (RNF-171)."""
    metrics = metadata["metrics"]
    lines = [
        f"# Ficha técnica del modelo de riesgo de caries {metadata['version']} (borrador)",
        "",
        "## Uso previsto",
        "",
        "Estimar la probabilidad de al menos una lesión de caries nueva en 12 meses para priorizar la prevención"
        " (DD-12). Apoya la decisión del odontólogo: **no es un diagnóstico**.",
        "",
        "## Datos",
        "",
        f"- Conjunto sintético versión {metadata['dataset_version']}, {metadata['dataset_rows']} filas,"
        f" `dataset_hash` {metadata['dataset_hash']}.",
        "- Distribuciones y supuestos: `docs/dataset_card.md`.",
        "- Un AUC sobre datos sintéticos no demuestra validez clínica (OUT-06).",
        "",
        "## Variables",
        "",
        ", ".join(f"`{name}`" for name in metadata["features"]),
        "",
        "## Métricas de validación",
        "",
        f"- AUC-ROC: {metrics['auc_validation']:.4f} (mínimo para activar: 0,75; RN-84)",
        f"- Brier: {metrics['brier_score']:.4f}",
        f"- ECE (10 intervalos): {metrics['ece']:.4f}",
        f"- Umbrales: medio ≥ {metadata['thresholds']['medium']}, alto ≥ {metadata['thresholds']['high']}",
        "",
        "## Métricas por subgrupo (RNF-167)",
        "",
        "| Subgrupo | Valor | n | AUC | Tasa de nivel alto |",
        "| :-- | :-- | --: | --: | --: |",
    ]
    for group, values in metadata["subgroup_metrics"].items():
        for value, stats in values.items():
            auc = "—" if stats["auc"] is None else f"{stats['auc']:.4f}"
            lines.append(f"| {group} | {value} | {stats['n']} | {auc} | {stats['high_rate']:.3f} |")
    lines += [
        "",
        "## Entrenamiento (RNF-170)",
        "",
        f"- Semilla: {metadata['seed']}; commit: {metadata['code_commit']}; entrenado: {metadata['trained_at']}.",
        f"- Hiperparámetros: `{json.dumps(metadata['hyperparameters'], sort_keys=True)}`.",
        "- XGBoost (60 %), calibración isotónica (20 %) y validación (20 %), con partición estratificada.",
        "",
        "## Limitaciones",
        "",
        "- Entrenado solo con datos sintéticos (PQ-06); requiere validación clínica prospectiva (OUT-06).",
        "- Los supuestos del conjunto condicionan las métricas y la importancia de las variables.",
        "",
    ]
    return "\n".join(lines)


def main(argv: Sequence[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description="Entrena una versión del modelo y escribe sus artefactos.")
    parser.add_argument("--version", required=True)
    parser.add_argument("--rows", type=int, default=dataset.DEFAULT_ROWS)
    parser.add_argument("--seed", type=int, default=dataset.DEFAULT_SEED)
    parser.add_argument("--out", type=Path, default=Path("models"))
    parser.add_argument("--commit", default=os.environ.get("GIT_COMMIT", "desconocido"))
    parser.add_argument("--upload", action="store_true", help="Sube los artefactos al bucket ML_S3_BUCKET.")
    args = parser.parse_args(argv)

    frame = dataset.generate(rows=args.rows, seed=args.seed)
    result = train(frame, version=args.version, seed=args.seed, out_dir=args.out, code_commit=args.commit)
    if args.upload:  # pragma: no cover - requiere un bucket real
        publish(args.out / args.version, client=s3_client_from_env(), bucket=os.environ["ML_S3_BUCKET"])
    print(json.dumps({"version": result.version, "dataset_hash": result.dataset_hash, **result.metrics}))


def _as_features(row: Mapping[Hashable, object]) -> dict[str, FeatureValue]:
    return {str(name): _native(value) for name, value in row.items()}


def _native(value: object) -> FeatureValue:
    """Valores de pandas/numpy como tipos de Python."""
    if isinstance(value, np.generic):
        value = value.item()
    if value is None or isinstance(value, str | bool | int | float):
        return value
    raise TypeError(f"Valor no admitido: {value!r}")


def _subgroup_metrics(frame: pd.DataFrame, target: np.ndarray, probabilities: np.ndarray) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for group in SUBGROUPS:
        values = frame[group].astype(object).where(frame[group].notna(), "sin_dato").to_numpy()
        result[group] = {}
        for value in sorted(set(values)):
            mask = values == value
            subset = target[mask]
            auc = round(float(roc_auc_score(subset, probabilities[mask])), 4) if len(set(subset)) == 2 else None
            result[group][str(value)] = {
                "n": int(mask.sum()),
                "auc": auc,
                "high_rate": round(float((probabilities[mask] >= THRESHOLDS["high"]).mean()), 4),
            }
    return result


if __name__ == "__main__":  # pragma: no cover
    main()
