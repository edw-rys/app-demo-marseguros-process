<?php

namespace Database\Seeders;

use App\Models\ValidationPrompt;
use Illuminate\Database\Seeder;

/**
 * Prompts versionados del Stack B (RF-08).
 *
 * Los nombres NO son libres: `stack-b/app/main.py` los busca por convención.
 *
 * - `classify`                     → clasificación (usa el placeholder `{types}`)
 * - `extract_{doc_type}_v1`        → extracción con el schema del tipo detectado
 * - `validate_{doc_type}_v1`       → criterios de Fase 2 para ese tipo
 * - `validate_default_v1`          → criterios genéricos cuando no hay uno específico
 *
 * Editarlos en Filament sube `version` y surte efecto en el siguiente job,
 * sin redeploy: Laravel manda el texto activo en cada request.
 */
class ValidationPromptSeeder extends Seeder
{
    public function run(): void
    {
        $prompts = [
            [
                'name' => 'classify',
                'expected_schema' => [
                    'doc_type'     => 'string',
                    'confidence'   => 'float 0..1',
                    'reasoning'    => 'string',
                    'key_signals'  => 'string[]',
                ],
                'prompt_text' => <<<'TXT'
                Eres un clasificador experto de documentos de seguros en Ecuador.
                Tipos posibles: {types}

                Analiza el documento adjunto y determina a cuál de esos tipos corresponde.

                Devuelve JSON estricto con esta forma exacta:
                {
                  "doc_type": "<uno de los tipos, o 'desconocido'>",
                  "confidence": <float entre 0 y 1>,
                  "reasoning": "<justificación breve en español, máximo 2 frases>",
                  "key_signals": ["<pistas concretas que usaste, p.ej. 'menciona RUC emisor'>"]
                }

                Reglas:
                - Si el documento no corresponde a ningún tipo, responde "desconocido" con confidence menor a 0.5.
                - La confidence debe reflejar tu certeza real: no la infles.
                - Los tipos configurados son: {types}
                TXT,
            ],

            [
                'name' => 'extract_factura_v1',
                'expected_schema' => [
                    'rfc_emisor'    => 'string|null',
                    'ruc_emisor'    => 'string|null',
                    'numero_factura'=> 'string|null',
                    'monto_total'   => 'number|null',
                    'iva'           => 'number|null',
                    'fecha_emision' => 'YYYY-MM-DD|null',
                    'nombre_razon_social' => 'string|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "rfc_emisor": {"type": "string", "description": "RFC mexicano de 12 o 13 caracteres"},
                  "ruc_emisor": {"type": "string", "description": "RUC ecuatoriano de 13 dígitos"},
                  "numero_factura": {"type": "string", "description": "Número de comprobante"},
                  "monto_total": {"type": "number", "description": "Importe total sin símbolo"},
                  "iva": {"type": "number"},
                  "fecha_emision": {"type": "string", "format": "date"},
                  "nombre_razon_social": {"type": "string"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                'name' => 'extract_poliza_vigente_v1',
                'expected_schema' => [
                    'numero_poliza'    => 'string|null',
                    'nombre_razon_social' => 'string|null',
                    'fecha_emision'    => 'YYYY-MM-DD|null',
                    'fecha_fin_vigencia' => 'YYYY-MM-DD|null',
                    'monto_total'      => 'number|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "numero_poliza": {"type": "string"},
                  "nombre_razon_social": {"type": "string", "description": "Nombre del asegurado o razón social"},
                  "fecha_emision": {"type": "string", "format": "date", "description": "Fecha de emisión de la póliza"},
                  "fecha_fin_vigencia": {"type": "string", "format": "date", "description": "Fecha de fin de vigencia"},
                  "monto_total": {"type": "number", "description": "Prima o suma asegurada"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                'name' => 'extract_ine_v1',
                'expected_schema' => [
                    'nombre_razon_social' => 'string|null',
                    'fecha_emision' => 'YYYY-MM-DD|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "nombre_razon_social": {"type": "string", "description": "Nombre completo del titular"},
                  "fecha_emision": {"type": "string", "format": "date", "description": "Fecha de expedición del documento"},
                  "email": {"type": "string"},
                  "telefono": {"type": "string"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                'name' => 'extract_comprobante_domicilio_v1',
                'expected_schema' => [
                    'nombre_razon_social' => 'string|null',
                    'fecha_emision' => 'YYYY-MM-DD|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "nombre_razon_social": {"type": "string", "description": "Titular del servicio"},
                  "fecha_emision": {"type": "string", "format": "date", "description": "Fecha de emisión o del corte"},
                  "monto_total": {"type": "number"},
                  "telefono": {"type": "string"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                'name' => 'extract_solicitud_v1',
                'expected_schema' => [
                    'nombre_razon_social' => 'string|null',
                    'fecha_emision' => 'YYYY-MM-DD|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "nombre_razon_social": {"type": "string", "description": "Datos del solicitante"},
                  "fecha_emision": {"type": "string", "format": "date", "description": "Fecha de la solicitud"},
                  "numero_poliza": {"type": "string", "description": "Si la solicitud ya menciona un número de póliza"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                'name' => 'extract_estado_cuenta_v1',
                'expected_schema' => [
                    'nombre_razon_social' => 'string|null',
                    'fecha_emision' => 'YYYY-MM-DD|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "nombre_razon_social": {"type": "string", "description": "Titular de la cuenta"},
                  "fecha_emision": {"type": "string", "format": "date", "description": "Fecha de corte del estado de cuenta"},
                  "monto_total": {"type": "number", "description": "Saldo o monto adeudado"},
                  "numero_poliza": {"type": "string", "description": "Número de póliza si aparece en el documento"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                'name' => 'extract_acta_constitutiva_v1',
                'expected_schema' => [
                    'nombre_razon_social' => 'string|null',
                    'fecha_emision' => 'YYYY-MM-DD|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "nombre_razon_social": {"type": "string", "description": "Razón social de la compañía constituida"},
                  "fecha_emision": {"type": "string", "format": "date", "description": "Fecha de constitución o de inscripción"},
                  "ruc_emisor": {"type": "string", "description": "RUC de la compañía, si consta"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                'name' => 'extract_rfc_v1',
                'expected_schema' => [
                    'rfc_emisor' => 'string|null',
                    'nombre_razon_social' => 'string|null',
                    'fecha_emision' => 'YYYY-MM-DD|null',
                ],
                'prompt_text' => <<<'TXT'
                Extrae del documento los campos solicitados.
                Los campos ausentes deben ir como null; nunca inventes un valor.
                Para cada campo no encontrado añade además la clave "{field}_reason" con valor "no_encontrado".

                Esquema de salida (JSON estricto, todas las claves presentes):
                {
                  "rfc_emisor": {"type": "string", "description": "RFC del emisor o de la persona física"},
                  "nombre_razon_social": {"type": "string", "description": "Nombre o razón social del titular"},
                  "fecha_emision": {"type": "string", "format": "date", "description": "Fecha de emisión o de vigencia"}
                }

                Responde solo con el objeto JSON.
                TXT,
            ],

            [
                // ── Criterios de Fase 2 ─────────────────────────────────────
                'name' => 'validate_factura_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que la factura sea apta para un trámite de seguro:
                - El emisor debe estar identificado (RFC o RUC) y ser coherente con el país del trámite.
                - La fecha de emisión no debe tener más de 90 días.
                - Debe haber un monto total legible y no absurdo.
                - No debe estar anulada, cancelada ni ser una nota de crédito negativa.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }

                Reglas:
                - Sin problemas: `approved` true e `issues` vacío.
                - Usa mensajes en español listos para enviar al cliente.
                TXT,
            ],

            [
                'name' => 'validate_poliza_vigente_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que la póliza esté vigente y sea apta para el trámite:
                - La fecha de fin de vigencia debe ser posterior a hoy.
                - Debe haber número de póliza identificable.
                - La prima o suma asegurada debe ser coherente con el tipo de riesgo.
                - La póliza no debe estar cancelada, suspendida ni vencida.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }

                Reglas:
                - Sin problemas: `approved` true e `issues` vacío.
                - Usa mensajes en español listos para enviar al cliente.
                TXT,
            ],

            [
                'name' => 'validate_ine_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que la identificación sea válida para el trámite:
                - Debe leerse el nombre completo del titular.
                - La foto y los datos no deben estar tapados ni recortados.
                - No debe ser una copia de una copia ilegible.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }
                TXT,
            ],

            [
                'name' => 'validate_comprobante_domicilio_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que el comprobante de domicilio sirva para el trámite:
                - La fecha debe tener menos de 90 días.
                - Debe leerse el domicilio y el titular.
                - Debe corresponder a un servicio de luz, agua, internet o teléfono.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }
                TXT,
            ],

            [
                'name' => 'validate_solicitud_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que la solicitud de seguro esté completa:
                - Debe tener los datos del solicitante.
                - Debe indicar el producto o plan que se quiere contratar.
                - Debe estar firmada y fechada.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }
                TXT,
            ],

            [
                'name' => 'validate_estado_cuenta_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que el estado de cuenta sirva para el trámite:
                - Debe tener menos de 90 días.
                - Debe leerse el titular y el período que cubre el estado de cuenta.
                - Debe estar completo y no cortado.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }
                TXT,
            ],

            [
                'name' => 'validate_acta_constitutiva_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que el acta de constitución sirva para el trámite:
                - Debe leerse la razón social de la compañía.
                - Debe tener fecha de constitución y número de inscripción.
                - No debe estar anulada ni tener cambio de objeto social relevante.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }
                TXT,
            ],

            [
                'name' => 'validate_rfc_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que la constancia fiscal sirva para el trámite:
                - Debe leerse el RFC y el nombre o razón social del titular.
                - La constancia debe estar vigente y no tener fecha de expiración vencida.
                - Debe corresponder al país del trámite en curso.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }
                TXT,
            ],

            [
                'name' => 'validate_default_v1',
                'expected_schema' => [
                    'approved' => 'boolean',
                    'issues'   => 'array<{code, severity, message}>',
                ],
                'prompt_text' => <<<'TXT'
                Revisa que el documento esté completo y sea utilizable para un trámite de seguro:
                - Debe tener contenido legible.
                - No debe estar truncado ni alterar.
                - Debe corresponder a un documento de seguros.

                Responde JSON estricto:
                {
                  "approved": <boolean>,
                  "issues": [
                    {"code": "<CODIGO_EN_MAYUSCULAS>", "severity": "<error|warning>", "message": "<descripción legible en español>"}
                  ]
                }
                TXT,
            ],
        ];

        foreach ($prompts as $prompt) {
            $exists = ValidationPrompt::query()->where('name', $prompt['name'])->exists();

            // No se pisa un prompt ya editado a mano desde Filament: el seeder
            // solo crea lo que falta. `demo:seed --force` pisa todo.
            if ($exists && ! $this->shouldOverwrite()) {
                continue;
            }

            ValidationPrompt::query()->updateOrCreate(
                ['name' => $prompt['name']],
                [...$prompt, 'active' => true, 'version' => 1],
            );
        }

        $this->command?->info(count($prompts).' prompts cargados.');
    }

    private function shouldOverwrite(): bool
    {
        return (bool) $this->command?->hasOption('force')
            && (bool) $this->command->option('force');
    }
}