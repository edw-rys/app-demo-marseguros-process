<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla declarativa de Fase 2 del Stack A (RF-07).
 *
 * @property array $json_rule
 * @property string $severity
 * @property array|null $doc_types
 */
class ValidationRule extends Model
{
    use HasFactory;

    protected $table = 'validation_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'json_rule' => 'array',
            'doc_types' => 'array',
            'active'   => 'boolean',
            'version'  => 'integer',
        ];
    }

    /** Wire format que entiende `POST /validate` de stack-a. */
    public function toWireFormat(): array
    {
        return [
            'name'      => $this->name,
            'json_rule' => $this->json_rule,
            'severity'  => $this->severity,
            'doc_types' => $this->doc_types ?? [],
        ];
    }

    /** El `code` y el `message` viven en `event.params`, para no duplicarlos. */
    public function code(): ?string
    {
        return $this->json_rule['event']['params']['code'] ?? null;
    }

    public function messageTemplate(): ?string
    {
        return $this->json_rule['event']['params']['message'] ?? null;
    }
}