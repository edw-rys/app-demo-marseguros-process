<?php

namespace App\Services\Gmail;

use Illuminate\Support\Facades\Log;

/**
 * Guarda el refresh token de OAuth en `storage/private/gmail-token.json`.
 *
 * Archivo y no tabla por la misma razón que el JSON de la service account: es
 * una credencial, no un dato de negocio. La ruta vive en el `.env`; el
 * contenido nunca. `storage/private/` está en el `.gitignore` y en el
 * `.dockerignore`.
 *
 * Además del token guarda `mailbox`, que sale de `users.getProfile` en el
 * callback. Se persiste para no tener que pedirlo en cada sync: el buzón de
 * una credencial OAuth es uno solo y no cambia hasta que se reautoriza.
 *
 * TODO: la resolución de ruta de abajo es la tercera copia de la que ya hay
 * en `GmailClient::credentialsPath()`. `StoragePath` no sirve porque resuelve
 * contra `storage/app` y esto vive en `storage/private`. Unificarlo es refactor
 * de código que hoy funciona; queda anotado para cuando toque.
 */
class OAuthTokenStore
{
    /**
     * @return array<string, mixed>|null El token guardado, o `null` si nunca se
     *                                  autorizó una cuenta (o se revocó).
     */
    public function read(): ?array
    {
        $path = $this->path();

        if (! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $token
     */
    public function write(array $token): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        // 0600: mismo criterio que el `chmod 600` que ya documenta la guía
        // para el JSON de la service account.
        file_put_contents(
            $path,
            json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        // El `chmod` NO puede abortar la autorización: el token ya está escrito
        // y es lo que importa para que funcione. Fallar acá tiraría la
        // autorización entera por endurecer permisos, que es lo contrario de
        // lo que se quiere.
        //
        // El caso real es `Operation not permitted` cuando el archivo YA
        // existía en el host y lo creó otro usuario —típicamente el del deploy,
        // con el bind-mount de `storage/private`—. `www-data` puede escribirlo
        // porque el directorio se lo deja, pero no puede cambiarle los permisos
        // a un archivo que no es suyo: `chmod` exige ser el dueño.
        //
        // La corrección de verdad es que el archivo lo cree `www-data` desde
        // cero (borrando el viejo del host, o `chown` en el despliegue). Acá
        // solo se evita que eso parezca un fallo de Google.
        if (! @chmod($path, 0600)) {
            Log::warning('No se pudo dejar gmail-token.json en 0600.', [
                'path'     => $path,
                'owner'    => @fileowner($path) ?: null,
                'usuario'  => function_exists('posix_getpwuid') && @fileowner($path)
                                ? (posix_getpwuid(@fileowner($path))['name'] ?? null)
                                : null,
                'permisos' => substr(sprintf('%o', @fileperms($path)), -4),
            ]);
        }
    }

    public function forget(): void
    {
        if (is_file($this->path())) {
            unlink($this->path());
        }
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** El buzón de la cuenta autorizada, o `null` si no hay token. */
    public function mailbox(): ?string
    {
        $mailbox = $this->read()['mailbox'] ?? null;

        return filled($mailbox) ? (string) $mailbox : null;
    }

    /**
     * Unix timestamp en el que caduca el access token guardado.
     *
     * OJO: esto NO dice si el refresh token sigue vivo. En modo Testing Google
     * caduca el refresh token a los ~7 días aunque el access token parezca
     * nuevo — de ahí `authorizedAt()` y el aviso del comando `gmail:auth`.
     */
    public function expiresAt(): ?int
    {
        $token = $this->read();

        if ($token === null) {
            return null;
        }

        return (int) ($token['created'] ?? 0) + (int) ($token['expires_in'] ?? 0);
    }

    /** Cuándo se autorizó la cuenta, para poder avisar de la caducidad. */
    public function authorizedAt(): ?int
    {
        $token = $this->read();

        if ($token === null || ! isset($token['authorized_at'])) {
            return null;
        }

        return (int) strtotime((string) $token['authorized_at']) ?: null;
    }

    public function path(): string
    {
        $configured = (string) config('gmail_docs.google.oauth.token_path');

        // Las rutas relativas en `.env` son relativas a la raíz del proyecto,
        // igual que en `GmailClient::credentialsPath()`.
        return str_starts_with($configured, '/')
            ? $configured
            : base_path($configured);
    }
}