{{--
    Timeline de etapas de un job.

    Se renderiza desde `JobInfolist::runsOf()`, que ya agrupó las etapas por
    corrida del pipeline. Cada corrida es un bloque; dentro, las etapas por
    adjunto se separan por nombre de archivo para que se distingan de las
    etapas que corren una sola vez por correo.
--}}
@php
    $statusStyles = [
        'passed'   => ['dot' => 'bg-success-500', 'label' => 'text-success-700 dark:text-success-400'],
        'failed'   => ['dot' => 'bg-danger-500',  'label' => 'text-danger-700 dark:text-danger-400'],
        'degraded' => ['dot' => 'bg-warning-500', 'label' => 'text-warning-700 dark:text-warning-400'],
        'skipped'  => ['dot' => 'bg-gray-400',    'label' => 'text-gray-500 dark:text-gray-400'],
        'running'  => ['dot' => 'bg-info-500',    'label' => 'text-info-700 dark:text-info-400'],
    ];
@endphp

@if (empty($runs))
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Este job todavía no tiene etapas registradas.
    </p>
@else
    <div class="space-y-4">
        @foreach ($runs as $index => $run)
            @php
                // El número viene de `job_stages.run`, no de la posición en la
                // lista: las corridas llegan invertidas (la última primero) y
                // numerarlas por posición mentiría sobre cuál es cuál.
                $runNumber = (int) $run['number'];
                $lastAttachmentId = null;
            @endphp

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex flex-wrap items-center gap-2 mb-3 text-xs text-gray-600 dark:text-gray-400">
                    <span class="font-semibold text-gray-900 dark:text-gray-100">
                        Corrida #{{ $runNumber }}
                    </span>

                    @if ($index === 0 && count($runs) > 1)
                        <span class="rounded bg-primary-100 px-2 py-0.5 text-primary-700 dark:bg-primary-900 dark:text-primary-300">
                            última
                        </span>
                    @endif

                    <span>{{ $run['started']?->format('d/m/Y H:i:s') ?? '—' }}</span>
                    <span aria-hidden="true">·</span>
                    <span>stack {{ strtoupper((string) $run['stack']) }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ count($run['stages']) }} etapas</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ $run['total_ms'] < 1000 ? $run['total_ms'].' ms' : round($run['total_ms'] / 1000, 2).' s' }}</span>

                    @if ($run['degraded'])
                        <span class="rounded bg-warning-100 px-2 py-0.5 text-warning-700 dark:bg-warning-900 dark:text-warning-300">
                            degradada (fallback)
                        </span>
                    @endif

                    @if ($run['failed'])
                        <span class="rounded bg-danger-100 px-2 py-0.5 text-danger-700 dark:bg-danger-900 dark:text-danger-300">
                            con fallo
                        </span>
                    @endif

                    @if (! $run['complete'])
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            incompleta
                        </span>
                    @endif
                </div>

                <ul class="space-y-0">
                    @foreach ($run['stages'] as $stage)
                        @php
                            $style = $statusStyles[$stage->status] ?? $statusStyles['skipped'];
                            $isPerAttachment = $stage->stageEnum()?->isPerAttachment();
                            $filename = $stage->attachment?->filename;
                        @endphp

                        @if ($isPerAttachment && $filename && $filename !== $lastAttachmentId)
                            <li class="flex items-center gap-2 pt-3 pb-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                <span class="font-mono">{{ $filename }}</span>
                            </li>
                        @endif

                        <li class="flex items-start gap-3 py-1.5">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $style['dot'] }}"></span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $stage->label() }}
                                    </span>
                                    <span class="text-xs {{ $style['label'] }}">{{ $stage->statusEnum()?->label() ?? $stage->status }}</span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $stage->durationForHumans() }}
                                    </span>
                                    @if ($filename)
                                        <span class="truncate font-mono text-xs text-gray-400 dark:text-gray-500">{{ $filename }}</span>
                                    @endif
                                </div>

                                @if ($stage->message)
                                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ $stage->message }}</p>
                                @endif

                                @if ($stage->error)
                                    <p class="text-xs text-danger-600 dark:text-danger-400">{{ $stage->error }}</p>
                                @endif

                                @if (! empty($stage->detail_json))
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                            detalle
                                        </summary>
                                        <pre class="mt-1 max-h-56 overflow-auto rounded bg-gray-50 p-2 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ json_encode($stage->detail_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </div>
                        </li>

                        @if ($filename)
                            @php $lastAttachmentId = $filename; @endphp
                        @endif
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endif