<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Regex de extracción de campos del Stack A (RF-06).
 *
 * @property string $name
 * @property string $regex
 */
class FieldPattern extends Model
{
    use HasFactory;

    protected $table = 'field_patterns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active'  => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * Wire format: `stack-a` espera un dict `nombre => regex`.
     *
     * @return array<string, string>
     */
    public static function activeMap(): array
    {
        return static::query()
            ->where('active', true)
            ->pluck('regex', 'name')
            ->all();
    }

    /**
     * Valida el regex contra PCRE antes de guardarlo.
     *
     * Se valida en PHP por una razón práctica: si el regex compila en PHP pero
     * no en Python (o al revés), el autor se entera en el formulario y no
     * después, cuando un documento falla en silencio.
     */
    public static function isValidRegex(?string $regex): bool
    {
        if ($regex === null || $regex === '') {
            return false;
        }

        // Se prueba con delimitadores de PCRE y con flags comparables a los que
        // usa Python (IGNORECASE | MULTILINE).
        return @preg_match('~'.$regex.'~imu', '') !== false;
    }
}