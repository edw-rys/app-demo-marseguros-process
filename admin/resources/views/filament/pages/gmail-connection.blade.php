{{--
    Estado de la credencial de Gmail.

    En `service_account` no hay nada que autorizar: la service account ya tiene
    permisos sobre el dominio, y esta pantalla solo confirma cuál es. En OAuth
    lo que importa es si el token sigue en pie, de qué cuenta es y si hay que
    reautorizar (modo Testing caduca el refresh token a los ~7 días).
--}}
<x-filament-panels::page>
    @php
        // Mismo aviso que el comando `gmail:auth`: el access token puede
        // parecer vigente mientras el refresh token ya caducó.
        $caducando = $authorizedDaysAgo !== null && $authorizedDaysAgo >= 6;
    @endphp

    @if (session('gmail_status'))
        <div class="rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-800 dark:border-success-700 dark:bg-success-950 dark:text-success-300">
            {{ session('gmail_status') }}
        </div>
    @endif

    @if (session('gmail_error'))
        <div class="rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
            {{ session('gmail_error') }}
        </div>
    @endif

    @unless ($oauthMode)
        {{-- Ruta A: Google Workspace + DWD. No hay nada que autorizar acá. --}}
        <x-filament::section>
            <x-slot name="heading">Credencial de dominio</x-slot>

            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Usuario delegado
                    </dt>
                    <dd class="font-mono text-sm text-gray-950 dark:text-white">
                        {{ $delegatedUser ?: 'sin definir' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Buzones vigilados
                    </dt>
                    <dd class="text-sm text-gray-950 dark:text-white">
                        {{ count($mailboxes) }} configurado(s)
                    </dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
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

                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Buzón
                        </dt>
                        <dd class="font-mono text-sm text-gray-950 dark:text-white">{{ $mailbox }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Access token vence
                        </dt>
                        <dd class="text-sm text-gray-950 dark:text-white">{{ $expiresAt ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Autorizado hace
                        </dt>
                        <dd class="text-sm text-gray-950 dark:text-white">
                            {{ $authorizedDaysAgo === null ? '—' : $authorizedDaysAgo.' día(s)' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Scope
                        </dt>
                        <dd class="font-mono text-xs text-gray-950 dark:text-white">
                            {{ implode(', ', $scopes) ?: '—' }}
                        </dd>
                    </div>
                </dl>
            </x-filament::section>

            @if ($caducando)
                <div class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-300">
                    El refresh token tiene {{ $authorizedDaysAgo }} días. Si la app de Google
                    está en modo <strong>Testing</strong>, probablemente ya caducó: vuelve a
                    pulsar <strong>Autorizar con Google</strong>. Para una demo de varios días,
                    lo más simple es pasar la app a <em>In production</em> en la consola de
                    Google Cloud.
                </div>
            @endif

            <x-filament::section>
                <x-slot name="heading">Solo lectura</x-slot>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    La cuenta se autorizó con <code class="text-xs">gmail.readonly</code>, así
                    que el sistema lee pero <strong>no puede enviar</strong> respuestas. Las
                    respuestas se generan y se guardan igual en el admin como
                    <code class="text-xs">dry_run</code>; no sale ningún correo.
                </p>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Todavía no hay ninguna cuenta autorizada</x-slot>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Pulsá <strong>Autorizar con Google</strong>, aceptá el consentimiento, y
                    volvés acá con el buzón conectado. Google va a pedir
                    <strong>solo acceso de lectura</strong> a tu bandeja.
                </p>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    La redirección que se está usando es
                    <code class="text-xs">{{ $redirectUri }}</code>
                    y tiene que coincidir carácter por carácter con la registrada en Google Cloud.
                </p>
            </x-filament::section>
        @endif
    @endunless
</x-filament-panels::page>