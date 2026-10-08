"""Anonimización de PII antes de mandar nada al LLM (RNF-03).

El requisito es explícito: al proveedor externo solo van identificadores
fiscales. Cédulas, móviles, emails y nombres de persona se enmascaran; el RUC
se conserva porque sin él no se puede validar la factura.
"""

from __future__ import annotations

from app.redact import describe_redaction, redact


def test_enmascara_el_email():
    out = redact("Favor de escribir a maria.gomez@correo.com")

    assert "maria.gomez@correo.com" not in out
    assert "correo.com" not in out


def test_enmascara_cedula():
    out = redact("Cédula: 1712345678")

    assert "1712345678" not in out
    assert "CEDULA_REDACTADA" in out


def test_enmascara_celular():
    out = redact("Escríbame al 0981234567")

    assert "0981234567" not in out
    assert "MOVIL_REDACTADO" in out


def test_enmascara_telefono_fijo():
    out = redact("Llame al 02 2345678")

    assert "2345678" not in out


def test_conserva_el_ruc():
    """Sin el RUC no hay validación posible: es lo que sí puede salir."""
    ruc = "1790012345001"

    out = redact(f"Factura del emisor RUC {ruc} por $120.00")

    assert ruc in out


def test_no_confunde_ruc_con_cedula():
    """El RUC tiene 13 dígitos y empieza por 1: el patrón de cédula (10) no
    debe pisarlo a mitad de la redacción."""
    ruc = "1790012345001"

    out = redact(ruc)

    assert ruc in out
    assert "CEDULA" not in out


def test_enmascara_el_nombre_del_remitente():
    """Se enmascara literal, porque puede traer tildes y mayúsculas."""
    out = redact(
        "Estimada señora María Fernández",
        sender_name="María Fernández",
    )

    assert "María Fernández" not in out
    assert "Fernández" not in out


def test_texto_vacio_no_revienta():
    assert redact("") == ""
    assert redact(None) is None or redact(None) == ""


def test_texto_sin_pii_pasa_intacto():
    original = "Factura 001 por $120.00 con IVA."

    assert redact(original) == original


def test_describe_redaction_cuenta_lo_ocultado():
    """Lo que se reports al admin en el `detail` de la etapa."""
    original = "maria@correo.com, RUC 1790012345001, cédula 1712345678"
    redacted = redact(original)

    counts = describe_redaction(original, redacted)

    assert counts["emails"] == 1
    assert counts["emails_after"] == 0
    assert counts["rucs_conservados"] == 1