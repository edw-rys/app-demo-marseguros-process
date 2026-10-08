"""Fixtures compartidas del Stack A."""

from __future__ import annotations

from datetime import date
from pathlib import Path

import pytest

from app.config import settings
from app.models import DocTypeRule, ValidationRule

TOKEN = "test-token-para-stack-a"


@pytest.fixture(autouse=True)
def token_de_test(monkeypatch):
    monkeypatch.setattr(settings, "analyzer_token", TOKEN)


@pytest.fixture
def auth() -> dict[str, str]:
    return {"Authorization": f"Bearer {TOKEN}"}


@pytest.fixture
def hoy() -> date:
    return date(2026, 10, 7)


# ── Documentos de prueba ────────────────────────────────────────────────────


@pytest.fixture
def txt_file(tmp_path: Path) -> Path:
    """Un .txt con los datos de una factura: no necesita OCR."""
    path = tmp_path / "factura-agosto.txt"
    path.write_text(
        "FACTURA DE VENTA\n"
        "RUC emisor: 1790012345001\n"
        "Señor: Juan Pérez\n"
        "Fecha de emisión: 2026-09-01\n"
        "Total: $1.250,00\n",
        encoding="utf-8",
    )
    return path


@pytest.fixture
def exe_disfrazado(tmp_path: Path) -> Path:
    """CU-04: ejecutable renombrado como factura.pdf."""
    path = tmp_path / "factura.pdf"
    path.write_bytes(b"MZ\x90\x00\x03" + b"\x00" * 500)
    return path


@pytest.fixture
def csv_file(tmp_path: Path) -> Path:
    path = tmp_path / "factura.csv"
    path.write_text("concepto,monto\nFactura 001,120.00\nTotal,120.00\n", encoding="utf-8")
    return path


# ── Configuración que Laravel manda en cada request ──────────────────────────


@pytest.fixture
def reglas_factura() -> list[DocTypeRule]:
    return [
        DocTypeRule(
            name="factura",
            label="Factura",
            required_keywords=["factura", "ruc"],
            keywords=["total", "iva", "emisión"],
            filename_hints=["factura"],
        ),
        DocTypeRule(
            name="poliza",
            label="Póliza",
            required_keywords=["póliza", "prima"],
            keywords=["vigencia"],
            filename_hints=["poliza"],
        ),
    ]


@pytest.fixture
def patrones_factura() -> dict[str, str]:
    return {
        "ruc_emisor": r"RUC(?:\s+emisor)?:?\s*(\d{13})",
        "fecha_emision": r"Fecha de emisión:?\s*(\d{4}-\d{2}-\d{2})",
        "total": r"Total:?\s*\$?\s*([\d.,]+)",
    }


@pytest.fixture
def regla_vigencia() -> list[ValidationRule]:
    """Regla de Fase 2: una factura con más de 90 días está vencida."""
    return [
        ValidationRule(
            name="factura_vigente",
            severity="error",
            doc_types=["factura"],
            json_rule={
                "conditions": {
                    "all": [
                        {
                            "fact": "days_since_fecha_emision",
                            "operator": "greaterThan",
                            "value": 90,
                        }
                    ]
                },
                "message": "La factura tiene más de 90 días.",
                "code": "FACTURA_VENCIDA",
            },
        )
    ]