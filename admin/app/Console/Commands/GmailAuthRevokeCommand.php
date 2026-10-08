<?php

namespace App\Console\Commands;

use App\Services\Gmail\OAuthClientFactory;
use App\Services\Gmail\OAuthTokenStore;
use Illuminate\Console\Command;

/**
 * Desconecta la cuenta: borra el token local y, con `--remote`, lo revoca
 * también en Google.
 *
 * `--remote` es opcional a propósito: si el refresh token ya caducó —el caso
 * normal en modo Testing a los 7 días— Google devuelve error, y el
 * desconectado local tiene que completarse igual. Por eso va aparte y nunca
 * bloquea la limpieza.
 */
class GmailAuthRevokeCommand extends Command
{
    protected $signature = 'gmail:auth:revoke
                            {--remote : Revoca además el token en Google}';

    protected $description = 'Borra el token OAuth de Gmail y desconecta la cuenta';

    public function handle(OAuthTokenStore $tokens, OAuthClientFactory $factory): int
    {
        if (! $tokens->exists()) {
            $this->components->info('No hay token OAuth que borrar.');

            return self::SUCCESS;
        }

        if ($this->option('remote')) {
            try {
                $factory->revokeRemote();
                $this->components->info('Token revocado en Google.');
            } catch (\Throwable $e) {
                $this->components->warn('No se pudo revocar en Google: '.$e->getMessage());
            }
        }

        // Se lee ANTES de borrar: después `read()` ya devuelve null y el aviso
        // sobre las filas huérfanas no saldría nunca.
        $mailbox = $tokens->mailbox();

        $tokens->forget();

        $this->components->info('Token borrado de '.$tokens->path().'.');

        // Las filas de `watch_states` del buzón viejo quedan con su cursor y
        // `processed_emails.mailbox` sigue apuntando a una cuenta a la que ya
        // no hay acceso. No se borran solas: un reproceso de esos correos
        // fallaría al descargar adjuntos.
        if ($mailbox !== null) {
            $this->components->warn(
                "Quedó la fila de {$mailbox} en watch_states. Si autorizabas otra "
                .'cuenta, borrala para no reprocesar correos a los que ya no se tiene acceso.'
            );
        }

        return self::SUCCESS;
    }
}