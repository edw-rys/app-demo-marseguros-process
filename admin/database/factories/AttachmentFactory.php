<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\ProcessedEmail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2).'.pdf';

        return [
            'email_id'       => ProcessedEmail::factory(),
            'filename'       => $name,
            'local_path'     => storage_path('app/attachments/'.$name),
            'sha256'         => hash('sha256', $name),
            'size_bytes'     => fake()->numberBetween(2_000, 400_000),
            'detected_kind'  => null,
            'doc_type'       => null,
            'confidence'     => null,
            'extracted_text' => null,
        ];
    }

    public function forEmail(ProcessedEmail $email): static
    {
        return $this->state(fn (): array => ['email_id' => $email->id]);
    }

    /**
     * Adjunto ya analizado: el atajo que usan los tests de Fase 2, donde lo
     * interesante es la clasificación y no la llamada al worker.
     */
    public function classified(string $docType, array $fields = [], float $confidence = 0.9): static
    {
        return $this->state(fn (): array => [
            'doc_type'    => $docType,
            'confidence'  => $confidence,
            'fields_json' => $fields ?: [Str::random(6) => Str::random(8)],
        ]);
    }
}