<?php

namespace Database\Seeders;

use App\Models\DocTypeRule;
use Illuminate\Database\Seeder;

/**
 * Reglas de clasificación por keywords del Stack A (RF-05).
 *
 * El score es `0.5*required + 0.3*bonus + 0.2*filename`; por debajo de 0.4 el
 * documento queda como `desconocido` y el pipeline pide aclaración en vez de
 * adivinar. Los `required_keywords` son deliberadamente pocos: basta con que
 * el documento NO los tenga para que la clasificación caiga sola.
 */
class DocTypeRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'name' => 'factura',
                'label' => 'Factura / comprobante de venta',
                'required_keywords' => ['factura', 'comprobante de venta'],
                'keywords' => [
                    'número de comprobante', 'valor total', 'subtotal', 'iva',
                    'razón social', 'cliente', 'fecha de emisión',
                ],
                'filename_hints' => ['factura', 'comprobante', 'nota_venta', 'invoice'],
            ],
            [
                'name' => 'ine',
                'label' => 'Cédula de identidad',
                'required_keywords' => ['cédula de identidad', 'cedula de identidad'],
                'keywords' => [
                    'registro civil', 'nombres', 'apellidos', 'fecha de nacimiento',
                    'nacionalidad', 'expedición',
                ],
                'filename_hints' => ['ine', 'cedula', 'ci', 'identidad'],
            ],
            [
                'name' => 'comprobante_domicilio',
                'label' => 'Comprobante de domicilio',
                'required_keywords' => ['comprobante de domicilio', 'recibo de'],
                'keywords' => [
                    'servicio', 'dirección', 'cliente', 'consumo',
                    'luz', 'agua', 'internet', 'telefono',
                ],
                'filename_hints' => ['recibo', 'domicilio', 'comprobante', 'servicio'],
            ],
            [
                'name' => 'rfc',
                'label' => 'Constancia de situación fiscal',
                'required_keywords' => ['situación fiscal', 'situacion fiscal'],
                'keywords' => [
                    'rfc', 'razón social', 'régimen', 'domicilio fiscal',
                    'sat', 'trámite',
                ],
                'filename_hints' => ['rfc', 'constancia', 'fiscal'],
            ],
            [
                'name' => 'poliza_vigente',
                'label' => 'Póliza vigente',
                'required_keywords' => ['póliza', 'poliza'],
                'keywords' => [
                    'número de póliza', 'número de poliza', 'prima', 'vigencia',
                    'asegurado', 'tomador', 'riesgo cubierto', 'suma asegurada',
                    'fecha de emisión', 'fecha de inicio', 'fecha de fin',
                ],
                'filename_hints' => ['poliza', 'póliza', 'policy'],
            ],
            [
                'name' => 'solicitud',
                'label' => 'Formulario de solicitud',
                'required_keywords' => ['solicitud', 'formulario'],
                'keywords' => [
                    'datos del solicitante', 'póliza nueva', 'plan', 'cobertura',
                    'firma', 'fecha de solicitud', 'agente',
                ],
                'filename_hints' => ['solicitud', 'formulario', 'request'],
            ],
            [
                'name' => 'estado_cuenta',
                'label' => 'Estado de cuenta',
                'required_keywords' => ['estado de cuenta'],
                'keywords' => [
                    'saldo anterior', 'saldo actual', 'movimientos', 'pagos',
                    'intereses', 'periodo', 'facturas pendientes',
                ],
                'filename_hints' => ['estado', 'account', 'resumen'],
            ],
            [
                'name' => 'acta_constitutiva',
                'label' => 'Acta de constitución',
                'required_keywords' => ['acta constitutiva', 'acta de constitución'],
                'keywords' => [
                    'notaría', 'razón social', 'capital subscribed', 'objeto social',
                    'administrador', 'representante legal', 'inscrita en el registro',
                ],
                'filename_hints' => ['acta', 'constitucion', 'constitución'],
            ],
        ];

        foreach ($rules as $rule) {
            DocTypeRule::query()->updateOrCreate(
                ['name' => $rule['name']],
                [...$rule, 'active' => true, 'version' => 1],
            );
        }

        $this->command?->info(count($rules).' reglas de tipo documental cargadas.');
    }
}