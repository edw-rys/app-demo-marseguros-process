<?php

namespace App\Support;

/**
 * Títulos legibles de los tipos documentales.
 *
 * `required_documents` y `attachments.doc_type` guardan KEYS (`solicitud`,
 * `ine`, `comprobante_domicilio`), porque son las claves que el clasificador
 * escribe y contra las que se comparan. Keys son lo correcto para comparar y lo
 * incorrecto para mostrar: «Faltan: solicitud, ine» no le dice nada a quien
 * atendió el correo.
 *
 * Este es el único lugar donde se traduce. Si se agrega un key a
 * `gmail_docs.required_documents` hay que agregarlo acá, o `label()` devuelve
 * la key humanizada como reserva — que es mejor que la key cruda, pero no es un
 * título.
 */
final class DocumentTypes
{
    private const LABELS = [
        'factura'                => 'Factura',
        'solicitud'              => 'Solicitud',
        'ine'                    => 'Identificación (INE)',
        'comprobante_domicilio'  => 'Comprobante de domicilio',
        'poliza_vigente'         => 'Póliza vigente',
        'poliza_nueva'           => 'Póliza nueva',
        'desconocido'            => 'Sin clasificar',
    ];

    /**
     * Título de un key. Sin acentos ni mayúsculas, como un nombre normal.
     *
     * La reserva es humanizar la key: `comprobante_piso` sale «Comprobante
     * piso», que al menos se lee. Devolver la key cruda sería exactamente el
     * problema que esta clase viene a evitar.
     */
    public static function label(?string $docType): string
    {
        if (blank($docType)) {
            return 'Sin clasificar';
        }

        $docType = mb_strtolower(trim($docType));

        if (isset(self::LABELS[$docType])) {
            return self::LABELS[$docType];
        }

        // `ucfirst` sobre la parte legible de la key: se corta en el `_` para
        // que «identificacion_ine» dé «Identificacion ine» y no
        // «Identificacion_ine».
        $words = str_replace('_', ' ', $docType);

        return mb_convert_case($words, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Varios títulos, para los mensajes del pipeline.
     *
     * @param  iterable<int, string|null>  $docTypes
     * @return array<int, string>
     */
    public static function labels(iterable $docTypes): array
    {
        $labels = [];

        foreach ($docTypes as $docType) {
            $labels[] = self::label($docType);
        }

        return array_values(array_unique($labels));
    }
}