"""Clasificación documental por keywords + filename hints (RF-05).

Score = 0.5 (required keywords) + 0.3 (bonus keywords) + 0.2 (filename hints).
Por debajo de `classify_min_score` (0.4) el documento va a `"desconocido"`,
que es la cola de revisión manual.
"""

from __future__ import annotations

from pathlib import Path

from app.config import settings
from app.models import DocTypeRule

UNKNOWN = "desconocido"

_WEIGHTS = {"required": 0.5, "bonus": 0.3, "filename": 0.2}


def _normalize(text: str) -> str:
    """Minúsculas y sin acentos, para que 'Póliza' matchee 'poliza'."""
    import unicodedata

    decomposed = unicodedata.normalize("NFKD", text.lower())
    return "".join(ch for ch in decomposed if not unicodedata.combining(ch))


def score_rule(rule: DocTypeRule, text: str, filename: str) -> dict[str, float]:
    """Puntúa una regla contra el texto y el nombre del archivo."""
    haystack = _normalize(text)
    fname = _normalize(filename)

    hits_required = [k for k in rule.required_keywords if _normalize(k) in haystack]
    hits_bonus = [k for k in rule.keywords if _normalize(k) in haystack]
    hits_filename = [h for h in rule.filename_hints if _normalize(h) in fname]

    # Cada grupo aporta su peso completo si tiene al menos un match, y decae
    # proporcionalmente al número de matches: un tipo con 4 keywords requeridas
    # no debería exigir las 4 para puntuar.
    required_score = _WEIGHTS["required"] * min(len(hits_required), 1)
    bonus_score = _WEIGHTS["bonus"] * (len(hits_bonus) / max(len(rule.keywords), 1) * 2)
    bonus_score = min(bonus_score, _WEIGHTS["bonus"])
    filename_score = _WEIGHTS["filename"] * min(len(hits_filename), 1)

    return {
        "required_score": round(required_score, 4),
        "bonus_score": round(bonus_score, 4),
        "filename_score": round(filename_score, 4),
        "total": round(required_score + bonus_score + filename_score, 4),
        "hits_required": len(hits_required),
        "hits_bonus": len(hits_bonus),
        "hits_filename": len(hits_filename),
        "keywords_found": hits_required + hits_bonus + hits_filename,
    }


def classify(
    text: str, filename: str, rules: list[DocTypeRule]
) -> tuple[str, float, dict[str, dict[str, float]]]:
    """Clasifica el documento.

    Returns:
        `(doc_type, confidence, breakdown)` donde `breakdown` lleva el score de
        cada regla evaluada — es lo que se muestra en el admin para entender por
        qué un documento salió como salió.
    """
    if not rules:
        return UNKNOWN, 0.0, {}

    breakdown: dict[str, dict[str, float]] = {}
    best_name = UNKNOWN
    best_score = 0.0

    for rule in rules:
        scores = score_rule(rule, text, filename)
        breakdown[rule.name] = scores
        if scores["total"] > best_score:
            best_score = scores["total"]
            best_name = rule.name

    if best_score < settings.classify_min_score:
        return UNKNOWN, round(best_score, 4), breakdown

    return best_name, round(best_score, 4), breakdown