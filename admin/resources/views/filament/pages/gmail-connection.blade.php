{{--
    Estado de la credencial de Gmail.

    En `service_account` no hay nada que autorizar: la service account ya tiene
    permisos sobre el dominio, y esta pantalla solo confirma cuál es. En OAuth
    lo que importa es si el token sigue en pie, de qué cuenta es y si hay que
    reautorizar (modo Testing caduca el refresh token a los ~7 días).

    Las clases son `gdv-*` y no utilidades de Tailwind: en este panel las
    utilidades no existen. Ver el comentario de `public/css/gdv-pipeline.css`.
--}}
<x-filament-panels::page>
    @once
        <link rel="stylesheet" href="{{ asset('css/gdv-pipeline.css') }}">
    @endonce

    @php
        // Mismo aviso que el comando `gmail:auth`: el access token puede
        // parecer vigente mientras el refresh token ya caducó.
        $caducando = $authorizedDaysAgo !== null && $authorizedDaysAgo >= 6;
    @endphp

    <div class="gdv">
        @if (session('gmail_status'))
            <div class="gdv-alert gdv-alert--ok">{{ session('gmail_status') }}</div>
        @endif

        @if (session('gmail_error'))
            <div class="gdv-alert gdv-alert--error">{{ session('gmail_error') }}</div>
        @endif

        @unless ($oauthMode)
            {{-- Ruta A: Google Workspace + DWD. No hay nada que autorizar acá. --}}
            <x-filament::section>
                <x-slot name="heading">Credencial de dominio</x-slot>

                <dl class="gdv-kv">
                    <div>
                        <dt class="gdv-kv__k">Usuario delegado</dt>
                        <dd class="gdv-kv__v gdv-kv__v--mono">{{ $delegatedUser ?: 'sin definir' }}</dd>
                    </div>

                    <div>
                        <dt class="gdv-kv__k">Buzones vigilados</dt>
                        <dd class="gdv-kv__v">{{ count($mailboxes) }} configurado(s)</dd>
                    </div>
                </dl>

                <p class="gdv-note">
                    El sistema usa una service account con delegación de dominio. Todos los
                    buzones se leen <em>como</em> ese usuario, así que el buzón no se pasa a la
                    API en ningún punto.
                </p>
            </x-filament::section>
        @else
            {{-- Ruta B: cuenta propia vía OAuth. --}}
            @if ($mailbox)
                <x-filament::section>
                    <x-slot name="heading">Cuenta autorizada</x-slot>

                    <dl class="gdv-kv">
                        <div>
                            <dt class="gdv-kv__k">Buzón</dt>
                            <dd class="gdv-kv__v gdv-kv__v--mono">{{ $mailbox }}</dd>
                        </div>

                        <div>
                            <dt class="gdv-kv__k">Access token vence</dt>
                            <dd class="gdv-kv__v">{{ $expiresAt ?: '—' }}</dd>
                        </div>

                        <div>
                            <dt class="gdv-kv__k">Autorizado hace</dt>
                            <dd class="gdv-kv__v">
                                {{ $authorizedDaysAgo === null ? '—' : $authorizedDaysAgo.' día(s)' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="gdv-kv__k">Scope</dt>
                            <dd class="gdv-kv__v gdv-kv__v--mono">{{ implode(', ', $scopes) ?: '—' }}</dd>
                        </div>
                    </dl>
                </x-filament::section>

                @if ($caducando)
                    <div class="gdv-alert gdv-alert--warn">
                        El refresh token tiene {{ $authorizedDaysAgo }} días. Si la app de Google
                        está en modo <strong>Testing</strong>, probablemente ya caducó: vuelve a
                        pulsar <strong>Autorizar con Google</strong>. Para una demo de varios días,
                        lo más simple es pasar la app a <em>In production</em> en la consola de
                        Google Cloud.
                    </div>
                @endif

                <x-filament::section>
                    <x-slot name="heading">Solo lectura</x-slot>

                    <p class="gdv-note">
                        La cuenta se autorizó con <code>gmail.readonly</code>, así
                        que el sistema lee pero <strong>no puede enviar</strong> respuestas. Las
                        respuestas se generan y se guardan igual en el admin como
                        <code>dry_run</code>; no sale ningún correo.
                    </p>
                </x-filament::section>
            @else
                <x-filament::section>
                    <x-slot name="heading">Todavía no hay ninguna cuenta autorizada</x-slot>

                    <p class="gdv-note">
                        Pulsá <strong>Autorizar con Google</strong>, aceptá el consentimiento, y
                        volvés acá con el buzón conectado. Google va a pedir
                        <strong>solo acceso de lectura</strong> a tu bandeja.
                    </p>

                    <p class="gdv-note">
                        La redirección que se está usando es
                        <code>{{ $redirectUri }}</code>
                        y tiene que coincidir carácter por carácter con la registrada en Google Cloud.
                    </p>
                </x-filament::section>
            @endif
        @endunless
    </div>
</x-filament-panels::page>