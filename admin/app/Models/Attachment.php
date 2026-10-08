<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property string $filename
 * @property string $local_path
 * @property string $sha256
 * @property string|null $detected_kind
 * @property bool $extension_mismatch
 * @property string|null $doc_type
 * @property float|null $confidence
 * @property array|null $fields_json
 * @property string|null $extracted_text
 */
class Attachment extends Model
{
    use HasFactory;

    protected $table = 'attachments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'extension_mismatch' => 'boolean',
            'ocr_used'           => 'boolean',
            'confidence'         => 'float',
            'ocr_confidence'     => 'float',
            'fields_json'        => 'array',
            'key_signals'        => 'array',
            'score_breakdown'    => 'array',
            'size_bytes'         => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attachment) {
            $attachment->uuid ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(ProcessedEmail::class, 'email_id');
    }

    /** Etapas que se ejecutaron sobre este adjunto concreto. */
    public function stages()
    {
        return $this->hasMany(JobStage::class, 'attachment_id')->orderBy('sequence');
    }

    /** Si el archivo existe todavía en disco (puede haberse purgado). */
    public function fileExists(): bool
    {
        return is_file($this->local_path);
    }

    public function sizeForHumans(): string
    {
        $bytes = (int) $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}