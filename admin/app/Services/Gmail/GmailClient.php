<?php

namespace App\Services\Gmail;

use Google\Client;
use Google\Service\Gmail;

/**
 * Envoltura de la Gmail API, para los dos modos de credencial.
 *
 *   service_account → Domain-Wide Delegation. La service account actúa
 *                      SIEMPRE como `GOOGLE_DELEGATED_USER` y con ese mismo
 *                      `Subject` se accede a cualquier buzón del dominio para
 *                      el que el admin haya autorizado el scope. Por eso
 *                      `serviceFor()` no recibe el buzón: ya está resuelto.
 *
 *   oauth           → una cuenta propia autorizó el acceso. Hay un solo buzón
 *                      y no hay `Subject` que setear.
 *
 * Ver docs/GOOGLE_CLOUD_SETUP.md para sacar las credenciales de cada modo.
 *
 * Esta clase NO es singleton a propósito. Se resuelve por constructor en
 * `MailboxSynchronizer` y `GmailReplySender`, y por `app()` en
 * `AttachmentDownloader` (que corre dentro de un job encolado, sin usuario
 * autenticado). Dejarla sin binding hace que cada resolución relea el token
 * de disco: si fuera singleton, un token reautorizado a mitad de un proceso
 * largo seguiría usando el viejo en memoria sin que nada lo delate.
 */
class GmailClient
{
    private ?Gmail $gmail = null;

    public function __construct(
        private readonly OAuthTokenStore $tokens,
        private readonly OAuthClientFactory $factory,
    ) {
    }

    /**
     * @throws \RuntimeException si falta la credencial del modo configurado.
     */
    public function service(): Gmail
    {
        if ($this->gmail !== null) {
            return $this->gmail;
        }

        $client = match (GmailAuthMode::current()) {
            GmailAuthMode::ServiceAccount => $this->serviceAccountClient(),
            GmailAuthMode::Oauth => $this->oauthClient(),
        };

        return $this->gmail = new Gmail($client);
    }

    /**
     * La Gmail API no necesita el buzón como parámetro: el `Subject` de la
     * DHD ya determina sobre qué cuenta se opera, y en OAuth la credencial es
     * de un solo buzón. Se acepta el buzón solo para que el código que llama
     * sea explícito sobre qué cuenta está leyendo.
     */
    public function serviceFor(string $mailbox): Gmail
    {
        return $this->service();
    }

    /**
     * Los buzones que este despliegue vigila.
     *
     * En DHD es la lista de `GOOGLE_MAILBOXES`: N cuentas del dominio, todas
     * leídas "como" `GOOGLE_DELEGATED_USER`. En OAuth es exactamente uno —la
     * cuenta que autorizó— y sale del token, no del `.env`.
     *
     * Nadie más debe leer la lista de la config directamente: quien la necesita
     * no debería tener que saber qué modo de credencial hay.
     *
     * @return array<int, string>
     */
    public function mailboxes(): array
    {
        if (GmailAuthMode::current()->authorizesAMailbox()) {
            $mailbox = $this->tokens->mailbox();

            return $mailbox === null ? [] : [$mailbox];
        }

        return (array) config('gmail_docs.google.mailboxes');
    }

