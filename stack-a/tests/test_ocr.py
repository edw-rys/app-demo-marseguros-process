"""Cascade de OCR (RF-04).

Estos tests NO dependen de que Tesseract o PaddleOCR estén instalados en la
máquina: la cascada se prueba con los motores simulados. Lo que no se puede
simular (que Tesseract exista en el sistema) se comprueba con
`tesseract_available()`, que es informativo por diseño.
"""

from __future__ import annotations

import pytest

from app.config import settings
from app.extract import ocr


class _StubPaddle:
    """Replacement de `_get_paddle_pipeline` que no carga el modelo real."""

    def __init__(self, results):
        self._results = results

    def predict(self, _image):
        return self._results


def _ocr_line(text: str, conf: float):
    """Finge una salida de PaddleOCR: `[[(texto, confianza)], ...]`."""
    return [[(text, conf)]]


def test_cascada_devuelve_el_texto_de_tesseract(monkeypatch, tmp_path):
    """Confianza alta → PaddleOCR ni se consulta."""
    monkeypatch.setattr(ocr, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr, "ocr_image_tesseract", lambda _p: ("FACTURA 001", 92.0))

    llamado = {"paddle": False}

    def _nunca_deberia_entrar(_p):
        llamado["paddle"] = True
        return ("", 0.0)

    monkeypatch.setattr(ocr, "ocr_image_paddle", _nunca_deberia_entrar)

    text, engine, confidence, used = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert text == "FACTURA 001"
    assert engine == "tesseract"
    assert confidence == 92.0
    assert used is True
    assert llamado["paddle"] is False


def test_cae_a_paddleocr_con_confianza_baja(monkeypatch, tmp_path):
    """El fallback de RF-04: por debajo del umbral, PaddleOCR relee."""
    monkeypatch.setattr(ocr, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr, "ocr_image_tesseract", lambda _p: ("texto borroso", 41.0))
    monkeypatch.setattr(
        ocr, "_get_paddle_pipeline", lambda: _StubPaddle(_ocr_line("FACTURA 001", 95.0))
    )

    text, engine, confidence, used = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert engine == "paddleocr"
    assert "FACTURA 001" in text
    assert confidence == 95.0
    assert used is True


def test_cae_a_paddleocr_si_tesseract_no_devuelve_texto(monkeypatch, tmp_path):
    monkeypatch.setattr(ocr, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr, "ocr_image_tesseract", lambda _p: ("", 0.0))
    monkeypatch.setattr(
        ocr, "_get_paddle_pipeline", lambda: _StubPaddle(_ocr_line("FACTURA 002", 88.0))
    )

    text, engine, _, used = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert engine == "paddleocr"
    assert "FACTURA 002" in text
    assert used is True


def test_no_pierde_el_mejor_de_los_dos(monkeypatch, tmp_path):
    """Si PaddleOCR devuelve PEOR que Tesseract, gana Tesseract.

    Caer al fallback no puede empeorar el resultado.
    """
    monkeypatch.setattr(ocr, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr, "ocr_image_tesseract", lambda _p: ("bueno", 80.0))
    # El umbral está en 90 para forzar el intento.
    monkeypatch.setattr(settings, "ocr_fallback_threshold", 90.0)
    monkeypatch.setattr(
        ocr, "_get_paddle_pipeline", lambda: _StubPaddle(_ocr_line("peor", 50.0))
    )

    text, engine, confidence, _ = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert engine == "tesseract"
    assert text == "bueno"
    assert confidence == 80.0


def test_sin_tesseract_usa_paddle_directo(monkeypatch, tmp_path):
    """Tesseract no instalado: el servicio debe seguir funcionando."""
    monkeypatch.setattr(ocr, "tesseract_available", lambda: False)
    monkeypatch.setattr(
        ocr, "_get_paddle_pipeline", lambda: _StubPaddle(_ocr_line("solo paddle", 90.0))
    )

    text, engine, _, used = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert engine == "paddleocr"
    assert text == "solo paddle"
    assert used is True


def test_sin_ningun_motor_devuelve_vacio(monkeypatch, tmp_path):
    """Ninguno de los dos disponible: se registra `ocr_used=false`."""
    monkeypatch.setattr(ocr, "tesseract_available", lambda: False)
    monkeypatch.setattr(ocr, "_get_paddle_pipeline", lambda: _StubPaddle(_ocr_line("", 0.0)))

    text, engine, confidence, used = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert text == ""
    assert engine is None
    assert used is False


def test_paddle_desactivado_no_se_intenta(monkeypatch, tmp_path):
    """`PADDLEOCR_ENABLED=false` tiene que respetarse aunque Tesseract falle."""
    monkeypatch.setattr(settings, "paddleocr_enabled", False)
    monkeypatch.setattr(ocr, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr, "ocr_image_tesseract", lambda _p: ("", 0.0))

    def _nunca(_p):
        raise AssertionError("PaddleOCR no debería llamarse con paddleocr_enabled=false")

    monkeypatch.setattr(ocr, "_get_paddle_pipeline", _nunca)

    _text, engine, _, used = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert engine is None
    assert used is False


def test_paddleocr_roto_no_tumba_el_ocr(monkeypatch, tmp_path):
    """Si el modelo de Paddle no carga, se conserva lo que dio Tesseract.

    Perder el OCR entero por un problema del tier 2 sería peor que leer
    peor pero leer algo.
    """
    monkeypatch.setattr(ocr, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr, "ocr_image_tesseract", lambda _p: ("tesseract ok", 55.0))
    monkeypatch.setattr(settings, "ocr_fallback_threshold", 60.0)

    def _roto(_p):
        raise RuntimeError("modelo no encontrado")

    monkeypatch.setattr(ocr, "_get_paddle_pipeline", _roto)

    text, engine, confidence, _used = ocr.ocr_with_fallback(tmp_path / "x.png")

    assert text == "tesseract ok"
    assert engine == "tesseract"
    assert confidence == 55.0


# ── Umbral configurable ─────────────────────────────────────────────────────


def test_el_umbral_es_configurable(monkeypatch):
    assert settings.ocr_fallback_threshold == 60.0


def test_tesseract_available_devuelve_bool():
    """Informativo: el admin lo usa para mostrar si el worker tiene OCR."""
    assert isinstance(ocr.tesseract_available(), bool)