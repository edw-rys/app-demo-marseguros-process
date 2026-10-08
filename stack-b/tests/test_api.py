"""Contrato HTTP del Stack B.

Lo que se prueba aquí es lo que Laravel da por sentado: los nombres de los
campos, los códigos de estado y, sobre todo, que una caída de OpenRouter
produciga un **503** (que es lo que dispara el fallback a Stack A, CU-05).
"""

from __future__ import annotations

import httpx
import pytest
import respx

from fastapi.testclient import TestClient

from app.config import settings
from app.main import app

from .conftest import openrouter_body

CHAT = f"{settings.openrouter_base_url.rstrip('/')}/chat/completions"

client = TestClient(app)


# ── Salud y autenticación ───────────────────────────────────────────────────


def test_healthz_no_pide_token():
    r = client.get("/healthz")

    assert r.status_code == 200
    assert r.json()["status"] == "ok"
    assert r.json()["stack"] == "b"


def test_healthz_no_filtra_la_key():
    """La key no puede aparecer en la respuesta aunque esté configurada."""
    settings.openrouter_api_key = "sk-or-v1-secreta"

    body = client.get("/healthz").text

    assert "sk-or-v1-secreta" not in body


@pytest.mark.parametrize(
    "ruta,payload",
    [
        ("/analyze", {"attachment_id": "a", "path": "/tmp/x.pdf", "filename": "x.pdf"}),
        ("/validate", {"facts": {}, "doc_type": "factura", "rules": []}),
        ("/generate-reply", {"status": "failed", "issues": []}),
    ],
)
def test_sin_bearer_responde_401(ruta, payload, auth):
    assert client.post(ruta, json=payload).status_code == 401


def test_bearer_incorrecto_responde_401(auth):
    r = client.post(
        "/validate",
        json={"facts": {}, "doc_type": "factura", "rules": []},
        headers={"Authorization": "Bearer equivocado"},
    )

    assert r.status_code == 401


# ── /analyze ────────────────────────────────────────────────────────────────


@respx.mock
def test_analyze_clasifica_y_extrae_para(csv_file, auth):
    """El camino feliz: clasifica, extrae y devuelve las trazas de auditoría."""
    respuestas = [
        {
            "doc_type": "factura",
            "confidence": 0.93,
            "reasoning": "El documento menciona RUC, total e IVA.",
            "key_signals": ["ruc", "iva"],
        },
        # El prompt EXTRACT pide las claves del schema en el nivel superior,
        # no anidadas bajo "fields".
        {"ruc_emisor": "1790012345001", "total": "120.00", "fecha_emision_reason": "no_encontrado"},
    ]
    # `side_effect` y no dos `.mock()`: la segunda llamada a mock() REEMPLAZA
    # el return value y las dos peticiones recibirían la misma respuesta.
    respx.post(CHAT).mock(
        side_effect=[
            httpx.Response(200, json=openrouter_body(payload))
            for payload in respuestas
        ]
    )

    r = client.post(
        "/analyze",
        headers=auth,
        json={
            "attachment_id": "att-1",
            "path": str(csv_file),
            "filename": csv_file.name,
            "doc_type_rules": [{"name": "factura"}],
            "field_patterns": {},
        },
    )

    assert r.status_code == 200, r.text
    body = r.json()
    assert body["doc_type"] == "factura"
    assert body["confidence"] == pytest.approx(0.93)
    assert body["fields"]["ruc_emisor"] == "1790012345001"
    # Las claves `*_reason` no son campos: solo explican una ausencia.
    assert "fecha_emision_reason" not in body["fields"]
    # Stack B no pre-extrae: el texto del CSV sí viaja como contexto.
    assert "Factura 001" in body["text"]
    assert body["ocr_used"] is False
    # RF-04: las trazas van en la respuesta para que Laravel las persista.
    assert len(body["_llm_traces"]) == 2
    assert body["_llm_traces"][0]["cost_usd"] > 0


@respx.mock
def test_analyze_rechaza_exe_renombrado_sin_gastar_tokens(tmp_path, auth):
    """CU-04: un ejecutable disfrazado de PDF se rechaza antes del LLM."""
    exe = tmp_path / "factura.pdf"
    exe.write_bytes(b"MZ\x90\x00" + b"\x00" * 200)

    respx.post(CHAT)

    r = client.post(
        "/analyze",
        headers=auth,
        json={
            "attachment_id": "att-2",
            "path": str(exe),
            "filename": exe.name,
            "doc_type_rules": [],
        },
    )

    assert r.status_code == 200
    body = r.json()
    assert "no soportado" in body["error"].lower()
    assert body["extension_mismatch"] is True
    # Lo importante: ni una sola llamada al LLM.
    assert respx.calls.call_count == 0


def test_analyze_archivo_inexistente_devuelve_error(tmp_path, auth):
    r = client.post(
        "/analyze",
        headers=auth,
        json={
            "attachment_id": "att-3",
            "path": str(tmp_path / "no-existe.pdf"),
            "filename": "no-existe.pdf",
        },
    )

    assert r.status_code == 200
    assert "No existe el archivo" in r.json()["error"]


