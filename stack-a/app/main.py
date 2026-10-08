"""Stack A — microservicio de análisis documental local.

Expone el contrato HTTP que comparte con Stack B. No tiene base de datos ni
estado: toda la configuración (reglas, regex) llega en cada request desde Laravel,
lo que permite editar las reglas en el admin sin redeploy.
"""

from __future__ import annotations

import hashlib
import json
import logging
import time
from datetime import date
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import JSONResponse

from app import classify as classifier
from app import fields as field_extractor
from app import rules as rules_engine
from app.config import settings
from app.extract import kind as kind_detector
from app.extract import text as text_extractor
from app.models import (
    AnalyzeRequest,
    AnalyzeResponse,
    GenerateReplyRequest,
    GenerateReplyResponse,
    ValidateRequest,
    ValidateResponse,
)

logging.basicConfig(
    level=logging.INFO, format="%(asctime)s %(levelname)s [stack-a] %(message)s"
)
log = logging.getLogger(__name__)

app = FastAPI(title="Gmail Docs Validator — Stack A", version="1.0.0")


# ── Seguridad ────────────────────────────────────────────────────────────────


def verify_token(authorization: str | None = Header(default=None)) -> None:
    """Bearer compartido con Laravel. Vacío en `ANALYZER_TOKEN` = abierto (dev)."""
    if not settings.analyzer_token:
        return
    expected = f"Bearer {settings.analyzer_token}"
    if authorization != expected:
        raise HTTPException(status_code=401, detail="Token inválido")


# ── Utilidades ───────────────────────────────────────────────────────────────


def _dump_text(attachment_id: str, filename: str, text: str) -> str | None:
    """Escribe `{archivo}.extracted.txt` para debug (RF-04)."""
    if not settings.text_dump_dir or not text:
        return None
    out_dir = Path(settings.text_dump_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    safe = Path(filename).stem or attachment_id[:8]
    out = out_dir / f"{safe}.extracted.txt"
    out.write_text(text, encoding="utf-8")
    return str(out)


def _parse_today(raw: str | None) -> date:
    if not raw:
        return date.today()
    parsed = field_extractor.parse_date(raw)
    return parsed or date.today()


# ── Rutas ────────────────────────────────────────────────────────────────────


@app.get("/healthz")
def healthz() -> dict[str, object]:
    """Health check (RNF-07). Informa además qué motores de OCR están vivos."""
    from app.extract.ocr import tesseract_available

    return {
        "status": "ok",
        "stack": "a",
        "version": "1.0.0",
        "engines": {
            "tesseract": tesseract_available(),
            "paddleocr": settings.paddleocr_enabled,
        },
        "rules_engine": "python-rule-engine",
    }


@app.post("/analyze", response_model=AnalyzeResponse, dependencies=[Depends(verify_token)])
def analyze(req: AnalyzeRequest) -> AnalyzeResponse:
    """Detecta tipo, extrae texto, clasifica y extrae campos de un adjunto."""
    started = time.perf_counter()
    path = Path(req.path)

    if not path.exists():
        return AnalyzeResponse(
            attachment_id=req.attachment_id,
            error=f"No existe el archivo: {req.path}",
            elapsed_ms=int((time.perf_counter() - started) * 1000),
        )

    size = path.stat().st_size
    detected_kind, mime = kind_detector.detect_kind(path)
    mismatch = kind_detector.has_extension_mismatch(path, detected_kind)

    # Tipo no soportado → cola de revisión. No se intenta extraer nada (RF-03).
    if not kind_detector.is_supported(detected_kind):
        return AnalyzeResponse(
            attachment_id=req.attachment_id,
            detected_kind=detected_kind,
            mime=mime,
            size_bytes=size,
            extension_mismatch=mismatch,
            error=f"Tipo de archivo no soportado: {detected_kind}",
            elapsed_ms=int((time.perf_counter() - started) * 1000),
        )

    extraction = text_extractor.extract_text(path, detected_kind)
    text_path = _dump_text(req.attachment_id, req.filename, extraction.text)

    doc_type, confidence, breakdown = classifier.classify(
        extraction.text, req.filename, req.doc_type_rules
    )
    extracted = field_extractor.extract_fields(extraction.text, req.field_patterns)

    elapsed_ms = int((time.perf_counter() - started) * 1000)
    log.info(
        "analyze %s kind=%s doc_type=%s conf=%.2f ocr=%s %dms",
        req.filename, detected_kind, doc_type, confidence,
        extraction.ocr_engine or "-", elapsed_ms,
    )

    return AnalyzeResponse(
        attachment_id=req.attachment_id,
        detected_kind=detected_kind,
        mime=mime,
        size_bytes=size,
        extension_mismatch=mismatch,
        text=extraction.text,
        text_path=text_path,
        ocr_used=extraction.ocr_used,
        ocr_engine=extraction.ocr_engine,
        ocr_confidence=extraction.ocr_confidence,
        doc_type=doc_type,
        confidence=confidence,
        score_breakdown=breakdown,
        fields=extracted,
        elapsed_ms=elapsed_ms,
    )


@app.post("/validate", response_model=ValidateResponse, dependencies=[Depends(verify_token)])
def validate(req: ValidateRequest) -> ValidateResponse:
    """Fase 2: ejecuta las reglas declarativas contra los facts extraídos."""
    facts = dict(req.facts)
    facts["doc_type"] = req.doc_type

    results, approved = rules_engine.evaluate_rules(
        req.rules, facts, req.doc_type, today=_parse_today(req.today)
    )
    return ValidateResponse(results=results, approved=approved)


@app.post("/generate-reply", response_model=GenerateReplyResponse,
          dependencies=[Depends(verify_token)])
def generate_reply(req: GenerateReplyRequest) -> GenerateReplyResponse:
    """Stack A no usa LLM: compone la respuesta con templates fijos (RF-09).

    La ruta existe para mantener el contrato idéntico al de Stack B; así Laravel
    puede llamar siempre a `/generate-reply` sin saber con qué stack habla.
    """
    from app.templates import render_template

    body = render_template(req)
    return GenerateReplyResponse(body=body, source="template")


@app.exception_handler(Exception)
def unhandled(request, exc: Exception) -> JSONResponse:  # pragma: no cover
    log.exception("Error no controlado en %s", request.url.path)
    return JSONResponse(status_code=500, content={"detail": str(exc)})