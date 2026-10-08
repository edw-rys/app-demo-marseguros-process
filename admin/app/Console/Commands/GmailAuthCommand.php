<?php

namespace App\Console\Commands;

use App\Services\Gmail\GmailAuthMode;
use App\Services\Gmail\OAuthTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Estado de la conexión OAuth de Gmail e impresión de la URL de autorización.
 *
 * Es el comando para el demo por CLI: lo que imprime es la URL que hay que
 * abrir en el navegador. Sin argumentos solo informa, así que correrlo las
 * veces que haga falta no cambia nada.
 */
class GmailAuthCommand extends Command
{
    protected $signature = 'gmail:auth';

    protected $description = 'Muestra el estado de la conexión OAuth de Gmail e imprime la URL de autorización';

    /** Días tras los cuales conviene sospechar que el refresh token caducó. */
    private const AVISO_CADUCIDAD_DIAS = 6;

    public function handle(OAuthTokenStore $tokens): int
    {
        $this->components->twoColumnDetail('Modo', GmailAuthMode::current()->label());

        if (! GmailAuthMode::current()->authorizesAMailbox()) {
            $this->components->info(
                'No hay nada que autorizar: en service_account la credencial ya es del '
                .'dominio. Poné GMAIL_AUTH_MODE=oauth en el .env para usar una cuenta propia.'
            );

            return self::SUCCESS;
        }

        $credentials = (string) config('gmail_docs.google.oauth.client_credentials_path');
        $credentialsPath = str_starts_with($credentials, '/') ? $credentials : base_path($credentials);

        $this->components->twoColumnDetail(
            'JSON del cliente',
            is_readable($credentialsPath) ? 'en su sitio' : "FALTA ({$credentialsPath})",
        );

        $token = $tokens->read();

        $this->components->twoColumnDetail('Token', $token === null ? 'sin autorizar' : 'autorizado');
        $this->components->twoColumnDetail('Buzón', $tokens->mailbox() ?? '—');

        if ($token !== null) {
            if ($expiresAt = $tokens->expiresAt()) {
                $this->components->twoColumnDetail(
                    'Access token',
                    'vence '.Carbon::createFromTimestamp($expiresAt)->toDateTimeString(),
                );
            }

            $authorizedAt = $tokens->authorizedAt();
            $days = $authorizedAt === null
                ? null
                : (int) floor((time() - $authorizedAt) / 86400);

            if ($days !== null) {
                $this->components->twoColumnDetail('Autorizado hace', "{$days} día(s)");
            }

            // El access token puede parecer vigente mientras el refresh token
            // ya caducó: en modo Testing Google los revoca a los ~7 días. Es
            // el primer "no funciona" que va a reportar quien use esto.
            if ($days !== null && $days >= self::AVISO_CADUCIDAD_DIAS) {
                $this->components->warn(
                    "El refresh token tiene {$days} días. Si la app de Google está en "
                    .'modo Testing probablemente ya caducó. Reautoriza abriendo la URL de '
                    .'abajo (o borra el token con `php artisan gmail:auth:revoke`).'
                );
            }
        }

        $this->newLine();
        $this->components->info('Abre esta URL para autorizar:');
        $this->line($this->authorizationUrl());

        return self::SUCCESS;
    }

    /**
     * La URL local que dispara el flujo, no la de Google: el `state` se genera
     * en sesión y sin servidor web corriendo no hay sesión donde guardarlo.
     */
    private function authorizationUrl(): string
    {
        return route('gmail.oauth.redirect');
    }
}