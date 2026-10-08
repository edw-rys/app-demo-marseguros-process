"""Tareas del LLM: clasificar, extraer, validar, redactar respuesta.

Cada función devuelve el JSON ya parseado y deja su traza de auditoría en
`client.traces` para que `main.py` la devuelva a Laravel y se persista en
`llm_calls` (RNF-04).
"""

from __future__ import annotations

import json
import logging

from app.config import settings
from app.llm import prompts
from app.models import GenerateReplyRequest, LlmTrace

log = logging.getLogger(__name__)

UNKNOWN = "desconocido"


def _as_float(value: object, default: float = 0.0) -> float:
    try:
        return float(value)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return default


def _as_str_list(value: object) -> list[str]:
    if not isinstance(value, list):
        return []
    return [str(v) for v in value]


# ── RF-05 ────────────────────────────────────────────────────────────────────


async def classify_document(
    file_b64: str | None,
    mime: str | None,
    text_context: str,
    allowed_types: list[str],
    custom_prompt: str | None = None,
) -> tuple[dict[str, object], list[LlmTrace]]:
    """Clasifica el documento y devuelve `(resultado, trazas)`."""
    from app.openrouter_client import client

    types = ", ".join(allowed_types) if allowed_types else UNKNOWN
    template = custom_prompt or prompts.CLASSIFY

    instruction = template.format(types=types) if "{types}" in template else template

    body = instruction
    if text_context:
        # Para CSV/TXT el contenido va como texto, no binario (RF-04).
        body = f"{body}\n\nContenido del documento:\n\"\"\"\n{text_context}\n\"\"\""

    result, trace = await client.complete_json(
        purpose="classify",
        user_prompt=body,
        file_b64=file_b64,
        mime=mime,
    )
    return result, [trace]


def normalize_classification(raw: dict[str, object]) -> dict[str, object]:
    """Normaliza la salida del LLM al shape que espera el resto del pipeline.

    Confidence < `classify_min_confidence` fuerza `"desconocido"` (RF-05).
    """
    doc_type = str(raw.get("doc_type") or UNKNOWN).strip().lower()
    confidence = _as_float(raw.get("confidence"))
    signals = _as_str_list(raw.get("key_signals"))
    reasoning = str(raw.get("reasoning") or "")

    if confidence < settings.classify_min_confidence:
        doc_type = UNKNOWN

    return {
        "doc_type": doc_type,
        "confidence": round(confidence, 4),
        "reasoning": reasoning,
        "key_signals": signals,
    }


# ── RF-06 ────────────────────────────────────────────────────────────────────


def _schema_block(doc_type: str) -> str:
    schema = prompts.FIELD_SCHEMAS.get(doc_type)
    if not schema:
        schema = {"documento": {"type": "string", "description": "contenido relevante"}}
    body = ",\n".join(
        f'  "{name}": {json.dumps(spec, ensure_ascii=False)}'
        for name, spec in schema.items()
    )
    # La plantilla usa `{{` / `}}` para las llaves literales del JSON exterior.
    return "{\n" + body + "\n}"


async def extract_fields(
    doc_type: str,
    file_b64: str | None,
    mime: str | None,
    text_context: str,
    custom_prompt: str | None = None,
) -> tuple[dict[str, object], list[LlmTrace]]:
    """Extrae campos estructurados según el schema del tipo detectado."""
    from app.openrouter_client import client

    schema_block = _schema_block(doc_type)
    template = custom_prompt or prompts.EXTRACT
    instruction = template.format(schema=schema_block, field="<campo>")

    body = instruction
    if text_context:
        body = f"{body}\n\nContenido del documento:\n\"\"\"\n{text_context}\n\"\"\""

    result, trace = await client.complete_json(
        purpose=f"extract_{doc_type}",
        user_prompt=body,
        file_b64=file_b64,
        mime=mime,
    )
    return result, [trace]


def normalize_fields(raw: dict[str, object]) -> dict[str, object]:
    """Quita las claves `*_reason` que el prompt usa para marcar ausencias."""
    fields: dict[str, object] = {}
    for key, value in raw.items():
        if key.endswith("_reason"):
            continue
        fields[key] = value
    return fields


# ── RF-08 ────────────────────────────────────────────────────────────────────


