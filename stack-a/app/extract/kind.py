"""Detección del tipo real de archivo por magic bytes (RF-03).

No nos fiamos de la extensión declarada: un `.exe` renombrado a `factura.pdf`
tiene que detectarse como PE executable (CU-04).
"""

from __future__ import annotations

from pathlib import Path

import filetype

# Extensiones que aceptamos para cada tipo detectado. El mapeo es deliberadamente
# laxo (varias extensiones por tipo) porque el objetivo es detectar *discrepancia*,
# no validar la extensión al revés.
_EXTENSIONS_BY_KIND: dict[str, set[str]] = {
    "pdf": {"pdf"},
    "jpg": {"jpg", "jpeg"},
    "png": {"png"},
    "gif": {"gif"},
    "bmp": {"bmp"},
    "tiff": {"tif", "tiff"},
    "webp": {"webp"},
    "xlsx": {"xlsx", "xlsm"},
    "docx": {"docx"},
    "zip": {"zip"},
    "exe": {"exe", "dll", "bin"},
    # Tipos que Magic Bytes no distingue de forma fiable y se resuelven por
    # extensión en `resolve_kind`.
    "csv": {"csv"},
    "txt": {"txt", "text", "log"},
}

# `filetype` no detecta estos dos; se tratan por extensión.
_AMBIGUOUS_KINDS = {"csv", "txt"}


def detect_kind(path: str | Path) -> tuple[str, str | None]:
    """Devuelve `(kind, mime)` leyendo los magic bytes del archivo.

    `kind` es `"unknown"` si el tipo no está soportado, lo que manda el documento
    a la cola de revisión (RF-03).
    """
    p = Path(path)
    kind = filetype.guess(str(p))
    if kind is None:
        # Sin magic bytes: se cae a la extension para CSV/TXT, si no `unknown`.
        ext = p.suffix.lower().lstrip(".")
        if ext in _AMBIGUOUS_KINDS:
            return ext, f"text/{'csv' if ext == 'csv' else 'plain'}"
        return "unknown", None

    mime = kind.mime
    resolved = kind.extension
    return resolved, mime


def expected_extensions(kind: str) -> set[str]:
    """Extensiones legítimas para un `kind` dado."""
    return _EXTENSIONS_BY_KIND.get(kind, {kind})


def has_extension_mismatch(path: str | Path, kind: str) -> bool:
    """True si la extensión declarada no corresponde al tipo real (RF-03).

    Solo se marca discrepancia cuando la extensión es una que *conocemos*: un
    `.bin` sobre un PDF no es un ataque, es un archivo con extensión neutra.
    """
    ext = Path(path).suffix.lower().lstrip(".")
    if not ext:
        return False

    known = {e for kinds in _EXTENSIONS_BY_KIND.values() for e in kinds}
    if ext not in known:
        return False

    return ext not in expected_extensions(kind)


def is_supported(kind: str) -> bool:
    """Tipos con los que sabemos extraer texto."""
    return kind in {
        "pdf",
        "jpg",
        "png",
        "gif",
        "bmp",
        "tiff",
        "webp",
        "xlsx",
        "docx",
        "csv",
        "txt",
    }


def is_image(kind: str) -> bool:
    return kind in {"jpg", "png", "gif", "bmp", "tiff", "webp"}