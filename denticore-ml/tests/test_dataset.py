"""Conjunto de datos sintético del modelo (TASK-075; PQ-06, OUT-06, DD-12, RNF-170)."""

from pathlib import Path

import pandas as pd
import pytest

from denticore_ml import dataset
from denticore_ml.features import CATEGORIES, FEATURES, INTEGER_RANGES

CARD = Path(__file__).resolve().parents[1] / "docs" / "dataset_card.md"


@pytest.fixture(scope="module")
def frame() -> pd.DataFrame:
    return dataset.generate(rows=5000, seed=7)


def test_produces_the_same_hash_for_the_same_seed() -> None:
    first = dataset.dataset_hash(dataset.generate(rows=2000, seed=11))
    second = dataset.dataset_hash(dataset.generate(rows=2000, seed=11))

    assert first == second
    assert len(first) == 64
    assert dataset.dataset_hash(dataset.generate(rows=2000, seed=12)) != first


def test_keeps_every_variable_inside_its_domain(frame: pd.DataFrame) -> None:
    assert list(frame.columns) == ["age_group", *FEATURES, dataset.TARGET]

    for name, (low, high) in INTEGER_RANGES.items():
        values = frame[name].dropna()
        assert values.between(low, high).all(), name
        assert (values == values.round()).all(), name
    assert frame["plaque_index_pct"].between(0, 100).all()
    assert (frame["plaque_index_pct"] * 10 == (frame["plaque_index_pct"] * 10).round()).all()
    for name, domain in CATEGORIES.items():
        assert set(frame[name].dropna()) <= set(domain), name
    assert set(frame["fluoride_toothpaste"]) == {True, False}
    assert set(frame[dataset.TARGET]) == {0, 1}


def test_applies_the_rules_of_age_group_and_dentition(frame: pd.DataFrame) -> None:
    minors = frame[frame["age_group"] == "menor"]
    adults = frame[frame["age_group"] == "adulto"]

    assert (minors["age_years"] < 18).all() and (adults["age_years"] >= 18).all()
    # La estructura familiar solo se registra en menores (SDD §2.14.2).
    assert minors["family_structure"].notna().all() and adults["family_structure"].isna().all()
    # DI-20: dentición temporal → ceod; permanente → CPOD; mixta → ambos.
    assert frame.loc[frame["age_years"] < dataset.MIXED_DENTITION_FROM, "cpod"].isna().all()
    assert frame.loc[frame["age_years"] >= dataset.PERMANENT_DENTITION_FROM, "ceod"].isna().all()
    assert (frame["cpod"].notna() | frame["ceod"].notna()).all()


def test_matches_the_assumed_incidence(frame: pd.DataFrame) -> None:
    assert frame[dataset.TARGET].mean() == pytest.approx(dataset.TARGET_INCIDENCE, abs=0.03)


def test_versions_the_card_with_the_code() -> None:
    card = CARD.read_text(encoding="utf-8")

    assert f"Versión del conjunto: {dataset.DATASET_VERSION}" in card
    assert "OUT-06" in card and "no demuestra validez clínica" in card
    for name in FEATURES:
        assert f"`{name}`" in card, name


def test_writes_the_csv_and_prints_its_hash(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    output = tmp_path / "dataset.csv"

    dataset.main(["--rows", "300", "--seed", "3", "--out", str(output)])

    expected = dataset.dataset_hash(dataset.generate(rows=300, seed=3))
    assert capsys.readouterr().out.strip() == expected
    assert dataset.file_hash(output) == expected
