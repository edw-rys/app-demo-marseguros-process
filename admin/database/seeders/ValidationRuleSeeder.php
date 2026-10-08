<?php

namespace Database\Seeders;

use App\Models\ValidationRule;
use Illuminate\Database\Seeder;

/**
 * Reglas de Fase 2 del Stack A (RF-07).
 *
 * La semántica es "la regla se cumple ⇒ hay un problema": una regla que dispara
 * produce un `fail`. Solo `severity = error` bloquea la aprobación; `warning`
 * solo se reporta.
 *
 * Los hechos que las reglas usan (`days_since_date`, `ruc_emisor_valid`, …) los
 * calcula `stack-a/app/rules.py` a partir de los campos extraídos, así que
 * aquí no se escribe nada de PHP: solo el JSON declarativo.
 */
class ValidationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            // ── Factura ─────────────────────────────────────────────────────
            [
                'name' => 'factura_no_vencida',
                'severity' => 'error',
                'doc_types' => ['factura'],
                'json_rule' => $this->rule(
                    ['days_since_date', ['gt', 90]],
                    code: 'FACTURA_VENCIDA',
                    message: 'La factura tiene más de 90 días desde su emisión.',
                ),
            ],
            [
                'name' => 'factura_tiene_rfc',
                'severity' => 'error',
                'doc_types' => ['factura'],
                'json_rule' => $this->rule(
                    ['not', ['rfc_emisor', ['exists']]],
                    code: 'FACTURA_SIN_RFC',
                    message: 'No se pudo extraer el RFC del emisor de la factura.',
                ),
            ],
            [
                'name' => 'factura_ruc_valido',
                'severity' => 'warning',
                'doc_types' => ['factura'],
                'json_rule' => $this->rule(
                    ['and', [
                        ['exists', 'ruc_emisor'],
                        ['not', ['ruc_emisor_valid']],
                    ]],
                    code: 'RUC_INVALIDO',
                    message: 'El RUC del emisor no supera la validación de checksum.',
                ),
            ],
            [
                'name' => 'factura_tiene_monto',
                'severity' => 'error',
                'doc_types' => ['factura'],
                'json_rule' => $this->rule(
                    ['not', ['monto_total', ['exists']]],
                    code: 'FACTURA_SIN_MONTO',
                    message: 'No se pudo extraer el monto total de la factura.',
                ),
            ],

            // ── Póliza ──────────────────────────────────────────────────────
            [
                'name' => 'poliza_tiene_numero',
                'severity' => 'error',
                'doc_types' => ['poliza_vigente'],
                'json_rule' => $this->rule(
                    ['not', ['numero_poliza', ['exists']]],
                    code: 'POLIZA_SIN_NUMERO',
                    message: 'No se pudo extraer el número de póliza.',
                ),
            ],
            [
                'name' => 'poliza_vigente_en_fecha',
                'severity' => 'error',
                'doc_types' => ['poliza_vigente'],
                'json_rule' => $this->rule(
                    ['and', [
                        ['exists', 'fecha_fin_vigencia'],
                        ['gt', ['days_since_fecha_fin_vigencia', 0]],
                    ]],
                    code: 'POLIZA_VENCIDA',
                    message: 'La vigencia de la póliza ya terminó.',
                ),
            ],
            [
                'name' => 'poliza_prima_minima',
                'severity' => 'warning',
                'doc_types' => ['poliza_vigente'],
                'json_rule' => $this->rule(
                    ['and', [
                        ['exists', 'monto_total'],
                        ['<', ['monto_total', ['literal', 50]]],
                    ]],
                    code: 'PRIMA_BAJA',
                    message: 'La prima declarada es sospechosamente baja.',
                ),
            ],

            // ── Comprobante de domicilio ────────────────────────────────────
            [
                'name' => 'comprobante_reciente',
                'severity' => 'error',
                'doc_types' => ['comprobante_domicilio'],
                'json_rule' => $this->rule(
                    ['days_since_date', ['gt', 90]],
                    code: 'COMPROBANTE_VIEJO',
                    message: 'El comprobante de domicilio tiene más de 90 días.',
                ),
            ],

            // ── Solicitud ───────────────────────────────────────────────────
            [
                'name' => 'solicitud_con_datos',
                'severity' => 'warning',
                'doc_types' => ['solicitud'],
                'json_rule' => $this->rule(
                    ['not', ['nombre_razon_social', ['exists']]],
                    code: 'SOLICITUD_SIN_DATOS',
                    message: 'La solicitud no muestra datos del solicitante.',
                ),
            ],

            // ── Transversal ─────────────────────────────────────────────────
            [
                // Sin `doc_types` aplica a todos: un PDF escaneado sin texto
                // legible no debe pasar ninguna validación de contenido.
                'name' => 'documento_con_texto',
                'severity' => 'error',
                'doc_types' => null,
                'json_rule' => $this->rule(
                    ['not', ['text_chars', ['gt', 30]]],
                    code: 'SIN_TEXTO',
                    message: 'No se extrajo texto utilizable del documento.',
                ),
            ],
        ];

        foreach ($rules as $rule) {
            ValidationRule::query()->updateOrCreate(
                ['name' => $rule['name']],
                [...$rule, 'active' => true, 'version' => 1],
            );
        }

        $this->command?->info(count($rules).' reglas de validación cargadas.');
    }

    /**
     * @param  array<int, mixed>  $condition  condición que dispara la regla
     * @return array<string, mixed>
     */
    private function rule(array $condition, string $code, string $message): array
    {
        return [
            'conditions' => ['all' => [$condition]],
            'event' => [
                'type' => 'validation_failed',
                'params' => [
                    'code'    => $code,
                    'message' => $message,
                ],
            ],
        ];
    }
}