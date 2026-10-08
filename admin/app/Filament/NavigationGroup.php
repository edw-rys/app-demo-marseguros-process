<?php

namespace App\Filament;

/**
 * Grupos del menú lateral del panel.
 *
 * Filament v5 exige un `UnitEnum` en `$navigationGroup`, así que el grupo se
 * declara una sola vez aquí en vez de repetir el string en cada resource.
 *
 * El orden de los casos es el orden en que aparecen en el menú.
 */
enum NavigationGroup: string
{
    /** Lo que corre: los correos que entraron y su timeline. */
    case Pipeline = 'Pipeline';

    /** Evidencia: lo que el pipeline produjeron (adjuntos, reglas, LLM). */
    case Evidence = 'Evidencia';

    /** Configuración editable en caliente, dividida por stack. */
    case StackA = 'Stack A · OCR local';

    case StackB = 'Stack B · LLM';

    /** Estado del watcher. */
    case Watchers = 'Watchers';

    public function label(): string
    {
        return match ($this) {
            self::Pipeline => 'Pipeline',
            self::Evidence => 'Evidencia',
            self::StackA   => 'Stack A · OCR local',
            self::StackB   => 'Stack B · LLM',
            self::Watchers => 'Watchers',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pipeline => 'heroicon-o-arrow-path',
            self::Evidence => 'heroicon-o-document-magnifying-glass',
            self::StackA   => 'heroicon-o-eye',
            self::StackB   => 'heroicon-o-sparkles',
            self::Watchers => 'heroicon-o-envelope',
        };
    }
}