@respx.mock
def test_caida_de_openrouter_devuelve_503_para_el_fallback(csv_file, auth):
    """CU-05: un 503 aquí es lo que hace que Laravel caiga a Stack A."""
    respx.post(CHAT).mock(
        return_value=httpx.Response(503, text="upstream caído")
    )

    r = client.post(
        "/analyze",
        headers=auth,
        json={
            "attachment_id": "att-4",
            "path": str(csv_file),
            "filename": csv_file.name,
        },
    )

    assert r.status_code == 503
    assert r.json()["error"].startswith("DEGRADED:")


@respx.mock
def test_error_de_key_no_dispara_reintentos(csv_file, auth):
    """Un 401 es culpa de la configuración, no una caída: no se reintenta."""
    route = respx.post(CHAT).mock(
        return_value=httpx.Response(401, text="No auth")
    )

    r = client.post(
        "/analyze",
        headers=auth,
        json={
            "attachment_id": "att-5",
            "path": str(csv_file),
            "filename": csv_file.name,
        },
    )

    assert r.status_code == 503
    # llm_max_retries=2 → 1 intento + 0 reintentos porque 401 no es retryable.
    assert route.call_count == 1


# ── /validate ───────────────────────────────────────────────────────────────


@respx.mock
def test_validate_devuelve_issues_con_severidad(auth):
    respx.post(CHAT).mock(
        return_value=httpx.Response(
            200,
            json=openrouter_body(
                {
                    "approved": False,
                    "issues": [
                        {
                            "code": "FACTURA_VENCIDA",
                            "severity": "error",
                            "message": "La factura tiene más de 90 días.",
                        }
                    ],
                }
            ),
        )
    )

    r = client.post(
        "/validate",
        headers=auth,
        json={
            "facts": {"fecha_emision": "2026-01-01"},
            "doc_type": "factura",
            "today": "2026-10-07",
            "prompts": {"validate_factura_v1": {"prompt_text": "Chequea la vigencia."}},
        },
    )

    assert r.status_code == 200, r.text
    body = r.json()
    assert body["approved"] is False
    assert body["results"][0]["code"] == "FACTURA_VENCIDA"
    assert body["results"][0]["severity"] == "error"
    assert body["results"][0]["status"] == "fail"


@respx.mock
def test_validate_sin_issues_devuelve_pass(auth):
    """Sin problemas, el resultado tiene que ser un `pass` explícito.

    Antes esto devolvía una lista vacía y Laravel no tenía con qué marcar la
    etapa como exitosa.
    """
    respx.post(CHAT).mock(
        return_value=httpx.Response(
            200, json=openrouter_body({"approved": True, "issues": []})
        )
    )

    r = client.post(
        "/validate",
        headers=auth,
        json={
            "facts": {},
            "doc_type": "factura",
            "prompts": {"validate_factura_v1": {"prompt_text": "Chequea."}},
        },
    )

    assert r.json()["results"] == [
        {
            "rule_name": "validate_factura_v1",
            "status": "pass",
            "severity": None,
            "message": "El LLM no encontró problemas.",
            "code": "",
        }
    ]


def test_validate_sin_prompt_devuelve_422(auth):
    r = client.post(
        "/validate",
        headers=auth,
        json={"facts": {}, "doc_type": "factura", "prompts": {}},
    )

    assert r.status_code == 422


@respx.mock
def test_llm_que_dice_approved_con_error_error_manda(auth):
    """Si el LLM se contradice, la severidad del issue gana (RF-08)."""
    respx.post(CHAT).mock(
        return_value=httpx.Response(
            200,
            json=openrouter_body(
                {
                    "approved": True,
                    "issues": [
                        {"code": "X", "severity": "error", "message": "Falta algo"}
                    ],
                }
            ),
        )
    )

    r = client.post(
        "/validate",
        headers=auth,
        json={
            "facts": {},
            "doc_type": "factura",
            "prompts": {"validate_factura_v1": {"prompt_text": "Chequea."}},
        },
    )

    assert r.json()["approved"] is False


# ── /generate-reply ─────────────────────────────────────────────────────────


@respx.mock
def test_generate_reply_corta_a_800_caracteres(auth, monkeypatch):
    monkeypatch.setattr(settings, "reply_max_chars", 800)

    respx.post(CHAT).mock(
        return_value=httpx.Response(
            200,
            json=openrouter_body(
                {"body": "Estimado cliente, " + ("x" * 2000)}
            ),
        )
    )

    r = client.post(
        "/generate-reply",
        headers=auth,
        json={"status": "failed", "issues": [{"message": "Falta la factura"}]},
    )

    assert r.status_code == 200
    body = r.json()["body"]
    assert len(body) <= 801
    assert body.startswith("Estimado cliente")


# ── /test-prompt ────────────────────────────────────────────────────────────


@respx.mock
def test_test_prompt_devuelve_el_json_crudo(auth):
    """El botón "Probar prompt" del admin depende de este shape."""
    respx.post(CHAT).mock(
        return_value=httpx.Response(
            200, json=openrouter_body({"doc_type": "factura", "confidence": 0.8})
        )
    )

    r = client.post(
        "/test-prompt", headers=auth, json={"prompt_text": "Clasifica esto."}
    )

    assert r.status_code == 200
    assert r.json()["result"]["doc_type"] == "factura"
    assert r.json()["traces"][0]["purpose"] == "test_prompt"


def test_test_prompt_vacio_responde_422(auth):
    assert client.post("/test-prompt", headers=auth, json={"prompt_text": " "}).status_code == 422