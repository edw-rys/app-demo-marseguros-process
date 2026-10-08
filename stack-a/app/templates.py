"""Templates de respuesta automática del Stack A (RF-09).

Stack A es determinista: las respuestas se componen con plantillas fijas, sin LLM.
Los casos "faltan archivos" y "validación OK" son idénticos a los de Stack B;
los casos con errores o sin clasificar también usan plantilla en Stack A (en
Stack B esos dos los redacta el LLM).
"""

from __future__ import annotations

from app.models import GenerateReplyRequest

# Los textos salen del correo del cliente, así que se cortan para que una
# plantilla no crezca sin límite con una lista larga de issues.
_MAX_ITEMS = 10
_MAX_LEN = 800


def _label_for(doc_type: str) -> str:
    return {
        "factura": "la factura",
        "ine": "la identificación (INE)",
        "comprobante_domicilio": "el comprobante de domicilio",
        "rfc": "el RFC",
        "poliza_vigente": "la póliza vigente",
        "poliza_vencida": "la póliza",
        "solicitud": "la solicitud",
        "acta_constitutiva": "el acta constitutiva",
        "estado_cuenta": "el estado de cuenta",
    }.get(doc_type, f"el documento de tipo '{doc_type}'")


def render_template(req: GenerateReplyRequest) -> str:
    """Devuelve el cuerpo del correo según el estado del job."""
    if req.status == "validated":
        return (
            "Estimado(a), hemos recibido su documentación y esta fue validada "
            "correctamente. No tiene pendiente enviar nada más por este trámite."
        )

    if req.status == "pending":
        faltantes = req.missing_documents[:_MAX_ITEMS]
        lista = "\n".join(f"  - {_label_for(d)}" for d in faltantes)
        extra = (
            f"\n  - ... y {len(req.missing_documents) - _MAX_ITEMS} documento(s) más"
            if len(req.missing_documents) > _MAX_ITEMS
            else ""
        )
        return (
            "Recibimos su correo, pero todavía falta documentación para poder "
            "continuar con el trámite. Por favor adjunte:\n"
            f"{lista}{extra}"
        )

    if req.status == "review":
        no_clasificados = req.unclassified[:_MAX_ITEMS]
        if no_clasificados:
            lista = "\n".join(f"  - {n}" for n in no_clasificados)
            return (
                "Recibimos su correo, pero no pudimos identificar los siguientes "
                f"archivos:\n{lista}\n\n"
                "¿Podría confirmar qué documento es cada uno y reenviarlo si es "
                "posible en PDF o en una imagen más legible?"
            )
        return (
            "Recibimos su correo, pero no pudimos leer alguno de los documentos "
            "adjuntos. Por favor reenvíelo en PDF o en una imagen de mejor "
            "calidad para que podamos procesarlo."
        )

    # status == "failed": hay issues de validación que reportar.
    issues = req.issues[:_MAX_ITEMS]
    if issues:
        lineas = "\n".join(
            f"  - {i.get('message') or i.get('code') or i.get('rule_name', 'sin detalle')}"
            for i in issues
        )
        return (
            "Recibimos su documentación, pero encontramos los siguientes "
            f"problemas:\n{lineas}\n\n"
            "Por favor corríjalos y vuelva a enviarnos los documentos."
        )

    return (
        "Recibimos su correo, pero no pudimos completar la validación de los "
        "documentos enviados. Por favor vuelva a enviarlos para poder "
        "continuar con el trámite."
    )


def render_and_truncate(req: GenerateReplyRequest) -> str:
    """Como `render_template`, pero garantiza el límite de 800 caracteres (RF-09)."""
    return render_template(req)[:_MAX_LEN]