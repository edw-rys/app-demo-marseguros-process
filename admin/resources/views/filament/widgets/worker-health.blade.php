{{--
    Estado de los workers y de la configuración de envío.

    Es deliberadamente lo primero del listado de jobs: si un job falla, la causa
    más probable es un worker caído o credenciales sin configurar, y eso se ve
    sin tener que abrir nada.
--}}
<x-filament-widgets::widget>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            'a' => ['Stack A · OCR local', 'Tesseract + reglas'],
            'b' => ['Stack B · LLM', 'OpenRouter + Gemini'],
        ] as $stack => [$label, $detail])
            @php
                $state = $health[$stack] ?? ['up' => false, 'detail' => 'sin respuesta'];
                $isUp = $state['up'] ?? false;
            @endphp

            <div @class([
                'rounded-lg border p-3',
                'border-success-200 bg-success-50 dark:border-success-700 dark:bg-success-950' => $isUp,
                'border-danger-200 bg-danger-50 dark:border-danger-700 dark:bg-danger-950' => ! $isUp,
            ])>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $label }}</span>
                    <span @class([
                        'inline-flex items-center gap-1.5 text-xs font-medium',
                        'text-success-700 dark:text-success-400' => $isUp,
                        'text-danger-700 dark:text-danger-400' => ! $isUp,
                    ])>
                        <span class="h-2 w-2 rounded-full {{ $isUp ? 'bg-success-500' : 'bg-danger-500' }}"></span>
                        {{ $isUp ? 'activo' : 'caído' }}
                    </span>
                </div>

                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $detail }}</p>

                @unless ($isUp)
                    <p class="mt-2 text-xs text-danger-700 dark:text-danger-300">
                        {{ is_array($state['detail'] ?? null)
                            ? json_encode($state['detail'], JSON_UNESCAPED_UNICODE)
                            : ($state['detail'] ?? '') }}
                    </p>
                @endunless
            </div>
        @endforeach

        <div @class([
            'rounded-lg border p-3',
            'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800' => true,
        ])>
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">Configuración</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    stack por defecto: <span class="font-mono uppercase">{{ $defaultStack }}</span>
                </span>
            </div>

            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ $gmailConfigured ? 'Gmail configurado' : 'Gmail sin configurar' }}
                ({{ $gmailMode->value }})
                · envío
                {{ $sendEnabled ? 'activo' : 'desactivado (dry_run)' }}
            </p>

            @unless ($gmailConfigured)
                <p class="mt-2 text-xs text-warning-700 dark:text-warning-300">
                    @if ($gmailMode->authorizesAMailbox())
                        Sin token OAuth no hay sincronización. Autorizá la cuenta
                        desde <strong>Conexión con Gmail</strong>, o probá con
                        <code class="font-mono">php artisan demo:ingest</code>.
                    @else
                        Sin credenciales no hay sincronización por Gmail. Prueba con
                        <code class="font-mono">php artisan demo:ingest</code>.
                    @endif
                </p>
            @endunless
        </div>
    </div>
</x-filament-widgets::widget>