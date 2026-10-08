"""Extracción de campos por regex (RF-06).

Los patrones llegan desde la tabla `field_patterns` de Laravel en cada request,
así que editarlos en Filament surte efecto sin redeploy.

Cuando un campo tiene más de un match se devuelve una lista; si no hay ninguno,
`None` — nunca un error (criterio explícito de RF-06).
"""

from __future__ import annotations

import re
from datetime import date, datetime

# Checksums de identificación. En Ecuador el RUC es de 13 dígitos y el código de
# verificación es módulo 11; el RFC mexicano es de 13 con pesos 13-3-1.
_RUC_WEIGHTS = (2, 1, 2, 1, 2, 1, 2, 1, 2, 3, 4, 5)
_RUC_CHECK = (0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 5)


def ruc_valid(ruc: str | None) -> bool:
    """Valida un RUC ecuatoriano de 13 dígitos (checksum módulo 11)."""
    if not ruc or len(ruc) != 13 or not ruc.isdigit():
        return False
    # El dígito 3 distingue persona natural (0) de entidad jurídica (9).
    if ruc[2] not in {"0", "9"}:
        return False
    total = sum(int(d) * w for d, w in zip(ruc[:12], _RUC_WEIGHTS))
    check = _RUC_CHECK[total % 11]
    return check == 10 or check == int(ruc[12])


def rfc_valid(rfc: str | None) -> bool:
    """Valida un RFC mexicano de 13 caracteres (formato + checksum)."""
    if not rfc or len(rfc) != 13:
        return False
    if not re.fullmatch(r"[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}", rfc.upper()):
        return False
    body = rfc.upper()[4:]
    values = [ord(c) - 65 for c in body]
    total = sum(v * w for v, w in zip(values, (2, 1, 2, 1, 2, 1, 2, 1, 2, 1, 2, 1)))
    mod = total % 11
    digit = 0 if mod == 0 else (mod if mod < 10 else 11)
    expected = "0" if digit == 0 else ("A" if digit == 11 else str(digit))
    return expected == rfc.upper()[12]


def _coerce(value: str) -> object:
    """Convierte el texto capturado a número cuando parece un número.

    En Ecuador y México los importes se escriben `1.234,56` (punto de miles,
    coma decimal). Python no entiende eso, así que se normaliza antes de
    convertir: sin esto, `"1.234,56"` daría `1.234`.
    """
    cleaned = value.strip()

    if re.fullmatch(r"[^\d.,]*\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?[^\d.,]*", cleaned):
        # Formato con separadores: el último separador es el decimal.
        cleaned = re.sub(r"[^\d.,]", "", cleaned)
        if "," in cleaned and "." in cleaned:
            cleaned = (
                cleaned.replace(".", "").replace(",", ".")
                if cleaned.rfind(",") > cleaned.rfind(".")
                else cleaned.replace(",", "")
            )
        elif "," in cleaned:
            # Una sola coma: decimal si no cierra un grupo de miles.
            cleaned = (
                cleaned.replace(".", "").replace(",", ".")
                if len(cleaned.split(",")[-1]) != 3
                else cleaned.replace(",", "")
            )
        else:
            cleaned = cleaned.replace(",", "")
    else:
        cleaned = re.sub(r"[^\d.,-]", "", cleaned).replace(",", "")

    try:
        number = float(cleaned)
    except ValueError:
        return value.strip()

    return int(number) if number.is_integer() else number


def parse_date(value: str | None) -> date | None:
    """Best-effort de parseo de fechas en los formatos que sí emite la gente."""
    if not value:
        return None
    cleaned = value.strip().rstrip(".")
    for fmt in ("%Y-%m-%d", "%d/%m/%Y", "%d-%m-%Y", "%d.%m.%Y", "%Y/%m/%d"):
        try:
            return datetime.strptime(cleaned, fmt).date()
        except ValueError:
            continue
    return None


def extract_fields(text: str, patterns: dict[str, str]) -> dict[str, object]:
    """Aplica cada regex del dict sobre el texto.

    Un patrón que no compila se salta con warning en lugar de tumbar el job:
    el regex lo escribe un humano en Filament y no siempre es válido.
    """
    fields: dict[str, object] = {}

    for name, pattern in patterns.items():
        if not pattern:
            continue
        try:
            compiled = re.compile(pattern, re.IGNORECASE | re.MULTILINE)
        except re.error:
            import logging

            logging.getLogger(__name__).warning("Regex inválido para '%s': %s", name, pattern)
            fields[name] = None
            continue

        matches = [m.group(1) or m.group(0) for m in compiled.finditer(text)]
        matches = [m for m in matches if m and m.strip()]

        if not matches:
            fields[name] = None
            continue

        # Varias coincidencias → lista; una sola → escalar (más cómodo de leer).
        values = [_coerce(m) for m in matches]
        fields[name] = values[0] if len(values) == 1 else values

    # Metadatos derivados que RF-06 pide validar explícitamente.
    # El sufijo importa: `ruc_emisor` produce `ruc_emisor_valid`, igual que
    # `ruc` produciría `ruc_valid`.
    for suffix, validator in (("ruc", ruc_valid), ("rfc", rfc_valid)):
        for key in list(fields):
            if key != suffix and not key.endswith(f"_{suffix}"):
                continue

            raw = fields[key]
            value = raw[0] if isinstance(raw, list) and raw else raw
            if isinstance(value, str):
                fields[f"{key}_valid"] = validator(value)

    return fields


def days_since(value: object, today: date | None = None) -> int | None:
    """Días transcurridos desde `value` hasta hoy. Custom fact para las reglas.

    Negativo si la fecha es futura (una póliza que aún no vence).
    """
    today = today or date.today()
    parsed = value if isinstance(value, date) else parse_date(str(value) if value else None)
    if parsed is None:
        return None
    return (today - parsed).days