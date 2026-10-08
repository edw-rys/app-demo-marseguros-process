"""Fixtures compartidas del Stack B.

Los tests NUNCA salen a internet: `respx` intercepta httpx, de modo que una
prueba que se pase por alto porque "la red estaba caída" es imposible.
"""

from __future__ import annotations

import base64
import json
from pathlib import Path

import pytest

from app.config import settings

TOKEN = "test-token-para-stack-b"


@pytest.fixture(autouse=True)
def token_de_test(monkeypatch):
    """El middleware exige bearer; sin esto todos los tests darían 401."""
    monkeypatch.setattr(settings, "analyzer_token", TOKEN)
    monkeypatch.setattr(settings, "openrouter_api_key", "sk-or-v1-falsa-para-tests")


@pytest.fixture(autouse=True)
def sin_cache():
    """Cada test arranca sin traces de un test anterior."""
    settings.cache_enabled = False
    yield
    settings.cache_enabled = True


@pytest.fixture
def auth() -> dict[str, str]:
    return {"Authorization": f"Bearer {TOKEN}"}


@pytest.fixture
def png(tmp_path: Path) -> Path:
    """Un PNG de 1×1 válido, para probar la rama binaria (RF-04)."""
    import io

    try:
        from PIL import Image
    except ImportError:  # pragma: no cover - pillow no está en requirements de B
        # PNG mínimo hardcodeado, suficiente para `filetype`.
        data = base64.b64decode(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
        )
        path = tmp_path / "documento.png"
        path.write_bytes(data)
        return path

    buf = io.BytesIO()
    Image.new("RGB", (1, 1), "white").save(buf, format="PNG")
    path = tmp_path / "documento.png"
    path.write_bytes(buf.getvalue())
    return path


@pytest.fixture
def csv_file(tmp_path: Path) -> Path:
    path = tmp_path / "factura.csv"
    path.write_text(
        "concepto,monto\nFactura 001,$120.00\nTotal,$120.00\n", encoding="utf-8"
    )
    return path


def openrouter_body(payload: dict) -> dict:
    """Respuesta con la forma exacta que devuelve OpenRouter."""
    text = json.dumps(payload)
    return {
        "id": "gen-123",
        "model": "google/gemini-2.0-flash-lite",
        "choices": [
            {
                "finish_reason": "stop",
                "message": {"role": "assistant", "content": text},
            }
        ],
        "usage": {
            "prompt_tokens": 120,
            "completion_tokens": 45,
            "total_tokens": 165,
            "cost": 0.0000231,
        },
    }