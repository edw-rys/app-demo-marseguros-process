<?php

namespace App\Jobs;

use App\Models\ProcessedEmail;
use App\Services\Pipeline\EmailPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ejecuta el pipeline completo de un correo.
 *
 * El demo corre con `QUEUE_CONNECTION=sync`, así que despachar el job ya lo
 * ejecuta: sirve igual para la demo que con `database` + `queue:work` en real.
 *
 * `tries = 1` a propósito: el pipeline ya es idempotente y se auto-registra en
 * `job_stages`, y reintentar a ciegas duplicaría etapas en el timeline sin
 * arreglar nada. Lo que sí se reintenta es la lectura de Gmail, que no ha
 * empezado todavía cuando el job muere.
 */
class ProcessEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $emailUuid)
    {
        $this->onQueue('pipeline');
    }

    public function handle(): void
    {
        $email = ProcessedEmail::query()->where('uuid', $this->emailUuid)->first();

        if ($email === null) {
            Log::warning('Job sin correo asociado', ['uuid' => $this->emailUuid]);

            return;
        }

        app(EmailPipeline::class, ['email' => $email])->run();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessEmailJob falló', [
            'uuid'  => $this->emailUuid,
            'error' => $e->getMessage(),
        ]);
    }
}