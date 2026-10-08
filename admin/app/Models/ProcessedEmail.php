<?php

namespace App\Models;

use App\Enums\PipelineStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un correo procesado. Raíz de todo el árbol de auditoría.
 *
 * @property int $id
 * @property string $uuid
 * @property string $gmail_id
 * @property string|null $thread_id
 * @property string|null $mailbox
 * @property string|null $subject
 * @property string|null $sender
 * @property string|null $sender_name
 * @property string $status
 * @property string $pipeline_stack
 * @property string $current_stage
 */
class ProcessedEmail extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_VALIDATED  = 'validated';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_REVIEW     = 'review';

    /** Estados que cortan el pipeline: el job ya no avanza. */
    public const TERMINAL_STATUSES = [
        self::STATUS_VALIDATED,
        self::STATUS_FAILED,
        self::STATUS_REVIEW,
    ];

    protected $table = 'processed_emails';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at'    => 'datetime',
            'metadata_json'  => 'array',
            'subject_matched' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $email) {
            $email->uuid ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    /** Route binding por uuid: la API nunca expone el id numérico. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * ¿El pipeline terminó, haya terminado bien o mal?
     *
     * Lo necesita el SSE (`JobStreamController`) para saber cuándo cerrar el
     * stream: sin esto habría que esperar a ver la etapa `done`, que en un job
     * fallido nunca se registra.
     *
     * `failed` y `review` cuentan como terminado a propósito: un job que pide
     * revisión humana ya no va a escribir más etapas por sí solo.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function attachments(): HasMany
    {
        // La FK se declara explícita: `ProcessedEmail` se convierte a
        // `processed_email` en snake_case, que no es el nombre de la columna.
        return $this->hasMany(Attachment::class, 'email_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(JobStage::class, 'email_id')->orderBy('sequence');
    }

    public function validationResults(): HasMany
    {
        return $this->hasMany(ValidationResult::class, 'email_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(EmailResponse::class, 'email_id');
    }

    public function llmCalls(): HasMany
    {
        return $this->hasMany(LlmCall::class, 'email_id');
    }

    public function stageEnum(): ?PipelineStage
    {
        return PipelineStage::tryFrom($this->current_stage);
    }

    /**
     * Documentos efectivamente clasificados, agrupados por tipo.
     * Es la entrada de la Fase 1 (conteo de requeridos).
     *
     * @return array<string, int> tipo => cantidad
     */
    public function classifiedCounts(): array
    {
        return $this->attachments()
            ->whereNotNull('doc_type')
            ->selectRaw('doc_type, count(*) as total')
            ->groupBy('doc_type')
            ->pluck('total', 'doc_type')
            ->all();
    }

    public function hasErrors(): bool
    {
        return $this->validationResults()
            ->whereIn('status', ['fail', 'error'])
            ->where('severity', 'error')
            ->exists();
    }

    /** Duración total: de la primera etapa a la última. */
    public function totalDurationMs(): ?int
    {
        $started = $this->stages()->min('started_at');
        $finished = $this->stages()->max('finished_at');

        if (! $started || ! $finished) {
            return null;
        }

        return (int) round(
            \Illuminate\Support\Carbon::parse($started)
                ->diffInMilliseconds(\Illuminate\Support\Carbon::parse($finished))
        );
    }
}