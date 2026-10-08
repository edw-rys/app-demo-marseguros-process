{{--
    Estado de los workers y de la configuración de envío.

    Es deliberadamente lo primero del listado de jobs: si un job falla, la causa
    más probable es un worker caído o credenciales sin configurar, y eso se ve
    sin tener que abrir nada.

    Las clases son `gdv-*` y no utilidades de Tailwind: en este panel las
    utilidades no existen. Ver el comentario de `public/css/gdv-pipeline.css`.
--}}
<x-filament-widgets::widget>
    @once
        <link rel="stylesheet" href="{{ asset('css/gdv-pipeline.css') }}">
    @endonce

    <div class="gdv">
        <div class="gdv-cards">
            @foreach ([
                'a' => ['Stack A · OCR local', 'Tesseract + reglas'],
                'b' => ['Stack B · LLM', 'OpenRouter + Gemini'],
            ] as $stack => [$label, $detail])
                @php
                    $state = $health[$stack] ?? ['up' => false, 'detail' => 'sin respuesta'];
                    $isUp = $state['up'] ?? false;
                @endphp

                <div class="gdv-card @if ($isUp) gdv-card--ok @else gdv-card--down @endif">
                    <div class="gdv-card__top">
                        <span class="gdv-card__title">{{ $label }}</span>
                        <span class="gdv-card__state @if ($isUp) gdv-card__state--up @else gdv-card__state--down @endif">
                            {{ $isUp ? 'activo' : 'caído' }}
                        </span>
                    </div>

                    <p class="gdv-card__detail">{{ $detail }}</p>

                    @unless ($isUp)
                        <p class="gdv-card__note gdv-card__note--error">
                            {{ is_array($state['detail'] ?? null)
                                ? json_encode($state['detail'], JSON_UNESCAPED_UNICODE)
                                : ($state['detail'] ?? '') }}
                        </p>
                    @endunless
                </div>
            @endforeach

            <div class="gdv-card gdv-card--neutral">
                <div class="gdv-card__top">
                    <span class="gdv-card__title">Configuración</span>
                    <span class="gdv-card__state">
                        stack por defecto: <span class="gdv-card__code">{{ $defaultStack }}</span>
                    </span>
                </div>

                <p class="gdv-card__detail">
                    {{ $gmailConfigured ? 'Gmail configurado' : 'Gmail sin configurar' }}
                    ({{ $gmailMode->value }})
                    · envío
                    {{ $sendEnabled ? 'activo' : 'desactivado (dry_run)' }}
                </p>

                @unless ($gmailConfigured)
                    <p class="gdv-card__note">
                        @if ($gmailMode->authorizesAMailbox())
                            Sin token OAuth no hay sincronización. Autorizá la cuenta
                            desde <strong>Conexión con Gmail</strong>, o probá con
                            <code>php artisan demo:ingest</code>.
                        @else
                            Sin credenciales no hay sincronización por Gmail. Prueba con
                            <code>php artisan demo:ingest</code>.
                        @endif
                    </p>
                @endunless
            </div>
        </div>
    </div>
</x-filament-widgets::widget>