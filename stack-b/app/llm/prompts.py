"""Prompts por defecto del Stack B.

Los prompts *editables* viven en la tabla `validation_prompts` de Laravel y
llegan en cada request — estos son los valores semilla que se cargan con
`demo:seed`. Editarlos en Filament no requiere tocar este archivo.
"""

from __future__ import annotations

# ── RF-05: clasificación ─────────────────────────────────────────────────────

CLASSIFY = """Eres un clasificador experto de documentos de seguros en Ecuador.
Tipos posibles: {types}

Analiza el documento adjunto y determina a cuál de esos tipos corresponde.

Devuelve JSON estricto con esta forma exacta:
{{
  "doc_type": "<uno de los tipos, o 'desconocido'>",
  "confidence": <float entre 0 y 1>,
  "reasoning": "<justificación breve en español, máximo 2 frases>",
  "key_signals": ["<pistas concretas que usaste, p.ej. 'menciona RUC emisor', 'logo de la aseguradora'>"]
}}

Reglas:
- Si el documento no corresponde a ningún tipo, responde "desconocido" con confidence menor a 0.5.
- La confidence debe reflejar tu certeza real: no la infles.
- Los tipos configurados son: {types}
"""

# ── RF-06: extracción de campos ──────────────────────────────────────────────

EXTRACT = """Extrae del documento los campos solicitados.
Los campos ausentes deben ir como null; nunca inventes un valor.

Esquema de salida (JSON estricto, todas las claves presentes):
{{
{schema}
}}

Para cada campo no encontrado, en vez de inventarlo, añade la clave "{field}_reason"
con el valor "no_encontrado".

Responde solo con el objeto JSON.
"""

# ── RF-08: validación semántica ───────────────────────────────────────────────

VALIDATE = """{criteria}

Revisa el documento y responde JSON estricto:
{{
  "approved": <boolean>,
  "issues": [
    {{"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}}
  ]
}}

Reglas:
- Si no encuentras ningún problema, `approved` es true e `issues` es una lista vacía.
- Usa el mismo `message` en español para que se pueda enviar al cliente sin editar.
"""

# ── RF-09: respuesta con errores ─────────────────────────────────────────────

GENERATE_ERROR_REPLY = """Eres el asistente de una aseguradora escribiendo a un cliente.

Situación: el cliente envió documentación y hay {issue_count} problema(s):
{issues}

Redacta un correo en español que:
1. Sea cordial y directo (usted).
2. Enumere los problemas de forma clara y accionable.
3. Pida corregir y reenviar.
4. No mencione que usas inteligencia artificial, ni términos técnicos internos.

Responde solo con el cuerpo del correo en texto plano, sin asunto ni firmas.
Máximo 800 caracteres.
"""

GENERATE_CLARIFY_REPLY = """Eres el asistente de una aseguradora escribiendo a un cliente.
No podido identificar estos archivos adjuntos: {files}

Redacta un correo en español (usted) que pida aclarar qué es cada archivo, sin
mencionar que usas IA. Responde solo con el cuerpo del correo, máximo 800 caracteres.
"""

# ── Esquemas de extracción por tipo de documento (RF-06) ─────────────────────

FIELD_SCHEMAS: dict[str, dict[str, object]] = {
    "factura": {
        "rfc_emisor": {"type": "string", "description": "RFC/RUC del emisor"},
        "rfc_receptor": {"type": "string", "description": "RFC/RUC del receptor"},
        "monto_total": {"type": "number"},
        "fecha_emision": {"type": "string", "format": "date"},
        "folio": {"type": "string"},
        "uuid_cfdi": {"type": "string"},
        "concepto": {"type": "string"},
    },
    "ine": {
        "nombre": {"type": "string"},
        "curp": {"type": "string"},
        "fecha_nacimiento": {"type": "string", "format": "date"},
        "domicilio": {"type": "string"},
    },
    "comprobante_domicilio": {
        "direccion": {"type": "string"},
        "fecha_emision": {"type": "string", "format": "date"},
        "emisor": {"type": "string"},
    },
    "rfc": {
        "rfc_persona_moral": {"type": "string"},
        "razon_social": {"type": "string"},
        "fecha_emision": {"type": "string", "format": "date"},
    },
    "poliza_vigente": {
        "numero_poliza": {"type": "string"},
        "asegurado_nombre": {"type": "string"},
        "fecha_inicio": {"type": "string", "format": "date"},
        "fecha_fin": {"type": "string", "format": "date"},
        "monto_cobertura": {"type": "number"},
        "aseguradora": {"type": "string"},
    },
    "solicitud": {
        "numero_solicitud": {"type": "string"},
        "fecha_solicitud": {"type": "string", "format": "date"},
        "producto": {"type": "string"},
        "solicitante": {"type": "string"},
    },
    "estado_cuenta": {
        "numero_cuenta": {"type": "string"},
        "saldo": {"type": "number"},
        "fecha_corte": {"type": "string", "format": "date"},
    },
    "acta_constitutiva": {
        "razon_social": {"type": "string"},
        "ruc": {"type": "string"},
        "fecha_constitucion": {"type": "string", "format": "date"},
    },
}