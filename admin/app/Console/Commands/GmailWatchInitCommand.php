<?php

namespace App\Console\Commands;

use App\Models\WatchState;
use App\Services\Gmail\GmailAuthMode;
use App\Services\Gmail\GmailClient;
use Illuminate\Console\Command;

/**
 * Registra el watch de Gmail API / Pub/Sub (RF-01).
 *
 * Los watches caducan a los 7 días: hay que correr esto semanalmente.
 */
class GmailWatchInitCommand extends Command
{
    protected $signature = 'gmail:watch:init
                            {--mailbox= : Solo un buzón; por defecto, todos}
                            {--topic= : Nombre del topic de Pub/Sub}';

    protected $description = 'Registra el watch de Gmail para los buzones configurados';

    public function handle(GmailClient $client): int
    {
        $mailboxes = $this->option('mailbox')
            ? [(string) $this->option('mailbox')]
            : $client->mailboxes();

        if ($mailboxes === []) {
            $this->components->error(
                GmailAuthMode::current()->authorizesAMailbox()
                    ? 'No hay ninguna cuenta de Gmail autorizada. Corré `php artisan gmail:auth` primero.'
                    : 'No hay buzones configurados (GOOGLE_MAILBOXES vacío).'
            );

            return self::FAILURE;
        }

        // El modo OAuth se autoriza con `gmail.readonly`, y `users.watch` pide
        // un scope más amplio que el de lectura. Se corta aquí con el motivo
        // escrito en vez de dejar que Google devuelva un 403 de permisos que no
        // dice nada. El modo por defecto del proyecto es polling (`gmail:sync` /
        // `gmail:poll`), que no necesita Pub/Sub.
        if (GmailAuthMode::current()->authorizesAMailbox()) {
            $this->components->error(
                'El modo OAuth está autorizado en solo lectura (gmail.readonly), y '
                .'users.watch necesita un scope más amplio. Para el demo no hace falta: '
                .'el modo por defecto es polling con `php artisan gmail:poll`.'
            );

            return self::FAILURE;
        }

        $topic = (string) ($this->option('topic') ?: env('GMAIL_PUBSUB_TOPIC'));

        if ($topic === '') {
            $this->components->error(
                'Falta el topic de Pub/Sub: usa --topic=projects/TU_PROYECTO/topics/TU_TOPIC '
                .'o define GMAIL_PUBSUB_TOPIC en el .env.'
            );

            return self::FAILURE;
        }

        foreach ($mailboxes as $mailbox) {
            try {
                $historyId = $client->watch($mailbox, $topic);

                WatchState::ensureFor($mailbox)->forceFill([
                    'history_id'  => $historyId,
                    'last_poll_at' => now(),
                    'last_error'  => null,
                ])->save();

                $this->components->twoColumnDetail($mailbox, "historyId={$historyId}");
            } catch (\Throwable $e) {
                $this->components->error("{$mailbox}: ".$e->getMessage());
            }
        }

        $this->components->info(
            'Watches registrados. Caducan en 7 días: vuelve a correr este comando.'
        );

        return self::SUCCESS;
    }
}