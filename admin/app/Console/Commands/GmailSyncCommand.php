<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailJob;
use App\Models\ProcessedEmail;
use App\Services\Gmail\GmailAuthMode;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\MailboxSynchronizer;
use Illuminate\Console\Command;

/**
 * Un poll de todos los buzones y encola el pipeline de lo que haya entrado.
 *
 * Es el comando que se ejecuta en la demo: `php artisan gmail:sync`.
 */
class GmailSyncCommand extends Command
{
    protected $signature = 'gmail:sync
                            {--stack= : Fuerza el stack (a|b) para los correos nuevos}
                            {--dry-run : Solo muestra qué se detectaría, no despacha jobs}
                            {--skip-if-unconfigured : Salida limpia y silenciosa si Gmail no está listo}';

    protected $description = 'Sincroniza los buzones de GOOGLE_MAILBOXES y procesa los correos nuevos';

    public function handle(MailboxSynchronizer $synchronizer): int
    {
        if (! GmailClient::isConfigured()) {
            // Modo silencioso: es el que usa el bucle de `gmail-poll` en
            // supervisord. Sin esto, un contenedor recién levantado sin
            // credenciales escribiría el mismo error de configuración cada 120 s para
            // siempre, y el log deja de servir para diagnosticar nada.
            //
            // La diferencia con correrlo a mano es solo la verbosidad: el
            // código de salida es el mismo, `FAILURE`, así que un script que
            // dependa de él no cambia de comportamiento.
            if ($this->option('skip-if-unconfigured')) {
                return self::FAILURE;
            }

            $this->components->error(
                GmailAuthMode::current()->authorizesAMailbox()
                    ? 'Gmail no está autorizado. Corré `php artisan gmail:auth` y abrí la '
                        .'URL que imprime (o usá el botón de Conexión con Gmail en el admin).'
                    : 'Gmail no está configurado. Revisa GOOGLE_SERVICE_ACCOUNT_PATH, '
                        .'GOOGLE_DELEGATED_USER y GOOGLE_MAILBOXES en el .env '
                        .'(ver docs/GOOGLE_CLOUD_SETUP.md).'
            );
            $this->components->info(
                'Para probar el pipeline sin credenciales: php artisan demo:ingest'
            );

            return self::FAILURE;
        }

        $result = $synchronizer->sync();

        $this->components->twoColumnDetail('Correos vistos', (string) $result['scanned']);
        $this->components->twoColumnDetail('Pasaron el filtro', (string) $result['matched']);

        foreach ($result['errors'] as $error) {
            $this->components->warn($error);
        }

        if ($result['created'] === []) {
            $this->components->info('No hay correos nuevos que procesar.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($result['created'] as $uuid) {
                $this->components->twoColumnDetail('Detectado', $uuid);
            }

            $this->components->info('Dry-run: no se despachó ningún job.');

            return self::SUCCESS;
        }

        $stack = $this->option('stack');

        foreach ($result['created'] as $uuid) {
            $email = ProcessedEmail::query()->where('uuid', $uuid)->firstOrFail();

            if ($stack !== null) {
                $email->forceFill(['pipeline_stack' => $stack])->save();
            }

            ProcessEmailJob::dispatch($email->uuid);

            $this->components->twoColumnDetail(
                $email->pipeline_stack === 'b' ? 'Stack B' : 'Stack A',
                $email->subject ?? '(sin asunto)',
            );
        }

        $this->components->info(count($result['created']).' job(s) procesados.');

        return self::SUCCESS;
    }
}