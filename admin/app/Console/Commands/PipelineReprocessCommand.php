<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailJob;
use App\Models\AnalysisCache;
use App\Models\ProcessedEmail;
use Illuminate\Console\Command;

/**
 * Reprocesa un job desde Filament o desde consola.
 *
 * `job_stages` es append-only: reprocesar NO borra el timeline anterior, lo
 * duplica. Eso es deliberado — el timeline es la auditoría, y un reintento que
 * borrara el intento fallido dejaría sin rastro por qué se reprocesó.
 */
class PipelineReprocessCommand extends Command
{
    protected $signature = 'pipeline:reprocess
                            {email : uuid del correo}
                            {--stack= : Cambia el stack antes de correr (a|b)}
                            {--clear-cache : Ignora la cache por hash de los adjuntos}';

    protected $description = 'Vuelve a correr el pipeline de un correo, conservando el historial de etapas';

    public function handle(): int
    {
        $uuid = (string) $this->argument('email');

        $email = ProcessedEmail::query()->where('uuid', $uuid)->first();

        if ($email === null) {
            $this->components->error("No existe el correo con uuid '{$uuid}'.");

            return self::FAILURE;
        }

        if ($this->option('stack')) {
            $email->forceFill(['pipeline_stack' => $this->option('stack')])->save();
        }

        if ($this->option('clear-cache')) {
            $email->attachments()->pluck('sha256')
                ->each(fn (string $sha) => AnalysisCache::forget($sha, $email->pipeline_stack));
        }

        $previous = $email->stages()->count();

        $this->components->twoColumnDetail('Correo', $email->subject ?? '(sin asunto)');
        $this->components->twoColumnDetail('Stack', $email->pipeline_stack);
        $this->components->twoColumnDetail('Etapas previas', (string) $previous);

        ProcessEmailJob::dispatch($email->uuid);

        $email->refresh();

        $this->components->info(
            "Reprocesado. Estado final: '{$email->status}', "
            ."etapas registradas ahora: ".$email->stages()->count().'.'
        );

        // Si el pipeline terminó en `failed`, el comando NO devuelve error:
        // un documento inválido es un resultado esperado del negocio, no un
        // fallo de la herramienta. El exit code se reserva para no encontrar
        // el correo.
        return self::SUCCESS;
    }
}