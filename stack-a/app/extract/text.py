"""Estrategia de extracción de texto por tipo de archivo (RF-04).

| Tipo                | Estrategia                          | Herramienta      |
|---------------------|-------------------------------------|------------------|
| PDF nativo          | Directo                             | pdfplumber       |
| PDF escaneado       | Rasterizar + OCR                    | pdftoppm + OCR   |
| Imagen              | OCR                                 | Tesseract→Paddle |
| XLSX                | Celdas                              | openpyxl         |
| DOCX                | Párrafos                            | python-docx      |
| CSV / TXT           | Lectura directa                     | stdlib           |
"""

from __future__ import annotations

import csv
import logging
import shutil
import subprocess
import tempfile
from pathlib import Path

from app.config import settings
from app.extract import kind as kind_detector
from app.extract.ocr import ocr_with_fallback

log = logging.getLogger(__name__)


class ExtractionResult:
    """Resultado de la extracción de texto de un archivo."""

    def __init__(
        self,
        text: str = "",
        ocr_used: bool = False,
        ocr_engine: str | None = None,
        ocr_confidence: float | None = None,
    ) -> None:
        self.text = text
        self.ocr_used = ocr_used
        self.ocr_engine = ocr_engine
        self.ocr_confidence = ocr_confidence

    def as_dict(self) -> dict[str, object]:
        return {
            "text": self.text,
            "ocr_used": self.ocr_used,
            "ocr_engine": self.ocr_engine,
            "ocr_confidence": self.ocr_confidence,
        }


# ── PDF ──────────────────────────────────────────────────────────────────────


def extract_pdf(path: str | Path) -> ExtractionResult:
    """Extrae texto de un PDF: capa de texto embebida primero, OCR si no la hay."""
    import pdfplumber

    pages: list[str] = []
    chars_per_page: list[int] = []
    with pdfplumber.open(str(path)) as pdf:
        for page in pdf.pages:
            page_text = page.extract_text() or ""
            pages.append(page_text)
            chars_per_page.append(len(page_text.strip()))

    # Si alguna página viene vacía (típico de un escaneo), el documento entero se
    # considera escaneado: OCR sobre todas las páginas mantiene el orden.
    mean_chars = (sum(chars_per_page) / len(chars_per_page)) if chars_per_page else 0.0

    if mean_chars >= settings.pdf_text_min_chars:
        text = "\n\n".join(p for p in pages if p.strip())
        return ExtractionResult(text=text[: settings.max_text_chars])

    log.info("PDF sin capa de texto suficiente (%.0f chars/página) → OCR", mean_chars)
    return extract_pdf_via_ocr(path)


def extract_pdf_via_ocr(path: str | Path) -> ExtractionResult:
    """Rasteriza cada página con `pdftoppm` y le pasa OCR."""
    if not shutil.which(settings.pdftoppm_cmd):
        log.error("pdftoppm no disponible en %s", settings.pdftoppm_cmd)
        return ExtractionResult()

    chunks: list[str] = []
    engines: list[str] = []
    confidences: list[float] = []

    with tempfile.TemporaryDirectory(prefix="gdv-ocr-") as tmp:
        prefix = str(Path(tmp) / "page")
        try:
            subprocess.run(
                [
                    settings.pdftoppm_cmd,
                    "-r",
                    str(settings.raster_dpi),
                    "-png",
                    str(path),
                    prefix,
                ],
                check=True,
                capture_output=True,
                timeout=180,
            )
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
            log.error("pdftoppm falló: %s", exc)
            return ExtractionResult()

        # Orden lexicográfico de las páginas: page-1, page-2, ... page-10.
        for page_png in sorted(Path(tmp).glob("page*.png")):
            text, engine, confidence, _ = ocr_with_fallback(page_png)
            if text.strip():
                chunks.append(text)
                if engine:
                    engines.append(engine)
                if confidence >= 0:
                    confidences.append(confidence)

    text = "\n\n".join(chunks)[: settings.max_text_chars]

    # Si alguna página necesitó Paddle, el motor reportado es el peor de los dos.
    engine = None
    if engines:
        engine = "paddleocr" if "paddleocr" in engines else "tesseract"
    avg_conf = (sum(confidences) / len(confidences)) if confidences else None

    return ExtractionResult(
        text=text,
        ocr_used=bool(text.strip()),
        ocr_engine=engine,
        ocr_confidence=avg_conf,
    )


# ── Imagen ───────────────────────────────────────────────────────────────────


def extract_image(path: str | Path) -> ExtractionResult:
    text, engine, confidence, used = ocr_with_fallback(path)
    return ExtractionResult(
        text=text[: settings.max_text_chars],
        ocr_used=used,
        ocr_engine=engine,
        ocr_confidence=confidence if confidence >= 0 else None,
    )


# ── Office ───────────────────────────────────────────────────────────────────


def extract_xlsx(path: str | Path) -> ExtractionResult:
    from openpyxl import load_workbook

    wb = load_workbook(str(path), read_only=True, data_only=True)
    lines: list[str] = []
    try:
        for sheet in wb.worksheets:
            lines.append(f"## Hoja: {sheet.title}")
            for row in sheet.iter_rows(values_only=True):
                cells = [str(c) for c in row if c is not None and str(c).strip()]
                if cells:
                    lines.append(" | ".join(cells))
    finally:
        wb.close()

    return ExtractionResult(text="\n".join(lines)[: settings.max_text_chars])


def extract_docx(path: str | Path) -> ExtractionResult:
    import docx

    document = docx.Document(str(path))
    paragraphs = [p.text for p in document.paragraphs if p.text.strip()]

    # Las tablas de un DOCX son partes importantes de una póliza; se recorren también.
    for table in document.tables:
        for row in table.rows:
            cells = [c.text.strip() for c in row.cells if c.text.strip()]
            if cells:
                paragraphs.append(" | ".join(cells))

    return ExtractionResult(text="\n".join(paragraphs)[: settings.max_text_chars])


# ── Texto plano ──────────────────────────────────────────────────────────────


def extract_csv(path: str | Path) -> ExtractionResult:
    rows: list[str] = []
    with open(path, newline="", encoding="utf-8", errors="replace") as fh:
        for row in csv.reader(fh):
            if any(cell.strip() for cell in row):
                rows.append(" | ".join(cell.strip() for cell in row))
    return ExtractionResult(text="\n".join(rows)[: settings.max_text_chars])


def extract_text_file(path: str | Path) -> ExtractionResult:
    return ExtractionResult(
        text=Path(path).read_text(encoding="utf-8", errors="replace")[
            : settings.max_text_chars
        ]
    )


# ── Despachador ──────────────────────────────────────────────────────────────


def extract_text(path: str | Path, kind: str) -> ExtractionResult:
    """Extrae texto del archivo según su tipo real (`kind`)."""
    try:
        if kind == "pdf":
            return extract_pdf(path)
        if kind_detector.is_image(kind):
            return extract_image(path)
        if kind == "xlsx":
            return extract_xlsx(path)
        if kind == "docx":
            return extract_docx(path)
        if kind == "csv":
            return extract_csv(path)
        if kind == "txt":
            return extract_text_file(path)
    except Exception as exc:
        log.exception("Fallo extrayendo texto de %s (kind=%s)", path, kind)
        return ExtractionResult(text="", ocr_confidence=-1.0)

    return ExtractionResult()