async def validate_document(
    criteria: str,
    file_b64: str | None,
    mime: str | None,
    text_context: str,
    facts: dict[str, object],
) -> tuple[dict[str, object], list[LlmTrace]]:
    """Ejecuta la Fase 2 vía prompt estructurado.

    Criterio de aceptación de RF-08: si el JSON no parsea, se reintenta con un
    prompt de corrección hasta `LLM_MAX_RETRIES` veces. Ese reintento lo hace
    `complete_json`, que ya reintenta ante JSON inválido.
    """
    from app.openrouter_client import client

    instruction = prompts.VALIDATE.format(criteria=criteria)

    # Se le da al modelo lo ya extraído como contexto para que no tenga que
    # releerlo, pero el documento sigue adjuntado: la validación es semántica.
    facts_block = json.dumps(facts, ensure_ascii=False, indent=2)
    body = (
        f"{instruction}\n\n"
        f"Datos ya extraídos del documento (pueden servirte de apoyo):\n"
        f"{facts_block}\n"
        f"Fecha de referencia (hoy): {facts.get('today', '')}"
    )
    if text_context:
        body += f"\n\nContenido:\n\"\"\"\n{text_context}\n\"\"\""

    result, trace = await client.complete_json(
        purpose="validate",
        user_prompt=body,
        file_b64=file_b64,
        mime=mime,
    )
    return result, [trace]


def normalize_validation(raw: dict[str, object]) -> dict[str, object]:
    """Convierte la salida del validador al shape de `ValidateResponse`."""
    issues_raw = raw.get("issues")
    issues: list[dict[str, object]] = []
    if isinstance(issues_raw, list):
        for item in issues_raw:
            if not isinstance(item, dict):
                continue
            issues.append(
                {
                    "code": str(item.get("code") or "SIN_CODIGO"),
                    "severity": (
                        "warning" if str(item.get("severity", "")).lower() == "warning"
                        else "error"
                    ),
                    "message": str(item.get("message") or ""),
                }
            )

    approved = bool(raw.get("approved"))
    # El LLM puede marcarse `approved: true` con issues de severidad error:
    # en ese caso manda la severidad, no el booleano.
    if any(i["severity"] == "error" for i in issues):
        approved = False

    return {"approved": approved, "issues": issues}


# ── RF-09 ────────────────────────────────────────────────────────────────────


async def generate_reply(
    req: GenerateReplyRequest,
) -> tuple[str, list[LlmTrace]]:
    """Genera el texto natural de la respuesta con errores o sin clasificar.

    Los casos de "faltan archivos" y "validación OK" NO pasan por aquí: esos usan
    templates fijos en Laravel porque no necesitan LLM (tabla de RF-09).
    """
    from app.openrouter_client import client

    if req.status == "review" and req.unclassified:
        prompt = prompts.GENERATE_CLARIFY_REPLY.format(
            files=", ".join(req.unclassified[:10])
        )
        purpose = "generate_clarify_reply"
    else:
        lines = "\n".join(
            f"- {i.get('message') or i.get('code', 'sin detalle')}"
            for i in req.issues[:10]
        )
        prompt = (req.prompt_template or prompts.GENERATE_ERROR_REPLY).format(
            issue_count=len(req.issues), issues=lines
        )
        purpose = "generate_error_reply"

    # El correo de respuesta no necesita JSON mode: se pide texto plano y se
    # envuelve en un objeto para poder reutilizar el cliente con reintentos.
    result, trace = await client.complete_json(
        purpose=purpose,
        user_prompt=prompt,
        system_prompt=(
            "Redacta el correo en español. Tu respuesta debe ser un objeto JSON "
            'con una única clave "body" que contenga el texto del correo.'
        ),
    )
    body = str(result.get("body") or "").strip()
    return _cap(body, settings.reply_max_chars), [trace]


def _cap(text: str, limit: int) -> str:
    """Filtro de longitud obligatorio de RF-09."""
    if len(text) <= limit:
        return text
    cut = text[:limit]
    # Cortar a lo bruto suele partir una frase a la mitad; se recorta al último
    # punto antes del límite para que la respuesta cierre bien.
    for sep in (". ", ".\n"):
        idx = cut.rfind(sep)
        if idx > limit * 0.5:
            return cut[: idx + 1].strip()
    return cut.strip()