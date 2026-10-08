"""Contrato HTTP del Stack A — el mismo que consume Laravel."""

from __future__ import annotations

from pathlib import Path

import pytest

from fastapi.testclient import TestClient

from app.config import settings
from app.main import app

client = TestClient(app)


# ── Salud y autenticación ───────────────────────────────────────────────────


def test_healthz_no_pide_token():
    r = client.get("/healthz")

    assert r.status_code == 200
    assert r.json()["status"] == "ok"
    assert r.json()["stack"] == "a"


@pytest.mark.parametrize(
    "ruta,payload",
    [
        ("/analyze", {"path": "/tmp/x.txt", "filename": "x.txt"}),
        ("/validate", {"facts": {}, "doc_type": "factura", "rules": []}),
        ("/generate-reply", {"status": "failed"}),
    ],
)
def test_sin_bearer_responde_401(ruta, payload, auth):
    assert client.post(ruta, json=payload).status_code == 401


# ── /analyze ────────────────────────────────────────────────────────────────


def test_analyze_camino_feliz(txt_file, auth, reglas_factura, patrones_factura):
    r = client.post(
        "/analyze",
        headers=auth,
        json={
            "attachment_id": "att-1",
            "path": str(txt_file),
            "filename": txt_file.name,
            "doc_type_rules": [rule.model_dump() for rule in reglas_factura],
            "field_patterns": patrones_factura,
        },
    )

    assert r.status_code == 200, r.text
    body = r.json()
    assert body["doc_type"] == "factura"
    assert body["confidence"] >= 0.4
    assert body["fields"]["ruc_emisor"] == "1790012345001"
    assert body["fields"]["ruc_emisor_valid"] is True
    assert body["size_bytes"] > 0
    # El desglose del score es lo que explica la decisión en el admin.
    assert body["score_breakdown"]["factura"]["total"] > 0


def test_analyze_devuelve_text_path(tmp_path, auth, monkeypatch):
    """El texto extraído se guarda aparte para poder revisarlo a mano."""
    dumps = tmp_path / "dumps"
    monkeypatch.setattr(settings, "text_dump_dir", str(dumps))

    source = tmp_path / "factura.txt"
    source.write_text("Factura RUC 1790012345001 total 120", encoding="utf-8")

    r = client.post(
        "/analyze",
        headers=auth,
        json={"path": str(source), "filename": source.name, "attachment_id": "att-2"},
    )

    text_path = r.json()["text_path"]
    assert text_path is not None
    assert Path(text_path).exists()
    assert "1790012345001" in Path(text_path).read_text(encoding="utf-8")


def test_analyze_exe_renombrado_da_error(exe_disfrazado, auth):
    """CU-04: el mensaje que ve el cliente dice "no es un PDF válido"."""
    r = client.post(
        "/analyze",
        headers=auth,
        json={
            "path": str(exe_disfrazado),
            "filename": exe_disfrazado.name,
            "attachment_id": "att-3",
        },
    )

    assert r.status_code == 200
    body = r.json()
    assert "no soportado" in body["error"].lower()
    assert body["detected_kind"] == "exe"
    assert body["extension_mismatch"] is True


def test_analyze_archivo_inexistente(auth):
    r = client.post(
        "/analyze",
        headers=auth,
        json={"path": "/tmp/no-existe-xyz.txt", "filename": "no-existe-xyz.txt"},
    )

    assert "No existe el archivo" in r.json()["error"]


def test_analyze_sin_reglas_devuelve_desconocido(csv_file, auth):
    """Sin reglas no se inventa el tipo documental."""
    r = client.post(
        "/analyze",
        headers=auth,
        json={"path": str(csv_file), "filename": csv_file.name},
    )

    assert r.json()["doc_type"] == "desconocido"


# ── /validate ───────────────────────────────────────────────────────────────


def test_validate_con_regla_que_se_dispara(auth, regla_vigencia):
    r = client.post(
        "/validate",
        headers=auth,
        json={
            "facts": {"fecha_emision": "2026-01-01", "doc_type": "factura"},
            "doc_type": "factura",
            "today": "2026-10-07",
            "rules": [rule.model_dump() for rule in regla_vigencia],
        },
    )

    assert r.status_code == 200
    body = r.json()
    assert body["approved"] is False
    assert body["results"][0]["code"] == "FACTURA_VENCIDA"
    assert body["results"][0]["severity"] == "error"


def test_validate_sin_issues_aprueba(auth, regla_vigencia):
    r = client.post(
        "/validate",
        headers=auth,
        json={
            "facts": {"fecha_emision": "2026-10-01", "doc_type": "factura"},
            "doc_type": "factura",
            "today": "2026-10-07",
            "rules": [rule.model_dump() for rule in regla_vigencia],
        },
    )

    assert r.json()["approved"] is True


def test_validate_sin_reglas_aprueba(auth):
    r = client.post(
        "/validate",
        headers=auth,
        json={"facts": {}, "doc_type": "factura", "today": "2026-10-07"},
    )

    assert r.json()["results"] == []
    assert r.json()["approved"] is True


# ── /generate-reply ─────────────────────────────────────────────────────────


def test_generate_reply_usa_template(auth):
    """Stack A no llama a ningún LLM: `source` tiene que decirlo."""
    r = client.post(
        "/generate-reply",
        headers=auth,
        json={"status": "failed", "missing_documents": ["factura"]},
    )

    assert r.status_code == 200
    assert r.json()["source"] == "template"
    assert len(r.json()["body"]) > 0


def test_generate_reply_es_deterministico(auth):
    """Misma entrada, mismo texto: es un template, no una generación."""
    payload = {"status": "failed", "missing_documents": ["factura"]}

    primero = client.post("/generate-reply", headers=auth, json=payload).json()["body"]
    segundo = client.post("/generate-reply", headers=auth, json=payload).json()["body"]

    assert primero == segundo


def test_generate_reply_de_un_validado(auth):
    r = client.post("/generate-reply", headers=auth, json={"status": "validated"})

    assert "validada" in r.json()["body"].lower()