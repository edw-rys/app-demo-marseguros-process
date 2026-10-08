"""OCR en dos niveles: Tesseract primero, PaddleOCR como fallback (RF-04).

El criterio de cambio es la confianza media por palabra que devuelve Tesseract:
si baja de `OCR_FALLBACK_THRESHOLD` se reintenta con PaddleOCR.
"""

from __future__ import annotations

import logging
from pathlib import Path

from app.config import settings

log = logging.getLogger(__name__)

# Caché del pipeline de PaddleOCR. Construirlo cuesta segundos (carga modelos),
# así que se inicializa una sola vez y solo si alguien lo pide.
_paddle_pipeline: object | None = None
_paddle_failed: bool = False


def tesseract_available() -> bool:
    import shutil

    return shutil.which(settings.tesseract_cmd) is not None


def ocr_image_tesseract(image_path: str | Path) -> tuple[str, float]:
    """OCR de una imagen con Tesseract.

    Devuelve `(texto, confianza_media)`. La confianza es la media de las
    confianzas por palabra; `-1` si no se pudo calcular.
    """
    from PIL import Image, ImageOps
    import pytesseract

    pytesseract.pytesseract.tesseract_cmd = settings.tesseract_cmd

    with Image.open(image_path) as img:
        # Deskew / binarización básica: gris + autocontrast mejora notablemente
        # el resultado en fotos de celular, que es el caso de uso real (CU-03).
        img = ImageOps.exif_transpose(img)
        gray = ImageOps.grayscale(img)
        gray = ImageOps.autocontrast(gray)

        text = pytesseract.image_to_string(
            gray, lang=settings.tesseract_lang, config="--oem 1 --psm 6"
        )

        try:
            data = pytesseract.image_to_data(
                gray, lang=settings.tesseract_lang, output_type=pytesseract.Output.DICT
            )
            confidences = [
                float(c)
                for c, txt in zip(data.get("conf", []), data.get("text", []))
                # Tesseract usa -1 para "no es texto" y strings vacíos para ruido.
                if txt.strip() and str(c).lstrip("-").isdigit() and float(c) >= 0
            ]
            avg = sum(confidences) / len(confidences) if confidences else -1.0
        except Exception:  # pragma: no cover - depende del binario externo
            avg = -1.0

    return text, avg


def _get_paddle_pipeline():
    """Carga perezosa del OCR de PaddleOCR. Devuelve `None` si no está disponible."""
    global _paddle_pipeline, _paddle_failed

    if _paddle_pipeline is not None:
        return _paddle_pipeline
    if _paddle_failed or not settings.paddleocr_enabled:
        return None

    try:
        from paddleocr import PaddleOCR

        _paddle_pipeline = PaddleOCR(
            lang=settings.paddleocr_lang, use_doc_orientation_classify=False
        )
        return _paddle_pipeline
    except Exception as exc:  # pragma: no cover - depende de instalación opcional
        _paddle_failed = True
        log.warning(
            "PaddleOCR no disponible, se usará solo Tesseract: %s", exc
        )
        return None


def ocr_image_paddle(image_path: str | Path) -> tuple[str, float]:
    """OCR de una imagen con PaddleOCR. Devuelve `(texto, confianza_media)`.

    Si PaddleOCR no está instalado devuelve `("", -1.0)` y el llamador decide.
    """
    pipeline = _get_paddle_pipeline()
    if pipeline is None:
        return "", -1.0

    try:
        raw = pipeline.predict(str(image_path))  # type: ignore[attr-defined]
        texts: list[str] = []
        scores: list[float] = []
        for page in raw:
            data = getattr(page, "json", None)
            payload = data() if callable(data) else data
            if isinstance(payload, dict):
                for rec in payload.get("rec_texts", []) or []:
                    texts.append(str(rec))
                for score in payload.get("rec_scores", []) or []:
                    scores.append(float(score))
        text = "\n".join(texts)
        avg = (sum(scores) / len(scores) * 100.0) if scores else -1.0
        return text, avg
    except Exception as exc:  # pragma: no cover
        log.warning("PaddleOCR falló sobre %s: %s", image_path, exc)
        return "", -1.0


def ocr_with_fallback(image_path: str | Path) -> tuple[str, str | None, float, bool]:
    """OCR de una imagen con la cadena completa de fallbacks (RF-04).

    Returns:
        `(texto, motor_usado, confianza, uso_ocr)`. `motor_usado` es
        `"tesseract"`, `"paddleocr"` o `None` si no se pudo leer nada.
    """
    text, confidence = "", -1.0
    engine: str | None = None

    if tesseract_available():
        text, confidence = ocr_image_tesseract(image_path)
        if text.strip():
            engine = "tesseract"

    # Fallback a PaddleOCR: confianza baja o Tesseract no devolvió nada.
    if settings.paddleocr_enabled and (
        engine is None or confidence < settings.ocr_fallback_threshold
    ):
        reason = (
            "sin texto"
            if engine is None
            else f"confianza {confidence:.1f} < {settings.ocr_fallback_threshold}"
        )
        log.info("Fallback a PaddleOCR en %s (%s)", image_path, reason)
        paddle_text, paddle_conf = ocr_image_paddle(image_path)
        if paddle_text.strip():
            # Solo aceptamos el resultado de Paddle si aporta algo.
            if engine is None or paddle_conf > confidence:
                text, confidence, engine = paddle_text, paddle_conf, "paddleocr"

    return text, engine, confidence, bool(text.strip())