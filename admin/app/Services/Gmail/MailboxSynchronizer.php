<?php

namespace App\Services\Gmail;

use App\Models\ProcessedEmail;
use App\Models\WatchState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recorre los buzones de `GOOGLE_MAILBOXES`, aplica el filtro de asunto (RF-02)
 * y encola los correos que pasan (RF-01).
 *
 * El `historyId` se persiste en `watch_states` antes de procesar nada, para que
 * un fallo a mitad de un buzón no pierda los correos ya vistos.
 */
class MailboxSynchronizer
{
    public function __construct(private readonly GmailClient $client)
    {
    }

    /**
     * @return array{scanned: int, matched: int, created: string[], errors: string[]}
     */
    public function sync(): array
    {
        // La lista de buzones la decide `GmailClient`, no la config: en DHD es
        // `GOOGLE_MAILBOXES` y en OAuth es la cuenta que se autorizó. Quien
        // necesita la lista no debería tener que saber qué modo hay.
        $mailboxes = $this->client->mailboxes();

        if ($mailboxes === []) {
            // El mensaje tiene que decir QUÉ falta según el modo: en OAuth no
            // hay un `GOOGLE_MAILBOXES` que vaciar, hay una cuenta que autorizar.
            $error = GmailAuthMode::current()->authorizesAMailbox()
                ? 'No hay ninguna cuenta de Gmail autorizada. Abrí Conexión con Gmail '
                    .'en el admin, o corré `php artisan gmail:auth`.'
                : 'GOOGLE_MAILBOXES está vacío en el .env';

            return [
                'scanned' => 0, 'matched' => 0, 'created' => [],
                'errors'  => [$error],
            ];
        }

        $result = ['scanned' => 0, 'matched' => 0, 'created' => [], 'errors' => []];

        foreach ($mailboxes as $mailbox) {
            try {
                $outcome = $this->syncMailbox($mailbox);

                $result['scanned'] += $outcome['scanned'];
                $result['matched'] += $outcome['matched'];
                $result['created'] = array_merge($result['created'], $outcome['created']);
            } catch (\Throwable $e) {
                Log::error('Fallo sincronizando buzón', [
                    'mailbox' => $mailbox,
                    'error'   => $e->getMessage(),
                ]);

                $result['errors'][] = "{$mailbox}: ".$e->getMessage();

                WatchState::ensureFor($mailbox)
                    ->forceFill(['last_error' => $e->getMessage()])
                    ->save();
            }
        }

        return $result;
    }

    /**
     * @return array{scanned: int, matched: int, created: string[]}
     */
    private function syncMailbox(string $mailbox): array
    {
        $state = WatchState::ensureFor($mailbox);

        $history = $this->client->listNewMessages($mailbox, $state->history_id);

        // El cursor avanza siempre, matched o no.
        $state->forceFill([
            'history_id'  => $history['history_id'],
            'last_poll_at' => now(),
            'last_error'  => null,
        ])->save();

        $created = [];

        foreach ($history['messages'] as $message) {
            $headers = $this->client->headers($mailbox, $message['id']);

            if (! $this->subjectMatches($headers['subject'])) {
                // RF-02: no pasa el filtro, no se registra. Queda en la traza
                // del buzón, no en la BD, para no llenarla de ruido.
                continue;
            }

            $email = $this->registerEmail($mailbox, $message, $headers);

            if ($email !== null) {
                $created[] = $email->uuid;
            }
        }

        return [
            'scanned' => count($history['messages']),
            'matched' => count($created),
            'created' => $created,
        ];
    }

    /**
     * Filtro por asunto: match PARCIAL e insensible a mayúsculas (RF-02).
     *
     * Vacío = se acepta todo, que es lo útil para arrancar.
     */
    public function subjectMatches(?string $subject): bool
    {
        $filters = config('gmail_docs.subject_filter');

        if ($filters === []) {
            return true;
        }

        $subject = Str::lower((string) $subject);

        foreach ($filters as $filter) {
            if (Str::contains($subject, Str::lower($filter))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Crea el registro del correo. Devuelve `null` si ya existía.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $headers
     */
    private function registerEmail(
        string $mailbox,
        array $message,
        array $headers
    ): ?ProcessedEmail {
        if (ProcessedEmail::query()->where('gmail_id', $message['id'])->exists()) {
            return null;
        }

        return ProcessedEmail::query()->create([
            'gmail_id'        => $message['id'],
            'thread_id'       => $headers['thread_id'] ?? $message['thread_id'] ?? null,
            'mailbox'         => $mailbox,
            'subject'         => $headers['subject'],
            'sender'          => $headers['from'],
            'sender_name'     => $headers['from_name'],
            'snippet'         => $headers['snippet'],
            'received_at'     => $headers['date']
                ? Carbon::parse($headers['date'])
                : now(),
            'status'          => ProcessedEmail::STATUS_PENDING,
            'pipeline_stack'  => (string) config('gmail_docs.default_stack'),
            'current_stage'   => 'received',
            'subject_matched' => true,
            'metadata_json'   => ['raw_headers' => $headers],
        ]);
    }
}