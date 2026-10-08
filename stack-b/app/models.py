"""Modelos del contrato HTTP. Deben calzar exactamente con los de Stack A:
es lo que hace posible el fallback de B a A.

Duplicados a propósito en vez de compartir un paquete: los dos servicios son
independientes por diseño (uno debe seguir funcionando si el otro no arranca).
"""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field

DocTypeRuleName = str


class AnalyzeRequest(BaseModel):
    attachment_id: str = ""
    path: str
    filename: str
    # En Stack B las keywords no se usan para clasificar, pero llegan igual por
    # compatibilidad de contrato: sirven como `hint` para el prompt.
    doc_type_rules: list[dict[str, Any]] = Field(default_factory=list)
    field_patterns: dict[str, str] = Field(default_factory=dict)
    # Prompts versionados de la tabla `validation_prompts` (RF-08).
    prompts: dict[str, dict[str, Any]] = Field(default_factory=dict)
    cache_hits: list[dict[str, Any]] = Field(default_factory=list)


class AnalyzeResponse(BaseModel):
    attachment_id: str = ""
    detected_kind: str = "unknown"
    mime: str | None = None
    size_bytes: int = 0
    extension_mismatch: bool = False
    text: str = ""
    text_path: str | None = None
    ocr_used: bool = False
    ocr_engine: str | None = None
    ocr_confidence: float | None = None
    doc_type: str = "desconocido"
    confidence: float = 0.0
    reasoning: str = ""
    key_signals: list[str] = Field(default_factory=list)
    score_breakdown: dict[str, Any] = Field(default_factory=dict)
    fields: dict[str, Any] = Field(default_factory=dict)
    elapsed_ms: int = 0
    error: str | None = None


class ValidateRequest(BaseModel):
    email_id: str = ""
    attachment_id: str = ""
    doc_type: str = "desconocido"
    facts: dict[str, Any] = Field(default_factory=dict)
    prompts: dict[str, dict[str, Any]] = Field(default_factory=list)
    today: str = ""


class ValidationResultItem(BaseModel):
    rule_name: str
    status: Literal["pass", "fail", "warn", "error"]
    # Nullable a propósito: una regla que PASA no tiene severidad. Si el
    # default fuera "error", el filtro "¿tiene errores?" del admin marcaría
    # como erróneos los jobs que no encontraron ni un problema.
    severity: Literal["error", "warning"] | None = None
    message: str = ""
    code: str = ""


class ValidateResponse(BaseModel):
    results: list[ValidationResultItem] = Field(default_factory=list)
    approved: bool = False
    # Issues reportados por el propio LLM, ya normalizados.
    issues: list[dict[str, Any]] = Field(default_factory=list)
    error: str | None = None


class GenerateReplyRequest(BaseModel):
    status: Literal["validated", "failed", "review", "pending"]
    sender_name: str | None = None
    issues: list[dict[str, Any]] = Field(default_factory=list)
    missing_documents: list[str] = Field(default_factory=list)
    unclassified: list[str] = Field(default_factory=list)
    prompt_template: str | None = None


class GenerateReplyResponse(BaseModel):
    body: str
    source: Literal["llm", "template"] = "llm"
    error: str | None = None


# ── Traza de auditoría (RNF-04) ──────────────────────────────────────────────


class LlmTrace(BaseModel):
    """Una llamada al LLM, para que Laravel la persista en `llm_calls`."""

    purpose: str
    model: str = ""
    prompt_tokens: int = 0
    completion_tokens: int = 0
    cost_usd: float = 0.0
    latency_ms: int = 0
    raw_request: str = ""
    raw_response: str = ""
    parsed_response: str = ""
    error: str | None = None
    cache_hit: bool = False