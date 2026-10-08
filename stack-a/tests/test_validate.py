"""Fase 2: motor de reglas declarativas (RF-07)."""

from __future__ import annotations

from datetime import date

from app.models import ValidationRule
from app.rules import build_custom_facts, evaluate_rules


# ── Facts derivados ─────────────────────────────────────────────────────────


def test_calcula_days_since(hoy):
    facts = build_custom_facts({"fecha_emision": "2026-09-01"}, today=hoy)

    # Del 1 de septiembre al 7 de octubre hay 36 días.
    assert facts["days_since_fecha_emision"] == 36


def test_calcula_el_alias_generico(hoy):
    facts = build_custom_facts({"fecha_fin_vigencia": "2026-09-01"}, today=hoy)

    assert facts["days_since_date"] == 36


def test_calcula_el_validador_del_ruc():
    facts = build_custom_facts({"ruc_emisor": "1790012345001"})

    assert facts["ruc_emisor_valid"] is True


def test_calcula_monto_in_range():
    facts = build_custom_facts(
        {"monto_total": "1250.00", "minimo_cobertura": 1000, "maximo_cobertura": 5000}
    )

    assert facts["monto_in_range"] is True


def test_monto_fuera_de_rango():
    facts = build_custom_facts(
        {"monto_total": "50", "minimo_cobertura": 1000, "maximo_cobertura": 5000}
    )

    assert facts["monto_in_range"] is False


def test_acepta_montos_con_formato_ecuatoriano():
    """`$ 1.250,00` es como llega el dato de un PDF, no `1250.00`."""
    facts = build_custom_facts(
        {"monto_total": "$ 1.250,00", "minimo_cobertura": 1000, "maximo_cobertura": 5000}
    )

    assert facts["monto_in_range"] is True


# ── Ejecución de reglas ─────────────────────────────────────────────────────


def test_regla_se_dispara_con_issue_de_severidad_error(regla_vigencia, hoy):
    facts = build_custom_facts({"fecha_emision": "2026-01-01"}, today=hoy)

    results, approved = evaluate_rules(
        regla_vigencia, {**facts, "doc_type": "factura"}, "factura", today=hoy
    )

    assert approved is False
    assert results[0].status == "fail"
    assert results[0].code == "FACTURA_VENCIDA"
    assert results[0].severity == "error"


def test_regla_no_se_dispara_si_el_documento_es_reciente(regla_vigencia, hoy):
    facts = build_custom_facts({"fecha_emision": "2026-10-01"}, today=hoy)

    results, approved = evaluate_rules(
        regla_vigencia, {**facts, "doc_type": "factura"}, "factura", today=hoy
    )

    assert approved is True
    assert results[0].status == "pass"


def test_una_regla_de_otro_tipo_no_se_evalua(regla_vigencia, hoy):
    """La regla es de `factura`: si llega una póliza, no aplica."""
    results, approved = evaluate_rules(
        regla_vigencia, {"doc_type": "poliza"}, "poliza", today=hoy
    )

    assert results == []
    assert approved is True


def test_regla_sin_doc_types_aplica_a_todo(hoy):
    """Una regla global no debe quedar atada a un tipo."""
    reglas = [
        ValidationRule(
            name="debe_tener_ruc",
            json_rule={
                "conditions": {"all": [{"fact": "ruc_emisor_valid", "operator": "equal", "value": True}]},
                "message": "Falta el RUC del emisor.",
                "code": "SIN_RUC",
            },
        )
    ]

    _results, approved = evaluate_rules(reglas, {"doc_type": "cualquiera"}, "cualquiera")

    assert approved is True  # la regla pide que sea True y no hay valor → no dispara


def test_una_regla_rota_no_tumba_el_job(hoy):
    """Una regla mal escrita se reporta como `error` y se sigue con las demás.

    Es lo que evita que un typo en Filament detenga todo el pipeline.
    """
    rotas = [
        ValidationRule(
            name="regla_rota",
            json_rule={"conditions": {"all": [{"fact": "x", "operator": "noExiste", "value": 1}]}},
        ),
        ValidationRule(
            name="regla_sana",
            json_rule={
                "conditions": {"all": [{"fact": "ruc_emisor_valid", "operator": "equal", "value": True}]},
                "message": "RUC verificado.",
                "code": "RUC_OK",
            },
        ),
    ]

    results, approved = evaluate_rules(
        rotas, {"ruc_emisor": "1790012345001"}, "factura"
    )

    por_nombre = {r.rule_name: r for r in results}
    assert por_nombre["regla_rota"].status == "error"
    assert por_nombre["regla_rota"].code == "RULE_ERROR"
    # La segunda regla se evaluó igual.
    assert por_nombre["regla_sana"].status == "pass"
    assert approved is True


def test_un_warning_no_bloquea(hoy):
    """Solo la severidad `error` bloquea el trámite (RF-07)."""
    reglas = [
        ValidationRule(
            name="aviso",
            severity="warning",
            json_rule={
                "conditions": {"all": [{"fact": "days_since_date", "operator": "greaterThan", "value": 30}]},
                "message": "El documento tiene más de 30 días.",
                "code": "ANTIGUO",
            },
        )
    ]

    facts = build_custom_facts({"fecha_emision": "2026-08-01"}, today=hoy)
    results, approved = evaluate_rules(
        reglas, {**facts, "doc_type": "factura"}, "factura", today=hoy
    )

    assert results[0].status == "fail"
    assert results[0].severity == "warning"
    assert approved is True


def test_sin_reglas_todo_aprueba():
    results, approved = evaluate_rules([], {"doc_type": "factura"}, "factura")

    assert results == []
    assert approved is True