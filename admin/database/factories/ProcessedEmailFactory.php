<?php

namespace Database\Factories;

use App\Models\ProcessedEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessedEmail>
 */
class ProcessedEmailFactory extends Factory
{
    protected $model = ProcessedEmail::class;

    public function definition(): array
    {
        $gmailId = 'demo'.fake()->unique()->numerify('##########');

        return [
            'gmail_id'        => $gmailId,
            'thread_id'       => 'thread-'.$gmailId,
            'mailbox'         => 'docs@demo.local',
            'subject'         => 'Trámite: Póliza nueva — Juan Pérez',
            'sender'          => 'juan.perez@demo.local',
            'sender_name'     => 'Juan Pérez',
            'received_at'     => now()->subMinutes(5),
            'status'          => ProcessedEmail::STATUS_PENDING,
            'pipeline_stack'  => 'a',
            'current_stage'   => 'received',
            'subject_matched' => true,
        ];
    }

    public function forStack(string $stack): static
    {
        return $this->state(fn (): array => ['pipeline_stack' => $stack]);
    }

    public function withSubject(string $subject): static
    {
        return $this->state(fn (): array => ['subject' => $subject]);
    }
}