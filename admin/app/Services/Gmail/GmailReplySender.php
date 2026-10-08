<?php

namespace App\Services\Gmail;

use App\Models\ProcessedEmail;
use Illuminate\Support\Facades\Log;

/**
 * Envía la respuesta automática como respuesta en el hilo original (RF-09).
 *
 * Nunca se manda un correo nuevo: se responde con `threadId` + `In-Reply-To`,
 * que es lo que exige el criterio de aceptación de RF-09 para no perder el
 * contexto de la conversación.
 */
class GmailReplySender
{
    public function __construct(private readonly GmailClient $client)
    {
    }

    /**
     * @return array{message_id: string, thread_id: string}
     */
    public function send(ProcessedEmail $email, string $body): array
    {
        // Con un scope de solo lectura esto falla aquí, con un mensaje que dice
        // qué hacer, en vez de un 403 de permisos de scopes dentro de Gmail que
        // no dice nada. El pipeline envuelve el envío en un catch y deja este
        // texto en `email_responses`, que es justo lo que hay que ver en el admin.
        $this->client->assertCanSend();

        $service = $this->client->serviceFor((string) $email->mailbox);

        $threadId = (string) $email->thread_id;
        $original = $service->users_messages->get('me', $threadId, [
            'format' => 'metadata',
            'metadataHeaders' => ['Message-ID', 'Subject', 'References'],
        ]);

        $originalHeaders = [];
        foreach ($original->getPayload()?->getHeaders() ?? [] as $header) {
            $originalHeaders[strtolower((string) $header->getName())] = $header->getValue();
        }

        $replyTo = config('gmail_docs.reply_as') ?: $email->mailbox;
        $subject = $this->replySubject((string) ($originalHeaders['subject'] ?? $email->subject));

        $message = $service->users_messages->send('me', [
            'raw' => $this->buildRaw(
                to: (string) $email->sender,
                from: (string) $replyTo,
                subject: $subject,
                body: $body,
                inReplyTo: $originalHeaders['message-id'] ?? null,
                references: $originalHeaders['references'] ?? ($originalHeaders['message-id'] ?? null),
                threadId: $threadId,
            ),
            'threadId' => $threadId,
        ]);

        Log::info('Respuesta enviada', [
            'email_id' => $email->id,
            'message_id' => $message->getId(),
        ]);

        return [
            'message_id' => (string) $message->getId(),
            'thread_id'  => (string) $message->getThreadId(),
        ];
    }

    /** Re: solo si no lo tenía ya, como manda la convención de correo. */
    private function replySubject(string $subject): string
    {
        $subject = trim($subject);

        return str_starts_with(strtolower($subject), 're:') ? $subject : 'Re: '.$subject;
    }

    /**
     * Construye el RFC 822 crudo.
     *
     * Se arma a mano y no con la clase `Message` de Gmail porque necesitamos
     * control exacto de `In-Reply-To` y `References`, que son los que hacen que
     * el cliente agrupe la respuesta en el hilo.
     */
    private function buildRaw(
        string $to,
        string $from,
        string $subject,
        string $body,
        ?string $inReplyTo,
        ?string $references,
        string $threadId,
    ): string {
        $headers = [
            'To: '.$to,
            'From: '.$from,
            'Subject: '.$this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
        ];

        if ($inReplyTo) {
            $headers[] = 'In-Reply-To: '.$inReplyTo;
        }

        if ($references) {
            $headers[] = 'References: '.$references;
        }

        return implode("\r\n", $headers)
            ."\r\n\r\n"
            .quoted_printable_encode($body);
    }

    /** Los acentos del asunto tienen que ir codificados o Gmail los rechaza. */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?'.base64_encode($value).'?=';
    }
}