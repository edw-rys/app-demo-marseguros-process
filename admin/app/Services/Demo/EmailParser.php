<?php

namespace App\Services\Demo;

use App\Models\Attachment;
use App\Models\ProcessedEmail;
use App\Support\StoragePath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lee un `.eml` de la carpeta de demo y crea el `processed_emails` con sus
 * adjuntos ya en disco.
 *
 * Sirve para demostrar el pipeline completo sin credenciales de Google: el
 * correo entra por el mismo camino que en producción (una fila en
 * `processed_emails` + `attachments`), así que el resto del pipeline no
 * distingue un correo de demo de uno real.
 *
 * El parser es mínimo a propósito: cubre `multipart/mixed`, `multipart/related`
 * y `multipart/alternative` con codificación base64 o quoted-printable, que es
 * lo que exportan Gmail, Thunderbird y Apple Mail.
 */
class EmailParser
{
    /** @var array<int, array{filename: string, mime: string, bytes: string}> */
    private array $attachments = [];

    private string $text = '';

    /**
     * @return array{email: ProcessedEmail, attachments: Attachment[]}
     */
    public function ingest(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("No se pudo leer '{$path}'.");
        }

        $this->attachments = [];
        $this->text = '';

        $entity = $this->parseEntity(str_replace("\r\n", "\n", $raw));
        $headers = $entity['headers'];

        $email = $this->createEmail($headers, $path);

        $stored = [];
        foreach ($this->attachments as $part) {
            $stored[] = $this->store($email, $part);
        }

        $email->forceFill(['snippet' => Str::limit(preg_replace('/\s+/', ' ', strip_tags($this->text)) ?? '', 300)])
            ->save();

