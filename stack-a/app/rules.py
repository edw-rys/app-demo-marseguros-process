"""Motor de reglas declarativas de Fase 2 (RF-07).

Las reglas llegan como JSON desde la tabla `validation_rules` de Laravel y se
ejecutan con `python-rule-engine`, que es el port Python de
`node-json-rules-engine` y usa exactamente la misma sintaxis JSON.

Los *custom facts* (`days_since_date`, `rfc_valid`, `monto_in_range`, …) se
calculan aquí y se inyectan en el contexto antes de evaluar, de modo que el JSON
de una regla puede referenciarlos por nombre igual que cualquier otro fact.
"""

from __future__ import annotations

import logging
import re
from datetime import date, datetime
from typing import Any

from app.fields import days_since, parse_date, rfc_valid, ruc_valid
from app.models import ValidationResultItem, ValidationRule

log = logging.getLogger(__name__)


#: Facts de fecha cuyo valor se convierte a `days_since_<campo>`.
_DATE_FACTS = (
    "fecha_emision",
    "fecha_inicio_vigencia",
    "fecha_fin_vigencia",
    "issued_at",
    "expiry",
)

#: Fact de fecha "de referencia": `days_since_date` apunta al primero que exista.
_REFERENCE_DATE = (
    "fecha_emision",
    "issued_at",
    "fecha_inicio_vigencia",
    "expiry",
    "fecha_fin_vigencia",
)

#: Identificadores fiscales y su validador. La clave es el sufijo que se
#: busca en los facts (`ruc_emisor` -> `ruc_emisor_valid`).
_FISCAL_SUFFIXES = (("ruc", ruc_valid), ("rfc", rfc_valid))


def build_custom_facts(facts: dict[str, Any], today: date | None = None) -> dict[str, Any]:
    """Añade al contexto los facts derivados que las reglas pueden referenciar."""
    today = today or date.today()
    context = dict(facts)

    for raw_key in _DATE_FACTS:
        if not facts.get(raw_key):
            continue
        parsed = parse_date(str(facts[raw_key]))
        if parsed:
            context[f"days_since_{raw_key}"] = days_since(parsed, today)

    # Alias genérico: `days_since_date` apunta a la fecha de referencia del doc.
    for candidate in _REFERENCE_DATE:
        if not facts.get(candidate):
            continue
        parsed = parse_date(str(facts[candidate]))
        if parsed:
            context.setdefault("days_since_date", days_since(parsed, today))
            break

    # `ruc_emisor` -> `ruc_emisor_valid`, `rfc_solicitante` -> `rfc_solicitante_valid`.
    for key, value in facts.items():
        for suffix, validator in _FISCAL_SUFFIXES:
            if key == suffix or key.endswith(f"_{suffix}"):
                if value:
                    context[f"{key}_valid"] = validator(str(value))
                break

    monto = _to_number(facts.get("monto_total"))
    minimo = _to_number(facts.get("minimo_cobertura"))
    maximo = _to_number(facts.get("maximo_cobertura"))

    if monto is not None and minimo is not None:
        context["monto_in_range"] = minimo <= monto and (maximo is None or monto <= maximo)

    return context


def _to_number(value: Any) -> float | None:
    """Convierte `"1.234,56"` o `"1,234.56"` a float. `None` si no se puede."""
    if isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        return float(value)
    if not isinstance(value, str):
        return None

    cleaned = re.sub(r"[^\d.,]", "", value).strip()
    if not cleaned:
        return None

    # El separador decimal es el último que aparece: `1.234,56` es 1234.56.
    if "," in cleaned and "." in cleaned:
        cleaned = cleaned.replace(".", "").replace(",", ".") if cleaned.rfind(",") > cleaned.rfind(".") \
            else cleaned.replace(",", "")
    elif "," in cleaned:
        cleaned = cleaned.replace(".", "").replace(",", ".") if cleaned.count(",") == 1 \
            and len(cleaned.split(",")[1]) != 3 else cleaned.replace(",", "")
    else:
        cleaned = cleaned.replace(",", "")

    try:
        return float(cleaned)
    except ValueError:
        return None


def _message_for(rule_json: dict[str, Any], fallback: str) -> str:
    """Extrae el mensaje legible del `event.params` de la regla."""
    event = rule_json.get("event") or {}
    params = event.get("params") or {}
    return str(params.get("message") or fallback)


def _code_for(rule_json: dict[str, Any], fallback: str) -> str:
    event = rule_json.get("event") or {}
    params = event.get("params") or {}
    return str(params.get("code") or fallback)


def _matches(rule_json: dict[str, Any], facts: dict[str, Any]) -> bool:
    """Evalúa una regla. Devuelve `True` si la regla se dispara (fail).

    La semántica es "la regla se cumple ⇒ hay un problema": una regla que
    dispara produce un `fail`. Es la convención de node-json-rules-engine para
    reglas de validación.
    """
    from rule import Rule  # python-rule-engine

    rule = Rule.from_dict(rule_json)
    return bool(rule.evaluate(facts))


def evaluate_rules(
    rules: list[ValidationRule],
    facts: dict[str, Any],
    doc_type: str,
    today: date | None = None,
) -> tuple[list[ValidationResultItem], bool]:
    """Ejecuta todas las reglas aplicables a `doc_type`.

    Una regla sin `doc_types` aplica a todos los documentos.

    Returns:
        `(resultados, approved)`. `approved` es `False` si alguna regla con
        severidad `error` se dispara.
    """
    context = build_custom_facts(facts, today)
    results: list[ValidationResultItem] = []
    approved = True

    for rule in rules:
        if rule.doc_types and doc_type not in rule.doc_types:
            continue

        rule_json = dict(rule.json_rule)
        rule_json.setdefault("name", rule.name)

        try:
            fired = _matches(rule_json, context)
        except Exception as exc:
            # Una regla rota no debe tumbar el job: se registra como error y se
            # sigue con las demás (queda trazabilidad en `status='error'`).
            log.exception("Regla '%s' no evaluable: %s", rule.name, exc)
            results.append(
                ValidationResultItem(
                    rule_name=rule.name,
                    status="error",
                    severity="warning",
                    message=f"La regla no se pudo evaluar: {exc}",
                    code="RULE_ERROR",
                )
            )
            continue

        if not fired:
            results.append(
                ValidationResultItem(
                    rule_name=rule.name,
                    status="pass",
                    severity=rule.severity,
                    message="",
                    code=_code_for(rule_json, rule.name),
                )
            )
            continue

        message = _message_for(rule_json, f"La regla '{rule.name}' se cumplió.")
        results.append(
            ValidationResultItem(
                rule_name=rule.name,
                status="fail",
                severity=rule.severity,
                message=message,
                code=_code_for(rule_json, rule.name),
            )
        )

        # Un warning no bloquea; un error sí.
        if rule.severity == "error":
            approved = False

    return results, approved