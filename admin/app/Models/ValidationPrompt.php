<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Prompt versionado del Stack B (RF-08).
 *
 * @property string $name
 * @property string $prompt_text
 * @property array|null $expected_schema
 */
class ValidationPrompt extends Model
{
    use HasFactory;

    protected $table = 'validation_prompts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expected_schema' => 'array',
            'active'          => 'boolean',
            'version'         => 'integer',
        ];
    }

    /**
     * Prompts activos indexados por nombre, listos para mandarle a `stack-b`.
     *
     * @return array<string, array{prompt_text: string, version: int}>
     */
    public static function activeMap(): array
    {
        return static::query()
            ->where('active', true)
            ->get(['name', 'prompt_text', 'version'])
            ->mapWithKeys(fn (self $p) => [
                $p->name => [
                    'prompt_text' => $p->prompt_text,
                    'version'     => $p->version,
                    'expected_schema' => $p->expected_schema,
                ],
            ])
            ->all();
    }

    /** Subida de versión al editar el texto: deja rastro de qué cambió. */
    public function bumpVersionOnTextChange(): void
    {
        if ($this->isDirty('prompt_text')) {
            $this->version = ($this->version ?? 1) + 1;
        }
    }
}