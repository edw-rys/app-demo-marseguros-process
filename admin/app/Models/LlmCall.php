<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traza de auditoría de una llamada al LLM (RNF-04).
 *
 * Se persiste request completo, respuesta cruda y respuesta parseada para que
 * cualquier decisión sea reconstruible después.
 *
 * @property string $purpose
 * @property string $model
 * @property float $cost_usd
 */
class LlmCall extends Model
{
    use HasFactory;

    protected $table = 'llm_calls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cache_hit'          => 'boolean',
            'degraded'           => 'boolean',
            'prompt_tokens'      => 'integer',
            'completion_tokens'  => 'integer',
            'cost_usd'           => 'float',
            'latency_ms'         => 'integer',
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

    /** Costo acumulado del día, para el widget de presupuesto (RNF-07). */
    public static function costToday(): float
    {
        return (float) static::query()
            ->whereDate('created_at', today())
            ->sum('cost_usd');
    }

    public static function tokensToday(): int
    {
        return (int) static::query()
            ->whereDate('created_at', today())
            ->sum(\Illuminate\Support\Facades\DB::raw('prompt_tokens + completion_tokens'));
    }
}