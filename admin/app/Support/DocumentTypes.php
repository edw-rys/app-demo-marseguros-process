<?php

namespace App\Support;

use App\Models\DocTypeRule;

/**
 * Títulos legibles de los tipos documentales.
 *
 * `required_documents` y `attachments.doc_type` guardan KEYS (`solicitud`,
 * `ine`, `comprobante_domicilio`), porque son las claves que el clasificador
 * escribe y contra las que se comparan. Keys son lo correcto para comparar y lo
 * incorrecto para mostrar: «Faltan: solicitud, ine» no le dice nada a quien
 * atendió el correo.
 *
 * Este es el único lugar donde se traduce, y la fuente de verdad del título es
 * `doc_type_rules.label` — la misma fila que el admin edita en Reglas por tipo.
 * Si un tipo no tiene regla (o la tabla no existe todavía, p. ej. durante una
 * migración) se cae a la reserva de acá, que es mejor que la key cruda pero no
 * es un título de verdad.
 */
final class DocumentTypes
{
    /** Solo como reserva: tipos que no están (o no estaban) en `doc_type_rules`. */
    private const FALLBACK_LABELS = [
        'factura'                => 'Factura',
        'solicitud'              => 'Solicitud',
        'ine'                    => 'Cédula de identidad',
        'comprobante_domicilio'  => 'Comprobante de domicilio',
        'poliza_vigente'         => 'Póliza vigente',
        'poliza_nueva'           => 'Póliza nueva',
        'rfc'                    => 'Constancia de situación fiscal',
        'desconocido'            => 'Sin clasificar',
    ];

    /**
     * Una consulta por tipo documental, por request. Las reglas no cambian
     * dentro de un request y las vistas las piden en bucle (una por chip, una
     * por adjunto), así que sin esto cada chip sería un `SELECT`.
     *
     * @var array<string, string>|null
     */
    private static ?array $cache = null;

    /**
     * Título de un key. Sin acentos ni mayúsculas, como un nombre normal.
     *
     * La reserva final es humanizar la key: `comprobante_piso` sale «Comprobante
     * piso», que al menos se lee. Devolver la key cruda sería exactamente el
     * problema que esta clase viene a evitar.
     */
    public static function label(?string $docType): string
    {
        if (blank($docType)) {
            return 'Sin clasificar';
        }

        $docType = mb_strtolower(trim($docType));

        if ($docType === 'desconocido') {
            return 'Sin clasificar';
        }

        $fromRules = self::labelsFromRules();

        if (isset($fromRules[$docType])) {
            return $fromRules[$docType];
        }

        if (isset(self::FALLBACK_LABELS[$docType])) {
            return self::FALLBACK_LABELS[$docType];
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

    /**
     * `doc_type_rules` indexada por nombre, o `[]` si la tabla no está.
     *
     * El `try/catch` no es paranoia: `label()` se llama desde vistas y desde el
     * pipeline, y durante `migrate:fresh` la tabla existe pero vacía, mientras
     * que en un seed en curso puede no existir todavía. Que un tipo no tenga
     * título arriba no puede tumbar el job.
     *
     * @return array<string, string>
     */
    private static function labelsFromRules(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            self::$cache = DocTypeRule::query()
                ->pluck('label', 'name')
                ->filter(fn (?string $label, string $name): bool => filled($label) && $name !== 'desconocido')
                ->map(fn (string $label): string => trim($label))
                ->all();
        } catch (\Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }

    /**
     * Vacía la caché de títulos.
     *
     * En producción no hace falta (los labels no cambian dentro de un request),
     * pero entre tests sí: `RefreshDatabase` borra `doc_type_rules` y sin esto
     * el siguiente test heredaría los labels del anterior y pasaría (o fallaría)
     * por un motivo que no es el suyo.
     */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}