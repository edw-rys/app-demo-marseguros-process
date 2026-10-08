"""Anonimización de PII antes de enviar texto al LLM (RNF-03).

El requerimiento es explícito: al prompt del clasificador genérico van los RUC y
RFC (que son datos del documento que el modelo necesita para decidir), pero NO
los nombres ni las emails del remitente, que son PII del cliente y no aportan
nada a la clasificación.
"""

from __future__ import annotations

import re

_EMAIL_RE = re.compile(
    r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}"
)
# Teléfonos: desde los 7 dígitos de una línea fija ecuatoriana hasta
# internacionales. El mínimo es 7 + 1 para no pisar números cortos sueltos.
_PHONE_RE = re.compile(r"(?<![\d.])\+?\d[\d\s().-]{5,}\d(?![\d.])")
# Cédula ecuatoriana: 10 dígitos. Un móvil ecuatoriano también tiene 10 dígitos
# y empieza por 09, así que se separan los dos casos: los dos se enmascaran
# (ambos son PII), pero la etiqueta refleja qué se ocultó.
_ECU_ID_RE = re.compile(r"(?<!\d)(?!09\d{8})\d{10}(?!\d)")
_ECU_MOBILE_RE = re.compile(r"(?<!\d)09\d{8}(?!\d)")
# RUC (13) y RFC mexicano (13 alfanuméricos): se conservan, el modelo los necesita.
_RUC_RE = re.compile(r"\b\d{13}\b")

_PLACEHOLDER_EMAIL = "[EMAIL_REDACTADO]"
_PLACEHOLDER_PHONE = "[TELEFONO_REDACTADO]"
_PLACEHOLDER_NAME = "[NOMBRE_REDACTADO]"
_PLACEHOLDER_RUC = "[RUC_PROTECTED]"

# Los RUC se protegen antes de aplicar el regex de teléfono: un RUC de 13
# dígitos cumple el patrón de teléfono y sería enmascarado, cuando justamente
# el modelo lo necesita para validar el documento (RNF-03).
_RUC_GUARD = "{}\u0000"


def _protect_rucs(text: str) -> tuple[str, list[str]]:
    found: list[str] = []

    def _stash(match: re.Match[str]) -> str:
        found.append(match.group(0))
        return _RUC_GUARD.format(len(found) - 1)

    return _RUC_RE.sub(_stash, text), found


def redact(text: str, *, sender_name: str | None = None) -> str:
    """Enmascara PII del cliente conservando identificadores fiscales.

    El `sender_name` se enmascara de forma literal, porque puede aparecer con
    acentos o mayúsculas que un regex genérico no atraparía.
    """
    if not text:
        return ""

    if sender_name and len(sender_name.strip()) > 2:
        text = re.sub(
            re.escape(sender_name.strip()), _PLACEHOLDER_NAME, text, flags=re.IGNORECASE
        )

    text = _EMAIL_RE.sub(_PLACEHOLDER_EMAIL, text)

    # Cédulas y móviles de 10 dígitos primero: el patrón de teléfono largo
    # también los captura, y `(?<!\d)\d{10}(?!\d)` no pisa un RUC de 13.
    text = _ECU_ID_RE.sub("[CEDULA_REDACTADA]", text)
    text = _ECU_MOBILE_RE.sub("[MOVIL_REDACTADO]", text)

    text, rucs = _protect_rucs(text)
    text = _PHONE_RE.sub(_PLACEHOLDER_PHONE, text)

    for index, ruc in enumerate(rucs):
        text = text.replace(_RUC_GUARD.format(index), ruc)

    return text


def describe_redaction(original: str, redacted: str) -> dict[str, int]:
    """Cuántos datos se ocultaron. Va al `detail` de la etapa en el admin."""
    return {
        "emails": len(_EMAIL_RE.findall(original)),
        "emails_after": len(_EMAIL_RE.findall(redacted)),
        "rucs_conservados": len(_RUC_RE.findall(redacted)),
    }