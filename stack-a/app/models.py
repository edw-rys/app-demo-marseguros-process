"""Modelos Pydantic del contrato HTTP compartido por ambos stacks.

El contrato es idéntico en Stack A y Stack B a propósito: eso es lo que permite
que Laravel caiga de B a A (fallback) sin cambiar de cliente.
"""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field

# ── /analyze ────────────────────────────────────────────────────────────────


class DocTypeRule(BaseModel):
    """Regla de clasificación documental (tabla `doc_type_rules` de Laravel)."""

    name: str
    label: str = ""
    required_keywords: list[str] = Field(default_factory=list)
    keywords: list[str] = Field(default_factory=list)
    filename_hints: list[str] = Field(default_factory=list)


class AnalyzeRequest(BaseModel):
    attachment_id: str = ""
    path: str
    filename: str
    doc_type_rules: list[DocTypeRule] = Field(default_factory=list)
    field_patterns: dict[str, str] = Field(default_factory=dict)


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
    score_breakdown: dict[str, Any] = Field(default_factory=dict)
    fields: dict[str, Any] = Field(default_factory=dict)
    elapsed_ms: int = 0
    error: str | None = None


# ── /validate ───────────────────────────────────────────────────────────────


class ValidationRule(BaseModel):
    """Regla declarativa de Fase 2 (tabla `validation_rules` de Laravel).

    El JSON es el de node-json-rules-engine, ejecutado por `python-rule-engine`.
    """

    name: str
    json_rule: dict[str, Any]
    severity: Literal["error", "warning"] = "error"
    doc_types: list[str] = Field(default_factory=list)


class ValidateRequest(BaseModel):
    email_id: str = ""
    attachment_id: str = ""
    doc_type: str = "desconocido"
    facts: dict[str, Any] = Field(default_factory=dict)
    rules: list[ValidationRule] = Field(default_factory=list)
    today: str = ""


class ValidationResultItem(BaseModel):
    rule_name: str
    status: Literal["pass", "fail", "warn", "error"]
    severity: Literal["error", "warning"] = "error"
    message: str = ""
    code: str = ""


class ValidateResponse(BaseModel):
    results: list[ValidationResultItem] = Field(default_factory=list)
    approved: bool = False
    error: str | None = None


# ── /generate-reply ─────────────────────────────────────────────────────────


class GenerateReplyRequest(BaseModel):
    status: Literal["validated", "failed", "review", "pending"]
    sender_name: str | None = None
    issues: list[dict[str, Any]] = Field(default_factory=list)
    missing_documents: list[str] = Field(default_factory=list)
    unclassified: list[str] = Field(default_factory=list)
    prompt_template: str | None = None


class GenerateReplyResponse(BaseModel):
    body: str
    source: Literal["llm", "template"] = "template"
    error: str | None = None