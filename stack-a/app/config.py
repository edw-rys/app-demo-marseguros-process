"""Configuración del Stack A (OCR local + motor de reglas JSON).

Todo se configura por variables de entorno (12-factor) con valores por defecto
pensados para un macOS de desarrollo.
"""

from __future__ import annotations

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Variables de entorno del servicio. Ver `.env.example`."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # ── Seguridad ──────────────────────────────────────────────────────────────
    # Bearer compartido con Laravel. Vacío desactiva la verificación (solo dev).
    analyzer_token: str = ""

    # ── OCR tier 1: Tesseract ─────────────────────────────────────────────────
    tesseract_cmd: str = "/opt/homebrew/bin/tesseract"
    tesseract_lang: str = "spa+eng"

    # Umbral de caracteres extraídos por página para considerar que un PDF tiene
    # capa de texto embebida. Por debajo → ruta OCR (RF-04).
    pdf_text_min_chars: int = 40

    # Si la confianza media de Tesseract baja de este valor, se reintenta con
    # PaddleOCR (RF-04).
    ocr_fallback_threshold: float = 60.0

    # ── OCR tier 2: PaddleOCR ─────────────────────────────────────────────────
    paddleocr_enabled: bool = True
    paddleocr_lang: str = "es"

    # ── Rasterizado de PDF ────────────────────────────────────────────────────
    pdftoppm_cmd: str = "/opt/homebrew/bin/pdftoppm"
    # Resolución DPI al rasterizar. Más DPI = mejor OCR pero más lento.
    raster_dpi: int = 300

    # ── Reglas de clasificación ───────────────────────────────────────────────
    # RF-05: score < 0.4 → "desconocido"
    classify_min_score: float = 0.4

    # ── Varios ────────────────────────────────────────────────────────────────
    max_text_chars: int = 200_000
    # Directorio donde se escriben los `{archivo}.extracted.txt` para debug (RF-04).
    text_dump_dir: str = ""


settings = Settings()