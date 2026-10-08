<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $template
 * @property string $source
 * @property string $body
 * @property string $status
 */
class EmailResponse extends Model
{
    use HasFactory;

    protected $table = 'email_responses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(ProcessedEmail::class, 'email_id');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'sent'    => 'success',
            'failed'  => 'danger',
            // `dry_run` = generado y guardado, pero no enviado (modo demo).
            'dry_run' => 'info',
            default   => 'gray',
        };
    }
}