<?php

namespace App\Enums;

/** Estado de una etapa individual del pipeline. */
enum PipelineStatus: string
{
    case Running  = 'running';
    case Passed   = 'passed';
    case Failed   = 'failed';
    case Skipped  = 'skipped';
    case Degraded = 'degraded';

    public function label(): string
    {
        return match ($this) {
            self::Running  => 'En curso',
            self::Passed   => 'OK',
            self::Failed   => 'Error',
            self::Skipped  => 'Omitida',
            self::Degraded => 'Degradado',
        };
    }

    /** Color de la insignia en Filament. */
    public function color(): string
    {
        return match ($this) {
            self::Running  => 'warning',
            self::Passed   => 'success',
            self::Failed   => 'danger',
            self::Skipped  => 'gray',
            self::Degraded => 'warning',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}