<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $email_id
 * @property int|null $attachment_id
 * @property string $rule_name
 * @property string $status
 * @property string $severity
 */
class ValidationResult extends Model
{
    use HasFactory;

    protected $table = 'validation_results';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'facts_json' => 'array',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(ProcessedEmail::class, 'email_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    /** Un error de severidad `error` bloquea la aprobación del correo. */
    public function blocksApproval(): bool
    {
        return $this->severity === 'error'
            && in_array($this->status, ['fail', 'error'], true);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pass' => 'success',
            'warn' => 'warning',
            'fail' => 'danger',
            'error' => 'danger',
            default => 'gray',
        };
    }
}