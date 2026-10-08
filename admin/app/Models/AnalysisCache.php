<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache por hash de archivo (F-12).
 *
 * Evita reprocesar y volver a cobrar tokens cuando el mismo archivo se
 * reanida, algo habitual cuando el cliente reenvía un correo completo.
 *
 * @property string $sha256
 * @property string $stack
 * @property array $payload_json
 */
class AnalysisCache extends Model
{
    use HasFactory;

    protected $table = 'analysis_cache';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }

    public static function remember(
        string $sha256,
        string $stack,
        array $payload
    ): void {
        static::query()->updateOrCreate(
            ['sha256' => $sha256, 'stack' => $stack],
            ['payload_json' => $payload],
        );
    }

    public static function recall(string $sha256, string $stack): ?array
    {
        return static::query()
            ->where('sha256', $sha256)
            ->where('stack', $stack)
            ->value('payload_json');
    }

    /**
     * Invalida la cache de un archivo. Lo usa `pipeline:reprocess --clear-cache`
     * cuando el análisis anterior se hizo con reglas que ya cambiaron.
     */
    public static function forget(string $sha256, string $stack): void
    {
        static::query()
            ->where('sha256', $sha256)
            ->where('stack', $stack)
            ->delete();
    }
}