    /**
     * Lee los mensajes nuevos de un buzón desde su `historyId` (RF-01).
     *
     * Usa `users.history.list` en lugar de polling de la bandeja: es lo que
     * permite sostener 200 correos/día sin gastar cuota innecesaria.
     *
     * @return array{history_id: string, messages: array<int, array{ id: string, thread_id: string }>}
     */
    public function listNewMessages(string $mailbox, string $historyId): array
    {
        $service = $this->serviceFor($mailbox);
        $messages = [];
        $pageToken = null;
        $latestHistoryId = $historyId;

        // Primer sync de un buzón: `history_id` arranca en '1' (ver
        // `WatchState::ensureFor`), y Google responde 404 NOT_FOUND porque ese
        // cursor no existe. Lo correcto es sembrarlo con el `historyId` actual
        // del perfil, que además es lo que define la semántica: a partir de
        // ahora. Por eso el primer poll de una cuenta nueva NO recupera el
        // histórico — solo lo que entra desde que se conectó.
        if ($historyId === '1') {
            $historyId = (string) $service->users->getProfile('me')->getHistoryId();
            $latestHistoryId = $historyId;
        }

        do {
            // Los sub-recursos son PROPIEDADES planas del servicio
            // (`users_history`, `users_messages`, …), no métodos encadenados: el
            // constructor de `Gmail` las instancia todas de una vez.
            // `listUsersHistory`, no `list`: PHP reserva la palabra, así que el
            // generador de la librería le antepasa el nombre del recurso.
            $response = $service->users_history->listUsersHistory('me', [
                'startHistoryId'  => $historyId,
                'labelId'         => 'INBOX',
                'historyTypes'    => ['messageAdded'],
                'maxResults'      => 100,
                'pageToken'       => $pageToken,
            ]);

            // Se guarda el último historyId ANTES de filtrar por asunto: si un
            // mensaje no pasa el filtro nunca volverá a aparecer, así que el
            // cursor debe avanzar igual o el correo se reprocesaría para siempre.
            $latestHistoryId = $response->getHistoryId() ?: $latestHistoryId;

            foreach ($response->getHistory() ?? [] as $history) {
                foreach ($history->getMessagesAdded() ?? [] as $added) {
                    $message = $added->getMessage();
                    if ($message && $message->getId()) {
                        $messages[$message->getId()] = [
                            'id'        => $message->getId(),
                            'thread_id' => $message->getThreadId(),
                        ];
                    }
                }
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return ['history_id' => $latestHistoryId, 'messages' => array_values($messages)];
    }

    /**
     * Headers de un mensaje, sin descargar el cuerpo.
     *
     * @return array{subject: ?string, from: ?string, from_name: ?string, date: ?string, snippet: ?string}
     */
    public function headers(string $mailbox, string $messageId): array
    {
        $service = $this->serviceFor($mailbox);

        $message = $service->users_messages->get('me', $messageId, [
            'format' => 'metadata',
            'metadataHeaders' => ['Subject', 'From', 'Date'],
        ]);

        $headers = [];
        foreach ($message->getPayload()?->getHeaders() ?? [] as $header) {
            $headers[strtolower((string) $header->getName())] = $header->getValue();
        }

        $from = $headers['from'] ?? null;
        [$address, $name] = $this->splitFrom((string) $from);

        return [
            'subject'    => $headers['subject'] ?? null,
            'from'       => $address,
            'from_name'  => $name,
            'date'       => $headers['date'] ?? null,
            'snippet'    => $message->getSnippet(),
            'thread_id'  => $message->getThreadId(),
        ];
    }

    /** `Juan Pérez <juan@x.com>` → `['juan@x.com', 'Juan Pérez']`. */
    private function splitFrom(string $from): array
    {
        if (preg_match('/^(.*)<([^>]+)>\s*$/', trim($from), $matches) === 1) {
            return [trim($matches[2]), trim(trim($matches[1]), '"')];
        }

        return [trim($from), null];
    }

    /** Registra un watch de Gmail para este usuario (RF-01). */
    public function watch(string $mailbox, string $topicName): string
    {
        $service = $this->serviceFor($mailbox);

        $response = $service->users->watch()->post('me', [
            'topicName' => $topicName,
            'labelIds'  => ['INBOX'],
            'labelFilterBehavior' => 'include',
        ]);

        return (string) $response->getHistoryId();
    }

    private function serviceAccountClient(): Client
    {
        $path = self::credentialsPath();

        if (! is_readable($path)) {
            throw new \RuntimeException(
                "No se encuentra el JSON de la service account en '{$path}'. "
                .'Revisa GOOGLE_SERVICE_ACCOUNT_PATH y docs/GOOGLE_CLOUD_SETUP.md.',
            );
        }

        $client = new Client();
        $client->setApplicationName((string) config('app.name'));
        $client->setAuthConfig($path);
        $client->setScopes(config('gmail_docs.google.scopes'));
        $client->setSubject((string) config('gmail_docs.google.delegated_user'));

        return $client;
    }

    /**
     * Cliente con el refresh token del usuario.
     *
     * Se pasa el token COMPLETO (con `refresh_token`) y no solo el access
     * token: `Client::authorize()` detecta `refresh_token` + token caducado y
     * arma un `UserRefreshCredentials` con client_id + client_secret +
     * refresh_token, que refresca solo.
     *
     * El `token_callback` propio NO es una mejora, es obligatorio: el de la
     * librería (en el constructor de `Client`) reemplaza el token en memoria
     * por uno que solo trae `access_token` y PIERDE el `refresh_token`. En un
     * proceso larga vida como `gmail:poll` o el worker de cola, el SEGUNDO
     * refresco de la hora fallaría con un error de credenciales sin causa
     * aparente. Este callback vuelve a escribir el archivo entero.
     */
    private function oauthClient(): Client
    {
        $token = $this->tokens->read();

        if ($token === null) {
            throw new \RuntimeException(
                'No hay token OAuth de Gmail. Autoriza la cuenta en el admin '
                .'(Conexión con Gmail) o corre `php artisan gmail:auth`. '
                .'Ver docs/GOOGLE_CLOUD_SETUP.md.'
            );
        }

        $client = $this->factory->client();

        $client->setAccessToken([
            'access_token'  => (string) $token['access_token'],
            'refresh_token' => (string) $token['refresh_token'],
            'expires_in'    => (int) $token['expires_in'],
            'created'       => (int) $token['created'],
        ]);

        $client->setTokenCallback(function (string $cacheKey, string $accessToken) use ($token): void {
            // Se preserva el refresh_token: es lo único que sobrevive a la
            // próxima caducidad. `+` mantiene el resto del token (mailbox,
            // scopes, authorized_at) y sobrepone los dos campos que cambian.
            $this->tokens->write($token + [
                'access_token' => $accessToken,
                'created'      => time(),
            ]);
        });

        return $client;
    }

    /**
     * Falla con un mensaje accionable si la credencial no puede enviar.
     *
     * Con `gmail.readonly`, `users.messages.send` devuelve un 403 "Request had
     * insufficient authentication scopes" que no dice ni por qué ni cómo
     * arreglarlo. Conviene fallar antes de gastar la llamada, y con texto que
     * el operador pueda seguir.
     *
     * @throws \RuntimeException
     */
    public function assertCanSend(): void
    {
        if ($this->canSend()) {
            return;
        }

        throw new \RuntimeException(
            'La credencial de Gmail está autorizada en modo solo lectura '
            .'(gmail.readonly), así que no puede enviar respuestas. Opciones: deja '
            .'GMAIL_SEND_ENABLED=false en el .env (las respuestas se generan y se '
            .'guardan como dry_run en el admin) o amplía el scope en '
            .'config/gmail_docs.php y reautoriza la cuenta.'
        );
    }

    public function canSend(): bool
    {
        // Depende del modo: leer siempre `google.scopes` haría que en OAuth
        // esta comprobación viera el `gmail.modify` de la service account y
        // siempre daría `true` — el guard no guardaría nada y el 403 de Google
        // seguiría siendo lo primero que se ve.
        $scopes = GmailAuthMode::current()->authorizesAMailbox()
            ? (array) config('gmail_docs.google.oauth.scopes')
            : (array) config('gmail_docs.google.scopes');

        return in_array('https://www.googleapis.com/auth/gmail.modify', $scopes, true)
            || in_array('https://www.googleapis.com/auth/gmail.send', $scopes, true);
    }

    /**
     * Ruta del JSON de la service account.
     *
     * `public static` para que `isConfigured()` lo use sin duplicar la
     * resolución de rutas relativas.
     */
    public static function credentialsPath(): string
    {
        $configured = (string) config('gmail_docs.google.service_account_path');

        // Las rutas relativas en `.env` son relativas a la raíz del proyecto.
        return str_starts_with($configured, '/')
            ? $configured
            : base_path($configured);
    }

    /**
     * ¿Está todo lo necesario para hablar con Gmail?
     *
     * Lo que decide cambia con el modo: en DHD son el JSON, el usuario
     * delegado y la lista de buzones; en OAuth es que exista un token CON
     * refresh token — sin él el sistema arranca pero no puede leer nada, y ese
     * es justo el estado en el que hay que avisar.
     */
    public static function isConfigured(): bool
    {
        if (GmailAuthMode::current()->authorizesAMailbox()) {
            $token = app(OAuthTokenStore::class)->read();

            return $token !== null && filled($token['refresh_token'] ?? null);
        }

        return is_readable(self::credentialsPath())
            && filled(config('gmail_docs.google.delegated_user'))
            && filled(config('gmail_docs.google.mailboxes'));
    }
}