"""Clasificación por score (RF-05) y extracción por regex (RF-06)."""

from __future__ import annotations

from app import classify as classifier
from app.fields import extract_fields, parse_date, rfc_valid, ruc_valid


# ── RF-05: score = 0.5·required + 0.3·bonus + 0.2·filename, umbral 0.4 ──────


def test_documento_factura_se_clasifica(reglas_factura, txt_file):
    doc_type, confidence, _ = classifier.classify(
        txt_file.read_text(encoding="utf-8"), txt_file.name, reglas_factura
    )

    assert doc_type == "factura"
    assert confidence >= 0.4


def test_documento_sin_señales_queda_desconocido(reglas_factura):
    doc_type, confidence, _ = classifier.classify(
        "Un correo de saludo, sin ningún documento.", "notas.txt", reglas_factura
    )

    assert doc_type == "desconocido"
    assert confidence < 0.4


def test_sin_reglas_no_se_adivina(reglas_factura):
    """Sin reglas no hay contra qué comparar: nunca se inventa un tipo."""
    doc_type, confidence, _ = classifier.classify("cualquier cosa", "x.txt", [])

    assert doc_type == "desconocido"
    assert confidence == 0.0


def test_el_score_devuelve_el_desglose(reglas_factura, txt_file):
    """El breakdown es lo que el admin muestra para explicar la decisión."""
    _, _, breakdown = classifier.classify(
        txt_file.read_text(encoding="utf-8"), txt_file.name, reglas_factura
    )

    scores = breakdown["factura"]
    assert scores["required_score"] == 0.5
    assert scores["hits_required"] >= 1
    assert "total" in scores["keywords_found"]
    # Las reglas perdedoras también se evalúan y se muestran: saber por qué NO
    # se eligió otra regla es tan útil como saber por qué sí se eligió esta.
    assert "poliza" in breakdown
    assert breakdown["poliza"]["total"] == 0.0


def test_el_nombre_del_archivo_suma(reglas_factura):
    """El mismo texto con y sin "factura" en el nombre no puntúa igual."""
    texto = "RUC 1790012345001, factura de venta, total $120"

    _, _, con_pista = classifier.classify(texto, "factura-agosto.txt", reglas_factura)
    _, _, sin_pista = classifier.classify(texto, "documento-1.txt", reglas_factura)

    assert con_pista["factura"]["total"] > sin_pista["factura"]["total"]


def test_la_busqueda_ignora_mayusculas_y_acentos(reglas_factura):
    """Un PDF puede traer "FACTURA" o "Factúra"; la regla tiene que aguantar."""
    doc_type, _, _ = classifier.classify(
        "FACTURA\nRUC: 1790012345001\nTOTAL: 120", "FACTURA.TXT", reglas_factura
    )

    assert doc_type == "factura"


def test_el_score_no_supera_la_unitario(reglas_factura):
    """Con todos los keywords el total tiene que quedarse en 1.0 como máximo."""
    texto = "factura ruc total iva emisión factura ruc total iva emisión"

    _, _, breakdown = classifier.classify(texto, "factura.txt", reglas_factura)

    assert breakdown["factura"]["total"] <= 1.0


# ── RF-06: extracción por regex ─────────────────────────────────────────────


def test_extrae_campos_con_regex(txt_file, patrones_factura):
    fields = extract_fields(txt_file.read_text(encoding="utf-8"), patrones_factura)

    assert fields["ruc_emisor"] == "1790012345001"
    assert fields["fecha_emision"] == "2026-09-01"
    assert fields["total"] == "1.250,00"


def test_campo_ausente_es_none(patrones_factura):
    fields = extract_fields("Un texto sin datos.", patrones_factura)

    assert fields["ruc_emisor"] is None


def test_agrega_el_validador_del_ruc(patrones_factura):
    """`ruc_emisor` produce `ruc_emisor_valid`: es lo que RF-06 pide validar."""
    texto = "RUC emisor: 1790012345001"

    fields = extract_fields(texto, patrones_factura)

    assert fields["ruc_emisor_valid"] is True


def test_detecta_un_ruc_invalido(patrones_factura):
    """El dígito verificador no cuadra → `valid = False`, para que la regla
    de Fase 2 pueda rechazarlo sin adivinar."""
    texto = "RUC emisor: 1712345678000"

    fields = extract_fields(texto, patrones_factura)

    assert fields["ruc_emisor_valid"] is False


def test_una_regex_invalida_no_tumba_el_job(patrones_factura):
    """El regex lo escribe un humano en Filament: uno roto se salta."""
    patrones = dict(patrones_factura, roto="(no-es-regex")

    fields = extract_fields("RUC emisor: 1790012345001", patrones)

    assert fields["roto"] is None
    # Las demás siguen funcionando.
    assert fields["ruc_emisor"] == "1790012345001"


def test_varias_coincidencias_vienen_como_lista(patrones_factura):
    texto = "Factura A RUC: 1790012345001\nFactura B RUC: 1790012345002"

    fields = extract_fields(texto, patrones_factura)

    assert isinstance(fields["ruc_emisor"], list)
    assert len(fields["ruc_emisor"]) == 2


def test_una_sola_coincidencia_es_escalar(patrones_factura):
    fields = extract_fields("RUC emisor: 1790012345001", patrones_factura)

    assert not isinstance(fields["ruc_emisor"], list)


# ── Validadores fiscales y fechas ────────────────────────────────────────────


def test_ruc_valido():
    assert ruc_valid("1790012345001") is True


def test_ruc_invalido():
    assert ruc_valid("1790012345000") is False
    assert ruc_valid("cualquier-cosa") is False
    assert ruc_valid(None) is False


def test_rfc_valido():
    assert rfc_valid("1790012345001") is True
    assert rfc_valid("1712345678") is True


def test_parse_date_acepta_varios_formatos():
    from datetime import date

    assert parse_date("2026-09-01") == date(2026, 9, 1)
    assert parse_date("01/09/2026") == date(2026, 9, 1)
    assert parse_date("no es una fecha") is None
    assert parse_date(None) is None