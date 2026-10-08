<?php

namespace Database\Seeders;

use App\Models\FieldPattern;
use Illuminate\Database\Seeder;

/**
 * Regex de extracción de campos del Stack A (RF-06).
 *
 * Se escriben para Python (`re`), no para PHP: el worker es quien las ejecuta.
 * Los nombres siguen el snake_case que usan las reglas de Fase 2.
 *
 * Para Ecuador (broker local) y México (sucursal) mezclados: los RFC/Q11 y
 * los RUC de 13 dígitos conviven en la misma operación.
 */
class FieldPatternSeeder extends Seeder
{
    public function run(): void
    {
        $patterns = [
            [
                'name' => 'rfc_emisor',
                'description' => 'RFC mexicano de 12 o 13 caracteres (Stack A + B).',
                'regex' => '\b([A-ZÑ&]{3,4}-?\d{6}-?\d{3})\b',
            ],
            [
                'name' => 'ruc_emisor',
                'description' => 'RUC ecuatoriano de 13 dígitos; rules.py valida su checksum.',
                'regex' => '\b(\d{13})\b',
            ],
            [
                'name' => 'numero_factura',
                'description' => 'Número de comprobante: "001-001-000000123" o "F001-000123".',
                'regex' => '\b([A-Z]\d{3}-\d{6,10}|\d{3}-\d{3}-\d{9})\b',
            ],
            [
                'name' => 'monto_total',
                'description' => 'Importe total en texto. Se compara como número en las reglas.',
                'regex' => '(?:valor\s*total|total|monto|importe)\s*[:=]?\s*(?:USD|\$|S\/)?\s*([\d.,]+)',
            ],
            [
                'name' => 'iva',
                'description' => 'Valor del IVA / impuesto.',
                'regex' => 'IVA\s*[:=]?\s*(?:USD|\$|S\/)?\s*([\d.,]+)',
            ],
            [
                'name' => 'numero_poliza',
                'description' => 'Número de póliza, con o sin separadores.',
                'regex' => '(?:P[oó]liza|N[ºo°]\.?|N[uú]m\.?)\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{4,})',
            ],
            [
                'name' => 'fecha_emision',
                'description' => 'Fechas en dd/mm/aaaa, dd-mm-aaaa o ISO.',
                'regex' => '\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}-\d{2}-\d{2})\b',
            ],
            [
                'name' => 'fecha_fin_vigencia',
                'description' => 'Fin / vencimiento de la póliza.',
                'regex' => '(?:fin|vence|vigencia\s+hasta|hasta)\s*[:=]?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}-\d{2}-\d{2})',
            ],
            [
                'name' => 'nombre_razon_social',
                'description' => 'Razón social del emisor; se corta en el salto de línea.',
                'regex' => '(?:raz[oó]n\s+social|empresa|contribuyente)\s*[:=]?\s*([^\n\r]{3,80})',
            ],
            [
                'name' => 'telefono',
                'description' => 'Teléfono, fijo o móvil.',
                'regex' => '(?:tel[eé]fono|tel\.?|celular|m[oó]vil)\s*[:=]?\s*(\+?[\d\s().-]{7,20}\d)',
            ],
            [
                'name' => 'email',
                'description' => 'Correo electrónico.',
                'regex' => '\b([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b',
            ],
        ];

        foreach ($patterns as $pattern) {
            FieldPattern::query()->updateOrCreate(
                ['name' => $pattern['name']],
                [...$pattern, 'active' => true, 'version' => 1],
            );
        }

        $this->command?->info(count($patterns).' patrones de extracción cargados.');
    }
}