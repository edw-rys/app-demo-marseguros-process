<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\ProcessedEmail;
use App\Support\StoragePath;
use Illuminate\Support\Str;

/**
 * Descarga y persiste los adjuntos de un correo (RF-02).
 *
 * Nomenclatura de carpeta: `{gmail_id}_{slug_asunto}/`. Ante colisión de
 * nombres se numera `_1`, `_2`, y se escribe un `metadata.json` por correo.
 */
class AttachmentDownloader
{
    /**
     * Descarga los adjuntos del correo y registra cada uno.
     *
     * @return array{saved: string[], warnings: string[], files: Attachment[]}
     */
    public function downloadFor(ProcessedEmail $email): array
    {
        // Si el correo vino del modo demo (.eml), los adjuntos ya están en disco.
        $source = $email->attachments()->count() > 0
            ? ['saved' => $email->attachments()->pluck('filename')->all(), 'warnings' => []]
            : $this->downloadFromGmail($email);

        return $source + ['files' => $email->attachments()->get()->all()];
    }

    /**
     * @return array{saved: string[], warnings: string[]}
     */
    private function downloadFromGmail(ProcessedEmail $email): array
    {
        $service = app(\App\Services\Gmail\GmailClient::class)
            ->serviceFor((string) $email->mailbox);

        $message = $service->users_messages->get(
            'me',
            $email->gmail_id,
            ['format' => 'full'],
        );

        $payload = $message->getPayload();
        $saved = [];
        $warnings = [];
        $usedNames = [];

        foreach ($this->walkParts($payload) as $part) {
            $filename = $part['filename'] ?? null;
            $attachmentId = $part['body']->getAttachmentId();

            // Sin nombre o sin attachmentId: es el cuerpo del correo, no un adjunto.
            if (! $filename || ! $attachmentId) {
                continue;
            }

            // El `attachmentId` va como TERCER ARGUMENTO POSICIONAL, no dentro
            // de `optParams`. La firma real es `get($userId, $messageId, $id,
            // $optParams)` (ver `vendor/google/apiclient-services/.../
            // UsersMessagesAttachments.php:43`), así que mandar
            // `['messagePart' => …]` hace que `$id` reciba un array y Google
            // conteste 400 "Invalid attachment token".
            //
            // El token es de un solo uso y de vida corta: por eso el mensaje se
            // vuelve a pedir arriba con `format=full` en cada descarga, en vez
            // de guardarlo.
            $data = $service->users_messages_attachments->get(
                'me',
                $email->gmail_id,
                $attachmentId,
            );

            $bytes = base64_decode(strtr((string) $data->getData(), '-_', '+/'), true);
            if ($bytes === false) {
                $warnings[] = "No se pudo decodificar '{$filename}'.";
                continue;
            }

            $size = strlen($bytes);

            // RF-02: > 25 MB genera warning pero NO impide la descarga.
            if ($size > config('gmail_docs.attachments.size_warning_bytes')) {
                $warnings[] = "'{$filename}' supera los 25 MB ({$size} bytes).";
            }

            $path = $this->writeToDisk($email, $filename, $bytes, $usedNames);
            $saved[] = $path['filename'];

            $this->persist($email, $path['filename'], $path['path'], $bytes);
        }

        $this->writeMetadata($email, $saved, $warnings);

        return ['saved' => $saved, 'warnings' => $warnings];
    }

    /**
     * Recorre recursivamente el árbol de partes de MIME.
     *
     * Gmail anida los adjuntos: un PDF con portada llega como un `multipart/mixed`
     * cuyo hijo es el adjunto. Hay que bajar hasta el fondo.
     *
     * @return iterable<array<string, mixed>>
     */
    private function walkParts(?object $payload): iterable
    {
        if ($payload === null) {
            return;
        }

        $part = [
            'partId'   => $payload->getPartId(),
            'mimeType' => $payload->getMimeType(),
            'filename' => $payload->getFilename(),
            'body'     => $payload->getBody(),
        ];

        if ($part['filename']) {
            yield $part;
        }

        foreach ($payload->getParts() ?? [] as $child) {
            yield from $this->walkParts($child);
        }
    }

    /**
     * Escribe el archivo en disco evitando colisiones de nombre.
     *
     * @param  array<string, bool>  $usedNames
     * @return array{filename: string, path: string}
     */
    private function writeToDisk(
        ProcessedEmail $email,
        string $filename,
        string $bytes,
        array &$usedNames
    ): array {
        $directory = $this->directoryFor($email);
        $safe = $this->sanitize($filename);
        $candidate = $safe;
        $counter = 1;

        while (isset($usedNames[strtolower($candidate)])) {
            $candidate = Str::beforeLast($safe, '.').'_'.$counter
                .(Str::contains($safe, '.') ? '.'.Str::afterLast($safe, '.') : '');
            $counter++;
        }

        $usedNames[strtolower($candidate)] = true;

        // Se escribe con `file_put_contents` y no con el disk de Laravel: la
        // ruta puede ser absoluta (Docker la monta como volumen compartido) y
        // `Storage::disk('local')` la prefijaría con `storage/app/private`.
        $absolute = $directory.'/'.$candidate;
        $this->ensureDirectoryExists($directory);
        file_put_contents($absolute, $bytes);

        return ['filename' => $candidate, 'path' => $absolute];
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function persist(
        ProcessedEmail $email,
        string $filename,
        string $absolutePath,
        string $bytes
    ): Attachment {
        return Attachment::query()->firstOrCreate([
            'email_id' => $email->id,
            'filename' => $filename,
        ], [
            'local_path'  => $absolutePath,
            'sha256'      => hash('sha256', $bytes),
            'size_bytes'  => strlen($bytes),
        ]);
    }

    /**
     * Ruta ABSOLUTA de la carpeta del correo: `{gmail_id}_{slug_asunto}/` — RF-02.
     *
     * Es la que va también en `attachments.local_path` y es la que leen los
     * workers Python, así que tiene que ser la misma cadena en los tres
     * contenedores.
     */
    public function directoryFor(ProcessedEmail $email): string
    {
        $slug = Str::slug((string) $email->subject) ?: 'sin-asunto';

        return StoragePath::attachments().'/'.$email->gmail_id.'_'.$slug;
    }

    /** `metadata.json` por correo (RF-02). */
    private function writeMetadata(ProcessedEmail $email, array $saved, array $warnings): void
    {
        $directory = $this->directoryFor($email);

        $this->ensureDirectoryExists($directory);

        file_put_contents($directory.'/metadata.json', json_encode([
            'gmail_id'         => $email->gmail_id,
            'thread_id'        => $email->thread_id,
            'mailbox'          => $email->mailbox,
            'subject'          => $email->subject,
            'sender'           => $email->sender,
            'received_at'      => $email->received_at?->toIso8601String(),
            'attachment_count' => count($saved),
            'attachments'      => $saved,
            'warnings'         => $warnings,
            'downloaded_at'    => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Quita separadores de ruta y caracteres problemáticos del nombre original. */
    private function sanitize(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name) ?? 'archivo';

        return Str::limit($name, 180, '');
    }
}