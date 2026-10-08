<?php

/**
 * Configuración del Gmail Docs Validator.
 *
 * Las claves sensibles (API key de OpenRouter) viven en los `.env` de cada
 * worker Python, no aquí: este proceso nunca habla con OpenRouter, solo con
 * los dos servicios locales.
 */
return [

    /*
    |----------------------------------------------------------------------
    | Google / Gmail
    |----------------------------------------------------------------------
    |
    | Hay DOS caminos de credenciales y son excluyentes; cuál se usa lo dice
    | `auth_mode`. Ninguna credencial va en el .env: solo su ruta (ver
    | docs/GOOGLE_CLOUD_SETUP.md).
    |
    |   service_account → Google Workspace + domain-wide delegation.
    |                      Lee N buzones del dominio, y además envía.
    |
    |   oauth           → una cuenta propia de Google, sin Workspace.
    |                      Lee UN buzón, y no puede enviar.
    |
    */

    'google' => [
        // Explícito y no autodetectado a propósito: hay un modo por defecto
        // para que a quien ya tenía la delegación de dominio montada no le
        // cambie nada al actualizar. Si se dedujera de "¿existe el JSON?",
        // un `service-account.json` viejo en storage/private/ cambiaría el
        // comportamiento de un despliegue sin avisar.
        'auth_mode' => env('GMAIL_AUTH_MODE', 'service_account'),

        // ── service_account (Google Workspace + DWD) ────────────────────────

        // Ruta RELATIVA a la raíz del proyecto Laravel.
        'service_account_path' => env(
            'GOOGLE_SERVICE_ACCOUNT_PATH',
            'storage/private/service-account.json',
        ),

        // El usuario de dominio al que se delega la service account (DWD).
        // Debe ser el mismo en todas las llamadas; el buzón "imita" a este
        // usuario porque la DHD no permite suplantar a otro.
        'delegated_user' => env('GOOGLE_DELEGATED_USER'),

        // Buzones a vigilar. Coma-separado. RF-01 pide ≥ 5 sin degradación.
        // En modo oauth se IGNORA: el buzón es la cuenta que se autorizó.
        'mailboxes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_MAILBOXES', '')),
        ))),

        // Scopes de Gmail API. `gmail.modify` lee adjuntos y además envía
        // las respuestas automáticas en el mismo hilo.
        //
        // Lista aparte de `oauth.scopes` a propósito: si el scope de solo
        // lectura compartiera esta clave, la ruta DWD seguiría leyendo bien
        // y rompería SOLO al enviar, que es el fallo más difícil de atribuir.
        'scopes' => [
            'https://www.googleapis.com/auth/gmail.modify',
        ],

        // ── oauth (cuenta propia, sin Google Workspace) ────────────────────

        /*
         * El JSON del cliente y el token NUNCA van en el .env, por el mismo
         * motivo que la service account: el primero trae el `client_secret`,
         * y el segundo es un refresh token que concede acceso al buzón. Los
         * dos van en disco, en `storage/private/`, que está en el .gitignore.
         */
        'oauth' => [
            // JSON descargado de "Credentials → OAuth client ID → Download JSON".
            'client_credentials_path' => env(
                'GOOGLE_OAUTH_CLIENT_PATH',
                'storage/private/oauth-client.json',
            ),

            // Lo escribe la app tras el callback. Es lo que hay que borrar
            // (`gmail:auth:revoke`) para reautorizar una cuenta distinta.
            'token_path' => env(
                'GOOGLE_OAUTH_TOKEN_PATH',
                'storage/private/gmail-token.json',
            ),

            /*
             * Tiene que coincidir CARÁCTER por carácter con el "Authorized
             * redirect URI" registrado en Google Cloud: puerto, esquema y
             * barra final.
             *
             * El default es `null` a propósito, no `env('APP_URL')`: un
             * `env()` anidado dentro de otro devuelve `null` cuando la
             * configuración está cacheada (`config:cache`), y en Docker el
             * `init.sh` corre `optimize`. Se resuelve en runtime contra
             * `config('app.url')` en OAuthClientFactory::redirectUri().
             */
            'redirect_uri' => env('GOOGLE_OAUTH_REDIRECT_URI'),

            // Solo lectura a propósito: la demo no envía respuestas
            // (GMAIL_SEND_ENABLED=false) y así es imposible que un descuido
            // mande un correo a un remitente real. Ampliarlo obliga a
            // reautorizar la cuenta.
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Filtro de asunto (RF-02)
    |----------------------------------------------------------------------
    |
    | Match PARCIAL e insensible a mayúsculas: basta con que el asunto
    | contenga alguno de estos textos. Vacío = aceptar todo.
    |
    */

    'subject_filter' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GMAIL_SUBJECT_FILTER', '')),
    ))),

    /*
    |----------------------------------------------------------------------
    | Envío de respuestas
    |----------------------------------------------------------------------
    |
    | Con `send_enabled` en false las respuestas se generan y se guardan en
    | `email_responses` con status `dry_run`, pero NO se envían. Es lo que hay
    | que usar en el demo para no mandar correos reales por accidente.
    |
    */

    'send_enabled' => (bool) env('GMAIL_SEND_ENABLED', false),

    // Remitente con el que se responde. Por defecto el buzón vigilado.
    'reply_as' => env('GMAIL_REPLY_AS'),

    /*
    |----------------------------------------------------------------------
    | Workers Python
    |----------------------------------------------------------------------
    */

    'workers' => [
        // Stack A — OCR local + json-rules-engine.
        'a' => [
            'url'   => rtrim((string) env('STACK_A_URL', 'http://127.0.0.1:8010'), '/'),
            'label' => 'Stack A · OCR local',
        ],
        // Stack B — OpenRouter + Gemini Flash Lite.
        'b' => [
            'url'   => rtrim((string) env('STACK_B_URL', 'http://127.0.0.1:8011'), '/'),
            'label' => 'Stack B · LLM',
        ],
    ],

    // Stack con el que se procesa un job nuevo. CA-09 y el fallback de CU-05
    // asumen que B es el principal.
    'default_stack' => env('DEFAULT_STACK', 'b'),

    // Segundos antes de que una llamada al worker se considere colgada.
    // RNF-01 pide < 45 s end-to-end; el LLM es el paso lento.
    'timeout' => (int) env('ANALYZER_TIMEOUT_SEC', 60),

    // Bearer compartido con los dos workers.
    'token' => env('ANALYZER_TOKEN'),

    /*
    |----------------------------------------------------------------------
    | Adjuntos (RF-02)
    |----------------------------------------------------------------------
    */

    'attachments' => [
        /*
         | Raíz de los adjuntos, relativa a `storage/app` salvo que sea absoluta.
         |
         | Es la MISMA carpeta que leen los dos workers Python —Laravel les manda
         | la ruta absoluta en `attachments.local_path`—, así que en Docker se
         | monta un volumen compartido en esa ruta exacta. Si empieza por `/` se
         | respeta tal cual; es lo que hace el `docker-compose`.
         */
        'root' => env('ATTACHMENTS_ROOT', 'attachments'),

        // RF-02: archivos > 25 MB generan warning pero se descargan.
        'size_warning_bytes' => 25 * 1024 * 1024,
    ],

    /*
    |----------------------------------------------------------------------
    | Fase 1 — documentos requeridos (RF-08 Stack A)
    |----------------------------------------------------------------------
    |
    | "Póliza nueva" exige solicitud + identificación + comprobante.
    |
    */

    'required_documents' => [
        'factura'      => ['factura'],
        'poliza_nueva' => ['solicitud', 'ine', 'comprobante_domicilio'],
        'renovacion'   => ['poliza_vigente', 'comprobante_domicilio'],
    ],

    // Cuál es el "corredor" de un correo. En v1 se deduce del asunto; si no
    // calza con ninguna clave, se usan los documentos de `default`.
    'corridor_by_subject' => [
        'renovacion'  => ['renov', 'renova'],
        'poliza_nueva' => ['póliza', 'poliza', 'nueva póliza'],
        'factura'     => ['factura', 'comprobante de venta'],
    ],
    'default_corridor' => 'poliza_nueva',

    /*
    |----------------------------------------------------------------------
    | Respuestas (RF-09)
    |----------------------------------------------------------------------
    |
    | Solo se usan templates fijos. Los casos "con errores" y "no clasificado"
    | pasan por el LLM en Stack B; en Stack A los compone el worker.
    |
    */

    'reply' => [
        'validated' => "Estimado(a), hemos recibido su documentación y esta fue validada correctamente. No tiene pendiente enviar nada más por este trámite.",
        'missing'   => "Recibimos su correo, pero todavía falta documentación para poder continuar con el trámite. Por favor adjunte los documentos indicados.\n\n{detalle}",
        'unknown'   => "Recibimos su correo, pero no pudimos completar la validación de los documentos enviados. Por favor vuelva a enviarlos para poder continuar con el trámite.",
    ],

    /*
    |----------------------------------------------------------------------
    | Modo demo
    |----------------------------------------------------------------------
    */

    'demo' => [
        // Carpeta con los `.eml` que ingestan `demo:ingest`. Se resuelve con
        // `StoragePath::absolute()`, igual que los adjuntos.
        'inbox' => env('DEMO_INBOX_PATH', 'demo-inbox'),
    ],

    /*
    |----------------------------------------------------------------------
    | Stream SSE del pipeline
    |----------------------------------------------------------------------
    |
    | Cada conexión abierta RETIENE un worker de php-fpm mientras dure, así que
    | estos tres números no son detalles de implementación: son el límite de
    | cuántos usuarios pueden mirar un job al mismo tiempo sin colgar el panel.
    | El pool (`docker/php-fpm-pool.conf`) tiene 30 workers por esa razón.
    |
    */

    'stream' => [
        // Cada cuánto se consulta la BD buscando etapas nuevas.
        'poll_ms' => 400,

        // Comentario keep-alive para que los proxies intermedios no corten la
        // conexión por inactividad.
        'keepalive_seconds' => 15,

        // Tope duro de duración del stream. Un job real termina antes; esto
        // existe para que un `EventSource` no cerrado no retenga un worker
        // indefinidamente. El browser reconecta solo y el replay con
        // `last_event_id` no pierde nada.
        'max_seconds' => 600,

        // Máximo de streams a la vez en toda la instancia.
        'max_concurrent' => 8,
    ],
];