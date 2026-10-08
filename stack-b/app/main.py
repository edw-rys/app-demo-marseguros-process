"""Stack B — microservicio de análisis documental vía OpenRouter.

Mismo contrato HTTP que Stack A. El texto del documento va al LLM: PDF e imagen
como binario (RF-04), XLSX/DOCX/CSV/TXT como texto plano.
"""

from __future__ import annotations

import base64
import logging
import time
from pathlib import Path
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import JSONResponse

from app import redact as redactor
from app.config import settings
from app.llm import tasks
from app.models import (
    AnalyzeRequest,
    AnalyzeResponse,
    GenerateReplyRequest,
    GenerateReplyResponse,
    LlmTrace,
    ValidateRequest,
    ValidateResponse,
    ValidationResultItem,
)
from app.openrouter_client import LlmError, client, reset_traces

logging.basicConfig(
    level=logging.INFO, format="%(asctime)s %(levelname)s [stack-b] %(message)s"
)
log = logging.getLogger(__name__)

app = FastAPI(title="Gmail Docs Validator — Stack B", version="1.0.0")

# Tipos que van binarios al LLM. El resto se pre-extrae a texto (RF-04).
_BINARY_MIME_PREFIX = "image/"
_BINARY_MIME_EXACT = {"application/pdf"}


def verify_token(authorization: str | None = Header(default=None)) -> None:
    if not settings.analyzer_token:
        return
    if authorization != f"Bearer {settings.analyzer_token}":
        raise HTTPException(status_code=401, detail="Token inválido")


# ── Preparación del adjunto ──────────────────────────────────────────────────


def _detect_kind(path: Path) -> tuple[str, str | None, bool]:
    """`(kind, mime, extension_mismatch)` por magic bytes (RF-03)."""
    import filetype

    guessed = filetype.guess(str(path))
    if guessed is None:
        ext = path.suffix.lower().lstrip(".")
        return (ext or "unknown"), None, False

    kind = guessed.extension
    mismatch = False
    ext = path.suffix.lower().lstrip(".")
    if ext:
        expected = {"jpeg": "jpg", "tif": "tiff"}.get(ext, ext)
        mismatch = expected != kind and ext in {
            "pdf", "jpg", "jpeg", "png", "xlsx", "docx", "zip", "exe", "heic", "heif",
        }
    return kind, guessed.mime, mismatch


def _pre_extract_text(path: Path, kind: str) -> str:
    """Texto plano para XLSX/DOCX/CSV/TXT (RF-04). Vacío para PDF e imagen."""
    if kind in {"pdf", "jpg", "jpeg", "png", "gif", "bmp", "tiff", "webp"}:
        return ""

    try:
        if kind == "docx":
            import docx

            document = docx.Document(str(path))
            paragraphs = [p.text for p in document.paragraphs if p.text.strip()]
            for table in document.tables:
                for row in table.rows:
                    cells = [c.text.strip() for c in row.cells if c.text.strip()]
                    if cells:
                        paragraphs.append(" | ".join(cells))
            return "\n".join(paragraphs)[: settings.max_text_chars]

        if kind == "xlsx":
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
            return "\n".join(lines)[: settings.max_text_chars]

        # CSV y TXT
        return path.read_text(encoding="utf-8", errors="replace")[
            : settings.max_text_chars
        ]
    except Exception as exc:
        log.warning("No se pudo pre-extraer texto de %s: %s", path, exc)
        return ""


def _encode_binary(path: Path, mime: str | None) -> str | None:
    """Base64 del archivo, solo si su tipo se manda binario al LLM (RF-04)."""
    if not mime:
        return None
    if not (mime.startswith(_BINARY_MIME_PREFIX) or mime in _BINARY_MIME_EXACT):
        return None
    try:
        return base64.b64encode(path.read_bytes()).decode("ascii")
    except OSError as exc:
        log.warning("No se pudo leer %s: %s", path, exc)
        return None


def _allowed_types(rules: list[dict[str, Any]]) -> list[str]:
    return [str(r.get("name")) for r in rules if r.get("name")]


