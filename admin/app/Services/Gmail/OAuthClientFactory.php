<?php

namespace App\Services\Gmail;

use Google\Client;
use Google\Service\Gmail as GmailService;

/**
 * Cliente OAuth 2.0 de tipo "Web application" para una cuenta propia.
 *
 * Es el camino largo de OAuth de `google/apiclient`: `createAuthUrl()` +
 * `fetchAccessTokenWithAuthCode()`. El corto es `Google\Auth\OAuth2`, que este
 * proyecto no usa. `setAuthConfig()` con un JSON de forma `web` solo puebla
 * client_id / client_secret / redirect_uri — no activa Application Default
 * Credentials, que es lo que distingue esta credencial de una service account.
 *
 * Todo lo que habla con Google en el flujo OAuth pasa por acá a propósito: es
 * el seam que permite testear el resto sin red. `Http::fake()` NO serviría,
 * porque `google/apiclient` construye su propio Guzzle en
 * `Client::createDefaultHttpClient()` en vez de usar el de Laravel.
 */
class OAuthClientFactory
{
    public function __construct(private readonly OAuthTokenStore $tokens)
    {
    }

    /**
     * URL de autorización de Google. El `state` lo genera el controller y lo
     * valida el callback: es lo que ata el `code` a esta sesión.
     */
    public function authorizationUrl(string $state): string
    {
        $client = $this->client();

        $client->setState($state);

        // `offline` es lo que hace que Google devuelva un refresh token en vez
        // de solo un access token de una hora.
        $client->setAccessType('offline');

        // `consent` fuerza el prompt aunque la app ya estuviera autorizada.
        // Sin esto, Google NO vuelve a entregar el `refresh_token` en una
        // reautorización, y sin refresh token el sistema muere a la hora.
        $client->setPrompt('consent');

        $client->setRedirectUri($this->redirectUri());

        return $client->createAuthUrl($this->scopes());
    }

    /**
     * Canjea el `code` por tokens, resuelve de qué buzón son y los persiste.
     *
     * @return array<string, mixed> El token tal como queda en disco.
     *
     * @throws \RuntimeException si Google no devuelve un refresh token.
     */
    public function exchangeCode(string $code): array
    {
        $client = $this->client();

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (blank($token['refresh_token'] ?? null)) {
            throw new \RuntimeException(
                'Google no devolvió refresh_token. Pasa a ser inevitable cuando la '
                .'app está en modo Testing y el consentimiento ya se había dado antes: '
                .'vuelve a autorizar con el botón de la página de conexión, o pon la '
                .'app en "In production" en la consola de Google Cloud.'
            );
        }

        // `fetchAccessTokenWithAuthCode` no siempre los trae, y sin `created`
        // Google no puede saber si el token expiró.
        $token['created'] ??= time();
        $token['expires_in'] ??= 3600;
        $token['scopes'] = $this->scopes();
        $token['mailbox'] = $this->resolveMailbox($client);
        $token['authorized_at'] = now()->toIso8601String();

        $this->tokens->write($token);

        return $token;
    }

    /**
     * Revoca el refresh token en Google.
     *
     * Best-effort a propósito: si el token ya caducó (el caso normal en modo
     * Testing a los 7 días) Google devuelve error, y el desconectado local
     * tiene que completarse igual. Quien llama captura la excepción.
     */
    public function revokeRemote(): void
    {
        $token = $this->tokens->read();

        if ($token === null || blank($token['refresh_token'] ?? null)) {
            return;
        }

        $client = $this->client();

        $client->revokeToken((string) $token['refresh_token']);
    }

    /**
     * El cliente "sin usuario": tiene el client_id y el client_secret, pero no
     * ningún token.
     *
     * Lo usa también `GmailClient` en modo OAuth, porque `Client::authorize()`
     * arma el refresco con `createUserRefreshCredentials()`, que necesita las
     * tres cosas (client_id + client_secret + refresh_token).
     */
    public function client(): Client
    {
        $configured = (string) config('gmail_docs.google.oauth.client_credentials_path');
        $path = str_starts_with($configured, '/') ? $configured : base_path($configured);

        // Se comprueba antes de llamar a `setAuthConfig()` porque la librería
        // lanza InvalidArgumentException/LogicException cuyos mensajes no
        // mencionan el archivo: sin esto el operador no sabría ni qué le pasó.
        if (! is_readable($path)) {
            throw new \RuntimeException(
                "No se encuentra el JSON del cliente OAuth en '{$path}'. "
                .'Descárgalo de Google Cloud (Credentials → tu OAuth client ID → '
                .'Download JSON) y ponlo ahí. Ver docs/GOOGLE_CLOUD_SETUP.md.'
            );
        }

        $client = new Client();
        $client->setApplicationName((string) config('app.name'));
        $client->setAuthConfig($path);
        $client->setRedirectUri($this->redirectUri());
        $client->setScopes($this->scopes());

        return $client;
    }

    /**
     * De qué buzón es esta credencial.
     *
     * Sale de `users.getProfile`, que ya responde con el scope `gmail.readonly`
     * que se pidió: no hace falta añadir `userinfo.email` al scope por este
     * dato. Es una sola llamada y se hace una única vez, en el callback.
     *
     * @throws \RuntimeException si Google no devuelve el email del perfil.
     */
    private function resolveMailbox(Client $client): string
    {
        // `users` es una PROPIEDAD del servicio (la instancia el constructor de
        // `Gmail`); `getProfile` sí es un método suyo.
        $profile = (new GmailService($client))->users->getProfile('me');

        $mailbox = trim((string) $profile->getEmailAddress());

        // Sin esta guarda el token se guardaría con `mailbox` vacío y el
        // fallo aparecería después, en `gmail:sync`, como "no hay ningún buzón
        // autorizado" — que no señala la causa real.
        if ($mailbox === '') {
            throw new \RuntimeException(
                'Google autorizó la cuenta pero no devolvió el email del perfil. '
                .'Suele indicar que el scope concedido no incluye gmail.readonly: '
                .'desconectá con `php artisan gmail:auth:revoke` y volvé a autorizar.'
            );
        }

        return $mailbox;
    }

    /**
     * La URI de redirección, resuelta en runtime.
     *
     * NO sale de un `env()` anidado en el archivo de config: cuando la
     * configuración está cacheada (`config:cache`, que corre el `init.sh` de
     * Docker) los `env()` anidados devuelven `null` y Google respondería
     * `redirect_uri_mismatch`.
     */
    public function redirectUri(): string
    {
        $configured = config('gmail_docs.google.oauth.redirect_uri');

        if (filled($configured)) {
            return (string) $configured;
        }

        return rtrim((string) config('app.url'), '/').'/gmail/oauth/callback';
    }

    /** @return array<int, string> */
    public function scopes(): array
    {
        return array_values((array) config('gmail_docs.google.oauth.scopes'));
    }
}