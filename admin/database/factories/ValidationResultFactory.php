<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\ProcessedEmail;
use App\Models\ValidationResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValidationResult>
 */
class ValidationResultFactory extends Factory
{
    protected $model = ValidationResult::class;

    public function definition(): array
    {
        return [
            'email_id'       => ProcessedEmail::factory(),
            'attachment_id'  => null,
            'rule_name'      => 'factura_vigente',
            'status'         => 'pass',
            'severity'       => 'error',
            'code'           => null,
            'message'        => null,
            'facts_json'     => [],
        ];
    }

    /** Regla a nivel de correo: Fase 1 (documentos faltantes). */
    public function atEmailLevel(): static
    {
        return $this->state(fn (): array => ['attachment_id' => null]);
    }

    /** Regla sobre un adjunto concreto. */
    public function forAttachment(Attachment $attachment): static
    {
        return $this->state(fn (): array => ['attachment_id' => $attachment->id]);
    }

    /**
     * @param  'pass'|'fail'|'warn'|'error'  $status
     */
    public function withStatus(string $status, string $severity = 'error'): static
    {
        return $this->state(fn (): array => [
            'status'   => $status,
            'severity' => $severity,
            'message'  => $status === 'pass' ? null : 'La factura tiene más de 90 días.',
        ]);
    }
}