def _prompt_for(prompts: dict[str, Any], key: str) -> str | None:
    entry = prompts.get(key)
    if not isinstance(entry, dict):
        return None
    text = entry.get("prompt_text")
    return str(text) if text else None


# ── Rutas ────────────────────────────────────────────────────────────────────


@app.get("/healthz")
def healthz() -> dict[str, object]:
    return {
        "status": "ok",
        "stack": "b",
        "version": "1.0.0",
        "model": settings.openrouter_model,
        # La key nunca se devuelve; solo si está presente, para no filtrarla.
        "api_key_configured": bool(settings.openrouter_api_key),
    }


@app.post("/analyze", response_model=AnalyzeResponse, dependencies=[Depends(verify_token)])
async def analyze(req: AnalyzeRequest) -> JSONResponse:
    """Clasifica con el LLM, extrae campos y devuelve las trazas de auditoría."""
    started = time.perf_counter()
    reset_traces()

    path = Path(req.path)
    if not path.exists():
        return JSONResponse(
            AnalyzeResponse(
                attachment_id=req.attachment_id,
                error=f"No existe el archivo: {req.path}",
            ).model_dump()
        )

    kind, mime, mismatch = _detect_kind(path)
    if kind in {"exe", "zip"} or mime == "application/octet-stream":
        # Documento no soportado → cola de revisión, sin gastar tokens (RF-03).
        return JSONResponse(
            AnalyzeResponse(
                attachment_id=req.attachment_id,
                detected_kind=kind,
                mime=mime,
                size_bytes=path.stat().st_size,
                extension_mismatch=mismatch,
                error=f"Tipo de archivo no soportado: {kind}",
            ).model_dump()
        )

    file_b64 = _encode_binary(path, mime)
    text_context = redactor.redact(_pre_extract_text(path, kind))
    allowed = _allowed_types(req.doc_type_rules)

    try:
        raw_classification, _ = await tasks.classify_document(
            file_b64, mime, text_context, allowed,
            custom_prompt=_prompt_for(req.prompts, "classify"),
        )
        classification = tasks.normalize_classification(raw_classification)
        doc_type = str(classification["doc_type"])

        fields: dict[str, object] = {}
        if doc_type != "desconocido":
            raw_fields, _ = await tasks.extract_fields(
                doc_type, file_b64, mime, text_context,
                custom_prompt=_prompt_for(req.prompts, f"extract_{doc_type}_v1"),
            )
            fields = tasks.normalize_fields(raw_fields)

    except LlmError as exc:
        # 503 / caída de OpenRouter. Laravel detecta el 500 y cae a Stack A (CU-05).
        log.error("Fallo de OpenRouter: %s", exc)
        return JSONResponse(
            AnalyzeResponse(
                attachment_id=req.attachment_id,
                detected_kind=kind,
                mime=mime,
                size_bytes=path.stat().st_size,
                extension_mismatch=mismatch,
                error=f"DEGRADED: {exc}",
                elapsed_ms=int((time.perf_counter() - started) * 1000),
            ).model_dump(),
            status_code=503,
        )

    elapsed_ms = int((time.perf_counter() - started) * 1000)
    response = AnalyzeResponse(
        attachment_id=req.attachment_id,
        detected_kind=kind,
        mime=mime,
        size_bytes=path.stat().st_size,
        extension_mismatch=mismatch,
        text=text_context,
        ocr_used=False,  # Stack B no pre-extrae: el LLM lee el PDF/imagen directo
        doc_type=doc_type,
        confidence=float(classification["confidence"]),
        reasoning=str(classification["reasoning"]),
        key_signals=list(classification["key_signals"]),  # type: ignore[arg-type]
        score_breakdown={"llm_confidence": classification["confidence"]},
        fields=fields,
        elapsed_ms=elapsed_ms,
    )

    return JSONResponse({
        **response.model_dump(),
        "_llm_traces": [t.model_dump() for t in client.traces],
    })


