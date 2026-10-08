<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de tipo documental del Stack A (RF-05).
 *
 * @property array|null $required_keywords
 * @property array|null $keywords
 * @property array|null $filename_hints
 */
class DocTypeRule extends Model
{
    use HasFactory;

    protected $table = 'doc_type_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'required_keywords' => 'array',
            'keywords'          => 'array',
            'filename_hints'    => 'array',
            'active'            => 'boolean',
            'version'           => 'integer',
        ];
    }

    /** Wire format que entiende `POST /analyze` de stack-a. */
    public function toWireFormat(): array
    {
        return [
            'name'             => $this->name,
            'label'            => $this->label ?? $this->name,
            'required_keywords' => $this->required_keywords ?? [],
            'keywords'         => $this->keywords ?? [],
            'filename_hints'   => $this->filename_hints ?? [],
        ];
    }
}