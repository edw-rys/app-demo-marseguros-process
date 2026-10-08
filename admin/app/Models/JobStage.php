<?php

namespace App\Models;

use App\Enums\PipelineStage;
use App\Enums\PipelineStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una etapa ejecutada. Append-only: reprocesar agrega filas, no sobrescribe.
 *
 * @property int $id
 * @property int $email_id
 * @property int|null $attachment_id
 * @property string $stage_key
 * @property int $sequence
 * @property string $status
 * @property array|null $detail_json
 */
class JobStage extends Model
{
    use HasFactory;

    protected $table = 'job_stages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detail_json'  => 'array',
            'started_at'   => 'datetime',
            'finished_at'  => 'datetime',
            'duration_ms'  => 'integer',
            'sequence'     => 'integer',
            'run'          => 'integer',
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

    public function stageEnum(): ?PipelineStage
    {
        return PipelineStage::tryFrom($this->stage_key);
    }

    public function statusEnum(): ?PipelineStatus
    {
        return PipelineStatus::tryFrom($this->status);
    }

    public function label(): string
    {
        return $this->stageEnum()?->label() ?? $this->stage_key;
    }

    public function color(): string
    {
        return $this->statusEnum()?->color() ?? 'gray';
    }

    public function durationForHumans(): string
    {
        if ($this->duration_ms === null) {
            return '—';
        }

        return $this->duration_ms < 1000
            ? $this->duration_ms.' ms'
            : round($this->duration_ms / 1000, 2).' s';
    }
}