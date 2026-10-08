<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailJob;
use App\Services\Demo\EmailParser;
use App\Support\StoragePath;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Ingerea los `.eml` de la carpeta de demo por el mismo pipeline que Gmail.
 *
 * Es el camino para demostrar el sistema sin credenciales de Google Cloud.
 */
class DemoIngestCommand extends Command
{
    protected $signature = 'demo:ingest
                            {path? : Carpeta con .eml; por defecto DEMO_INBOX_PATH}
                            {--stack= : Fuerza el stack (a|b)}
                            {--no-run : Solo registra los correos, no despacha el pipeline}';

    protected $description = 'Ingesta correos .eml de demo y los pasa por el pipeline';

    public function handle(EmailParser $parser): int
    {
        // Sin argumento se usa `DEMO_INBOX_PATH`, resuelto por `StoragePath` (igual
        // que los adjuntos). Con argumento, se respeta tal cual venga.
        $path = $this->argument('path') !== null
            ? (string) $this->argument('path')
            : StoragePath::demoInbox();

        $files = is_dir($path)
            ? glob(rtrim($path, '/').'/*.eml') ?: []
            : (is_file($path) ? [$path] : []);

        if ($files === []) {
            $this->components->error("No hay archivos .eml en '{$path}'.");
            $this->components->info(
                'Genera los de ejemplo con: php artisan demo:make-eml'
            );

            return self::FAILURE;
        }

        $stack = $this->option('stack');

        foreach ($files as $file) {
            try {
                $result = $parser->ingest($file);

                $email = $result['email'];

                if ($stack !== null) {
                    $email->forceFill(['pipeline_stack' => $stack])->save();
                }

                $this->components->twoColumnDetail(
                    Str::limit((string) $email->subject, 50),
                    count($result['attachments']).' adjunto(s)'
                        .' · '.($email->pipeline_stack === 'b' ? 'Stack B' : 'Stack A'),
                );

                if (! $this->option('no-run')) {
                    ProcessEmailJob::dispatch($email->uuid);
                }
            } catch (\Throwable $e) {
                $this->components->error(basename($file).': '.$e->getMessage());
            }
        }

        $this->components->info(
            $this->option('no-run')
                ? count($files).' correo(s) registrados. Corre demo:ingest sin --no-run.'
                : count($files).' correo(s) procesados.'
        );

        return self::SUCCESS;
    }
}