@app.post("/validate", response_model=ValidateResponse, dependencies=[Depends(verify_token)])
async def validate(req: ValidateRequest) -> JSONResponse:
    """Fase 2 semántica: los criterios vienen de `validation_prompts` (RF-08)."""
    started = time.perf_counter()
    reset_traces()

    criteria = _prompt_for(req.prompts, f"validate_{req.doc_type}_v1") or _prompt_for(
        req.prompts, "validate_default_v1"
    )
    if not criteria:
        return JSONResponse(
            ValidateResponse(error="No hay prompt de validación activo").model_dump(),
            status_code=422,
        )

    facts = dict(req.facts)
    facts["today"] = req.today

    try:
        raw, _ = await tasks.validate_document(
            criteria, None, None, "", facts
        )
    except LlmError as exc:
        log.error("Validación LLM falló: %s", exc)
        return JSONResponse(
            ValidateResponse(
                error=f"DEGRADED: {exc}",
                results=[
                    ValidationResultItem(
                        rule_name=f"validate_{req.doc_type}_v1",
                        status="error",
                        severity="warning",
                        message=f"No se pudo ejecutar la validación: {exc}",
                        code="LLM_UNAVAILABLE",
                    )
                ],
            ).model_dump(),
            status_code=503,
        )

    normalized = tasks.normalize_validation(raw)

    results = [
        ValidationResultItem(
            rule_name=f"validate_{req.doc_type}_v1",
            status="warn" if i["severity"] == "warning" else "fail",
            severity=str(i["severity"]),  # type: ignore[arg-type]
            message=str(i["message"]),
            code=str(i["code"]),
        )
        for i in normalized["issues"]  # type: ignore[index]
    ]
    if not results:
        results = [
            ValidationResultItem(
                rule_name=f"validate_{req.doc_type}_v1",
                status="pass",
                message="El LLM no encontró problemas.",
            )
        ]

    return JSONResponse({
        "results": [r.model_dump() for r in results],
        "approved": bool(normalized["approved"]),
        "issues": normalized["issues"],
        "elapsed_ms": int((time.perf_counter() - started) * 1000),
        "_llm_traces": [t.model_dump() for t in client.traces],
    })


@app.post("/generate-reply", response_model=GenerateReplyResponse,
          dependencies=[Depends(verify_token)])
async def generate_reply(req: GenerateReplyRequest) -> JSONResponse:
    """Genera el texto natural de la respuesta cuando hay issues o no clasificó."""
    started = time.perf_counter()
    reset_traces()

    try:
        body, _ = await tasks.generate_reply(req)
    except LlmError as exc:
        log.error("Generación de respuesta falló: %s", exc)
        return JSONResponse(
            GenerateReplyResponse(body="", source="llm", error=str(exc)).model_dump(),
            status_code=503,
        )

    return JSONResponse({
        "body": body,
        "source": "llm",
        "elapsed_ms": int((time.perf_counter() - started) * 1000),
        "_llm_traces": [t.model_dump() for t in client.traces],
    })


@app.post("/test-prompt", dependencies=[Depends(verify_token)])
async def test_prompt(payload: dict[str, Any]) -> JSONResponse:
    """Ejecuta un prompt suelto desde Filament, sin pasar por el pipeline.

    Es el botón "Probar prompt" del admin: escribe el prompt, lo manda y ve el
    JSON crudo que volvió, sin crear ningún job.
    """
    reset_traces()
    prompt = str(payload.get("prompt_text") or "")
    if not prompt.strip():
        raise HTTPException(status_code=422, detail="prompt_text vacío")

    try:
        parsed, _ = await client.complete_json(
            purpose="test_prompt", user_prompt=prompt
        )
    except LlmError as exc:
        return JSONResponse(
            {"error": str(exc), "traces": [t.model_dump() for t in client.traces]},
            status_code=502,
        )

    return JSONResponse({
        "result": parsed,
        "traces": [t.model_dump() for t in client.traces],
    })


@app.exception_handler(Exception)
def unhandled(request, exc: Exception) -> JSONResponse:  # pragma: no cover
    log.exception("Error no controlado en %s", request.url.path)
    return JSONResponse(status_code=500, content={"detail": str(exc)})