        return ['email' => $email, 'attachments' => $stored];
    }

    // ── MIME ─────────────────────────────────────────────────────────────────

    /**
     * Parsea una entidad MIME completa (cabeceras + línea en blanco + cuerpo) y
     * acumula sus adjuntos en `$this->attachments`.
     *
     * @return array{headers: array<string, string>, body: string, mime: string}
     */
    private function parseEntity(string $raw): array
    {
        $split = explode("\n\n", $raw, 2);
        $headers = $this->parseHeaders($split[0]);
        $body = $split[1] ?? '';

        $mime = $headers['content-type'] ?? 'text/plain; charset=utf-8';
        $decoded = $this->decodeBody($body, $headers['content-transfer-encoding'] ?? '7bit');

        $boundary = $this->boundary($mime);

        if ($boundary !== null) {
            foreach (explode('--'.$boundary, $decoded) as $chunk) {
                $chunk = trim($chunk, "\n");

                if ($chunk === '' || str_starts_with($chunk, '--')) {
                    continue;
                }

                $this->parseEntity($chunk);
            }

            return ['headers' => $headers, 'body' => '', 'mime' => $mime];
        }

        $filename = $this->filename($headers, $mime);

        if ($filename !== null) {
            $this->attachments[] = [
                'filename' => $filename,
                'mime'     => trim(explode(';', $mime)[0]),
                'bytes'    => $decoded,
            ];
        } elseif ($this->text === ''
            && str_starts_with(strtolower(trim(explode(';', $mime)[0])), 'text/plain')) {
            // Solo el primer texto plano sirve como snippet: la alternativa en
            // HTML viene después y no aporta nada al resumen.
            $this->text = $decoded;
        }

        return ['headers' => $headers, 'body' => $body, 'mime' => $mime];
    }

    /**
     * @return array<string, string> nombre en minúsculas => valor (último gana)
     */
    private function parseHeaders(string $block): array
    {
        $headers = [];

        // Los headers continuados llevan espacio al inicio: se pliegan primero.
        $block = preg_replace('/\n[ \t]+/', ' ', $block) ?? $block;

        foreach (explode("\n", $block) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    private function boundary(string $mime): ?string
    {
        return preg_match('/boundary="?([^";\s]+)"?/i', $mime, $matches) === 1
            ? $matches[1]
            : null;
    }

    /** El nombre del archivo va en `Content-Disposition` o en el `name=` del content-type. */
    private function filename(array $headers, string $mime): ?string
    {
        if (preg_match('/filename\*?="?([^";\n]+)"?/i', $headers['content-disposition'] ?? '', $matches) === 1) {
            return $this->decodeHeader(trim($matches[1]));
        }

        return preg_match('/name="?([^";\n]+)"?/i', $mime, $matches) === 1
            ? $this->decodeHeader(trim($matches[1]))
            : null;
    }

    /** Los asuntos y nombres con acentos vienen en `=?UTF-8?B?...?=`. */
    private function decodeHeader(string $value): string
    {
        $matches = [];

        if (preg_match_all('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', $value, $matches, PREG_SET_ORDER) === false) {
            return $value;
        }

        foreach ($matches as $match) {
            $text = strtoupper($match[2]) === 'B'
                ? (string) base64_decode($match[3], false)
                : quoted_printable_decode(str_replace('_', ' ', $match[3]));

            $value = str_replace($match[0], $text, $value);
        }

        return $value;
    }

    // ── Persistencia ─────────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    private function createEmail(array $headers, string $path): ProcessedEmail
    {
        [$address, $displayName] = $this->splitAddress($headers['from'] ?? '');

        return ProcessedEmail::query()->create([
            'gmail_id'       => 'demo-'.hash('sha1', $path),
            'thread_id'      => $headers['message-id'] ?? null,
            'mailbox'        => (string) config('gmail_docs.demo.mailbox', 'demo@localhost'),
            'subject'        => $this->decodeHeader($headers['subject'] ?? '(sin asunto)'),
            'sender'         => $address,
            'sender_name'    => $displayName,
            'received_at'    => isset($headers['date'])
                ? Carbon::parse($headers['date'])
                : now(),
            'status'         => ProcessedEmail::STATUS_PENDING,
            'pipeline_stack' => (string) config('gmail_docs.default_stack'),
            'current_stage'  => 'received',
            'metadata_json'  => [
                'demo_source' => basename($path),
                'headers'     => $headers,
            ],
        ]);
    }

    /** `Juan Pérez <juan@x.com>` → `['juan@x.com', 'Juan Pérez']`. */
    private function splitAddress(string $from): array
    {
        if (preg_match('/^(.*)<([^>]+)>\s*$/', trim($from), $matches) === 1) {
            return [
                trim($matches[2]),
                $this->decodeHeader(trim(trim($matches[1]), '"')),
            ];
        }

        return [trim($from), null];
    }

    /**
     * @param  array{filename: string, mime: string, bytes: string}  $part
     */
    private function store(ProcessedEmail $email, array $part): Attachment
    {
        // La carpeta es la MISMA que usa `AttachmentDownloader`: un correo de
        // demo y uno de Gmail tienen que caer en el mismo sitio, o el volumen
        // compartido de Docker no alcanza para los dos.
        $directory = StoragePath::attachments()
            .'/'.$email->gmail_id.'_'.(Str::slug((string) $email->subject) ?: 'demo');

        $filename = $this->uniqueName($directory, basename($part['filename']));
        $absolute = $directory.'/'.$filename;

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($absolute, $part['bytes']);

        return Attachment::query()->create([
            'email_id'   => $email->id,
            'filename'   => $filename,
            'local_path' => $absolute,
            'sha256'     => hash('sha256', $part['bytes']),
            'size_bytes' => strlen($part['bytes']),
            'mime'       => $part['mime'],
        ]);
    }

    /**
     * Desambigua nombres repetidos dentro del mismo correo.
     *
     * Un reenvío con la misma factura dos veces es lo normal, no una excepción.
     */
    private function uniqueName(string $directory, string $name): string
    {
        $safe = Str::limit(preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name) ?: 'archivo', 180, '');

        if (! is_dir($directory)) {
            return $safe;
        }

        $candidate = $safe;
        $counter = 1;

        while (file_exists($directory.'/'.$candidate)) {
            $candidate = Str::beforeLast($safe, '.')
                .'_'.$counter
                .(Str::contains($safe, '.') ? '.'.Str::afterLast($safe, '.') : '');
            $counter++;
        }

        return $candidate;
    }
}