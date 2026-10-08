<?php

namespace App\Support;

/**
 * Resuelve las rutas de almacenamiento a una única definición.
 *
 * Antes de esta clase las dos rutas se resolvían de forma distinta:
 *
 * - `AttachmentDownloader` usaba `Storage::disk('local')->path()`, que
 *   prefija `storage/app/private`.
 * - `Demo\EmailParser` usaba `storage_path('app/')`.
 *
 * Con el valor por defecto de `ATTACHMENTS_ROOT` eso dejaba los adjuntos de
 * Gmail en un sitio y los de `demo:ingest` en otro, ambos con un `storage/app`
 * de más. En Docker es peor: los workers tienen que leer exactamente la misma
 * ruta que escribió Laravel, y un volumen montado en un solo lugar no alcanza.
 *
 * Ahora `ATTACHMENTS_ROOT` es relativo a `storage/app` salvo que empiece por
 * `/`, en cuyo caso se usa tal cual — que es lo que hace el `docker-compose`
 * para montarlo como volumen compartido entre los tres servicios.
 */
final class StoragePath
{
    /**
     * Ruta absoluta de una carpeta.
     *
     * Relativa → cuelga de `storage/app`. Absoluta → se respeta tal cual.
     */
    public static function absolute(string $configured): string
    {
        $configured = trim($configured);

        if (str_starts_with($configured, '/')) {
            return rtrim($configured, '/');
        }

        return storage_path('app/'.trim($configured, '/'));
    }

    /** Carpeta donde se guardan los adjuntos descargados. */
    public static function attachments(): string
    {
        return self::absolute((string) config('gmail_docs.attachments.root'));
    }

    /** Carpeta con los `.eml` del modo demo. */
    public static function demoInbox(): string
    {
        return self::absolute((string) config('gmail_docs.demo.inbox'));
    }
}