{{--
    Grafo de pipeline estilo CI/CD (GitLab / GitHub Actions / ArgoCD).
--}}
@php
    use App\Enums\PipelineStage;
    use Illuminate\Support\Str;

    $cardOf = function (PipelineStage $case, array $source, ?\App\Models\Attachment $attachment = null): array {
        $list = $source[$case->value] ?? [];
        $statuses = array_column($list, 'status');

        $status = match (true) {
            $list === []                              => 'pending',
            in_array('failed', $statuses, true)      => 'failed',
            in_array('running', $statuses, true)     => 'running',
            in_array('degraded', $statuses, true)    => 'degraded',
            array_unique($statuses) === ['skipped']  => 'skipped',
            default                                   => 'passed',
        };

        return [
            'stage'      => $case,
            'status'     => $status,
            'ms'         => (int) array_sum(array_column($list, 'duration_ms')),
            'count'      => count($list),
            'executions' => $list,
            'attachment' => $attachment,
        ];
    };

    $cleanErrorText = function (?string $raw): string {
        if (empty($raw)) return 'Se produjo un problema durante la ejecución de esta etapa.';
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (isset($decoded['error']['message'])) {
                $m = $decoded['error']['message'];
                if (stripos($m, 'invalid attachment token') !== false) {
                    return 'El token del archivo adjunto en Gmail no es válido o ya expiró.';
                }
                return $m;
            }
        }
        if (stripos($raw, 'invalid attachment token') !== false) {
            return 'El token del archivo adjunto en Gmail no es válido o ya expiró.';
        }
        return Str::limit(strip_tags($raw), 150);
    };
@endphp

@once
    <style>
        {!! file_get_contents(public_path('css/gdv-pipeline.css')) !!}
    </style>
    <link rel="stylesheet" href="{{ asset('css/gdv-pipeline.css') }}">
@endonce

<div class="gdv">
    @if ($run === null)
        <div class="gdv-empty">
            <p class="gdv-empty__title">Este correo todavía no tiene etapas registradas.</p>
            <p class="gdv-empty__hint">Aparecerá el flujo en cuanto comience el procesamiento.</p>
        </div>
    @else
        @php
            $byKey = [];
            foreach ($run['stages'] as $stage) {
                $byKey[$stage->stage_key][] = $stage;
            }

            // Grupos de etapas
            $receptionCases = [
                PipelineStage::Received,
                PipelineStage::SubjectFilter,
            ];

            $downloadCases = [
                PipelineStage::Download,
            ];

            $perFileCases = [
                PipelineStage::DetectKind,
                PipelineStage::ExtractText,
                PipelineStage::Classify,
                PipelineStage::ExtractFields,
            ];

            $validationCases = [
                PipelineStage::Phase1Count,
                PipelineStage::Phase2Validate,
            ];

            $finishCases = [
                PipelineStage::Reply,
                PipelineStage::Done,
            ];

            // Tarjetas por grupo
            $receptionCards = array_map(fn (PipelineStage $c): array => $cardOf($c, $byKey), $receptionCases);
            $downloadCards  = array_map(fn (PipelineStage $c): array => $cardOf($c, $byKey), $downloadCases);
            $linearPerFileCards = array_map(fn (PipelineStage $c): array => $cardOf($c, $byKey), $perFileCases);
            $validationCards = array_map(fn (PipelineStage $c): array => $cardOf($c, $byKey), $validationCases);
            $finishCards     = array_map(fn (PipelineStage $c): array => $cardOf($c, $byKey), $finishCases);

            // Verificar si hay ramas por adjunto
            $lanes = [];
            foreach ($run['stages'] as $stage) {
                if (! $stage->stageEnum()?->isPerAttachment() || ! $stage->attachment) {
                    continue;
                }
                $attachment = $stage->attachment;
                $key = $attachment->getKey();
                $lanes[$key] ??= ['attachment' => $attachment, 'byKey' => []];
                $lanes[$key]['byKey'][$stage->stage_key][] = $stage;
            }

            $hasBranching = count($lanes) > 0;
            $visibleLanes = array_slice($lanes, 0, 4, true);

            // ── Documentos requeridos que NO llegaron ──────────────────────
            //
            // Se leen del `ValidationResult` de Fase 1, no se recalculan acá:
            // el pipeline ya decidió qué falta (con su corredor deducido del
            // asunto), y recalcularlo en la vista correría el riesgo de
            // contradecirlo. Además evita duplicar la lógica de corredor.
            $missingDocs = [];
            $requiredDocs = [];

            foreach ($email?->validationResults ?? [] as $result) {
                if ($result->code !== 'FALTAN_DOCUMENTOS') {
                    continue;
                }

                $missingDocs = $result->facts_json['missing'] ?? [];
                $requiredDocs = $result->facts_json['required'] ?? [];

                break;
            }

            $missingLabels = \App\Support\DocumentTypes::labels($missingDocs);

            $totalMs = (int) $run['total_ms'];
            $humanTotal = $totalMs < 1000
                ? $totalMs.' ms'
                : rtrim(rtrim(number_format($totalMs / 1000, 2, ',', ''), '0'), ',').' s';

            $canvasId = 'gdv-canvas-' . ($run['number'] ?? 1) . '-' . uniqid();

            // ── Diagnóstico y Resultado Final ──
            $failedStage = collect($run['stages'])->firstWhere('status', 'failed');
            $validationErrors = $email?->validationResults?->whereIn('status', ['fail', 'error']) ?? collect();
            $attachmentsCount = $email?->attachments?->count() ?? count($lanes);

            if ($run['failed'] || ($email?->status === 'failed' && $failedStage !== null)) {
                $resultType = 'failed';
                $resultBadgeText = 'Procesamiento detenido';
                $resultTitle = $failedStage ? 'No se completó: ' . $failedStage->label() : 'El proceso no pudo completarse';
                $resultExplanation = $cleanErrorText($failedStage?->error ?: $failedStage?->message);
            } elseif ($email?->status === 'validated' || ($run['complete'] && $validationErrors->isEmpty())) {
                $resultType = 'success';
                $resultBadgeText = 'Documentación aprobada';
                $resultTitle = 'Todos los documentos fueron validados correctamente';
                $resultExplanation = 'El correo y sus adjuntos cumplen con los requisitos solicitados.';
            } elseif ($email?->status === 'review' || $validationErrors->isNotEmpty()) {
                $resultType = 'warning';
                $resultBadgeText = 'Requiere revisión';
                $resultTitle = 'Documentos incompletos o con observaciones';
                $resultExplanation = $validationErrors->isNotEmpty()
                    ? 'Observaciones: ' . $validationErrors->pluck('message')->filter()->implode(' · ')
                    : 'Faltan documentos requeridos para completar la validación.';
            } else {
                $resultType = 'info';
                $resultBadgeText = 'En proceso';
                $resultTitle = 'Analizando documentos del correo';
                $resultExplanation = 'Las etapas se están ejecutando en tiempo real.';
            }
        @endphp

        <div class="gdv-run">
            {{-- ── 1. Resumen del Resultado ── --}}
            <div class="gdv-hero gdv-hero--{{ $resultType }}">
                <div class="gdv-hero__main">
                    <div class="gdv-hero__icon">
                        @if ($resultType === 'success')
                            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        @elseif ($resultType === 'failed')
                            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        @endif
                    </div>

                    <div class="gdv-hero__content">
                        <div class="gdv-hero__eyebrow">
                            <span class="gdv-hero__badge gdv-hero__badge--{{ $resultType }}">
                                {{ $resultBadgeText }}
                            </span>
                        </div>

                        <h3 class="gdv-hero__title">
                            {{ $resultTitle }}
                        </h3>

                        <p class="gdv-hero__desc">
                            {{ $resultExplanation }}
                        </p>
                    </div>
                </div>

                <div class="gdv-hero__stats">
                    <div class="gdv-hero__stat-card">
                        <span class="gdv-hero__stat-label">Adjuntos</span>
                        <span class="gdv-hero__stat-val">{{ $attachmentsCount }} {{ $attachmentsCount === 1 ? 'archivo' : 'archivos' }}</span>
                    </div>

                    <div class="gdv-hero__stat-card">
                        <span class="gdv-hero__stat-label">Duración</span>
                        <span class="gdv-hero__stat-val">{{ $humanTotal }}</span>
                    </div>

                    <div class="gdv-hero__stat-card">
                        <span class="gdv-hero__stat-label">Ejecución</span>
                        <span class="gdv-hero__stat-val">Ejecución #{{ (int) $run['number'] }} · {{ $run['started']?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- ── 2. Canvas de Flujo Estilo CI/CD ── --}}
            <div class="gdv-canvas-container" id="{{ $canvasId }}">
                <!-- Controles de Zoom -->
                <div class="gdv-canvas-controls">
                    <button type="button" class="gdv-btn-ctrl" onclick="window.__gdvZoom('{{ $canvasId }}', 0.1)" title="Acercar (+)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                    <button type="button" class="gdv-btn-ctrl" onclick="window.__gdvZoom('{{ $canvasId }}', -0.1)" title="Alejar (-)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                    <button type="button" class="gdv-btn-ctrl" onclick="window.__gdvResetZoom('{{ $canvasId }}')" title="Restablecer">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    </button>
                </div>

                <div class="gdv-pipeline-flow" id="{{ $canvasId }}-workflow">
                    {{-- Columna 1: Recepción --}}
                    <div class="gdv-stage-column">
                        <div class="gdv-stage-column__header">
                            <span class="gdv-stage-column__title">Recepción</span>
                        </div>
                        <div class="gdv-stage-column__body">
                            @foreach ($receptionCards as $card)
                                @include('filament.jobs.partials.node', [
                                    'stage'      => $card['stage'],
                                    'status'     => $card['status'],
                                    'ms'         => $card['ms'],
                                    'count'      => $card['count'],
                                    'executions' => $card['executions'],
                                    'attachment' => $card['attachment'],
                                    'email'      => $email,
                                ])
                            @endforeach
                        </div>
                    </div>

                    <div class="gdv-pipeline-link"></div>

                    {{-- Columna 2: Descarga --}}
                    <div class="gdv-stage-column">
                        <div class="gdv-stage-column__header">
                            <span class="gdv-stage-column__title">Descarga</span>
                        </div>
                        <div class="gdv-stage-column__body">
                            @foreach ($downloadCards as $card)
                                @include('filament.jobs.partials.node', [
                                    'stage'      => $card['stage'],
                                    'status'     => $card['status'],
                                    'ms'         => $card['ms'],
                                    'count'      => $card['count'],
                                    'executions' => $card['executions'],
                                    'attachment' => $card['attachment'],
                                    'email'      => $email,
                                ])
                            @endforeach
                        </div>
                    </div>

                    <div class="gdv-pipeline-link"></div>

                    {{-- Columna 3: Análisis Documental --}}
                    <div class="gdv-stage-column">
                        <div class="gdv-stage-column__header">
                            <span class="gdv-stage-column__title">Análisis de Documentos</span>
                        </div>
                        <div class="gdv-stage-column__body">
                            @if ($hasBranching)
                                @foreach ($visibleLanes as $lane)
                                    <div style="margin-bottom: 0.75rem;">
                                        <div style="font-size: 0.6875rem; color: #94a3b8; font-weight: 700; margin-bottom: 0.35rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;">
                                            📄 {{ $lane['attachment']?->filename ?? 'Archivo' }}
                                        </div>
                                        @foreach ($perFileCases as $fileCase)
                                            @php
                                                $fileCard = $cardOf($fileCase, $lane['byKey'], $lane['attachment']);
                                            @endphp
                                            @include('filament.jobs.partials.node', [
                                                'stage'      => $fileCard['stage'],
                                                'status'     => $fileCard['status'],
                                                'ms'         => $fileCard['ms'],
                                                'count'      => $fileCard['count'],
                                                'executions' => $fileCard['executions'],
                                                'attachment' => $fileCard['attachment'],
                                                'email'      => $email,
                                            ])
                                        @endforeach
                                    </div>
                                @endforeach
                            @else
                                @foreach ($linearPerFileCards as $card)
                                    @include('filament.jobs.partials.node', [
                                        'stage'      => $card['stage'],
                                        'status'     => $card['status'],
                                        'ms'         => $card['ms'],
                                        'count'      => $card['count'],
                                        'executions' => $card['executions'],
                                        'attachment' => $card['attachment'],
                                        'email'      => $email,
                                    ])
                                @endforeach
                            @endif
                        </div>
                    </div>

                    <div class="gdv-pipeline-link"></div>

                    {{-- Columna 4: Validación --}}
                    <div class="gdv-stage-column">
                        <div class="gdv-stage-column__header">
                            <span class="gdv-stage-column__title">Validación</span>
                        </div>
                        <div class="gdv-stage-column__body">
                            @foreach ($validationCards as $card)
                                @include('filament.jobs.partials.node', [
                                    'stage'      => $card['stage'],
                                    'status'     => $card['status'],
                                    'ms'         => $card['ms'],
                                    'count'      => $card['count'],
                                    'executions' => $card['executions'],
                                    'attachment' => $card['attachment'],
                                    'email'      => $email,
                                ])
                            @endforeach
                        </div>
                    </div>

                    <div class="gdv-pipeline-link"></div>

                    {{-- Columna 5: Finalización --}}
                    <div class="gdv-stage-column">
                        <div class="gdv-stage-column__header">
                            <span class="gdv-stage-column__title">Finalización</span>
                        </div>
                        <div class="gdv-stage-column__body">
                            @foreach ($finishCards as $card)
                                @include('filament.jobs.partials.node', [
                                    'stage'      => $card['stage'],
                                    'status'     => $card['status'],
                                    'ms'         => $card['ms'],
                                    'count'      => $card['count'],
                                    'executions' => $card['executions'],
                                    'attachment' => $card['attachment'],
                                    'email'      => $email,
                                ])
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Documentos faltantes ──
                         Va ACÁ, arriba del detalle, y no dentro de la Fase 1
                         del grafo porque no es una etapa: es el resultado de
                         comparar lo recibido contra lo exigido por el
                         corredor. Es lo que contesta «¿puedo procesar esto?»,
                         así que tiene que estar a la vista y no escondido
                         en una sección colapsada. --}}
                    @if ($missingDocs !== [])
                        <div class="gdv-missing">
                            <div class="gdv-missing__head">
                                <span class="gdv-missing__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                                </span>
                                <span class="gdv-missing__title">
                                    Faltan {{ count($missingDocs) }}
                                    {{ count($missingDocs) === 1 ? 'documento' : 'documentos' }}
                                    @if ($requiredDocs !== [])
                                        <span class="gdv-missing__of">de {{ count($requiredDocs) }} requeridos</span>
                                    @endif
                                </span>
                            </div>

                            <div class="gdv-missing__list">
                                @foreach ($missingDocs as $missingDoc)
                                    <span class="gdv-missing__chip">
                                        {{ \App\Support\DocumentTypes::label($missingDoc) }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- ── 3. Panel de Metadatos y Datos Capturados por Etapa ── --}}
            <div class="gdv-metadata-panel">
                <div class="gdv-metadata-header">
                    <div class="gdv-metadata-header__title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px; color: #38bdf8;">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10 9 9 9 8 9"/>
                        </svg>
                        <span>Metadatos y Datos Capturados por Etapa</span>
                    </div>
                    <span class="gdv-metadata-count">
                        {{ count($run['stages']) }} {{ count($run['stages']) === 1 ? 'etapa registrada' : 'etapas registradas' }}
                    </span>
                </div>

                <div class="gdv-metadata-list">
                    @forelse ($run['stages'] as $stg)
                        @php
                            $stgStatus = $stg->status;
                            $stgDetail = $stg->detail_json ?? [];
                        @endphp
                        <div class="gdv-metadata-card">
                            <div
                                class="gdv-metadata-card__head"
                                onclick="this.closest('.gdv-metadata-card').classList.toggle('is-collapsed')"
                                title="Clic para expandir o contraer esta etapa"
                            >
                                <div class="gdv-metadata-card__left">
                                    <div class="gdv-step-status gdv-step-status--{{ $stgStatus }}" style="width: 20px; height: 20px;">
                                        @if ($stgStatus === 'passed')
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        @elseif ($stgStatus === 'failed')
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        @elseif ($stgStatus === 'running')
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="gdv-spin"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="10"/></svg>
                                        @else
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>
                                        @endif
                                    </div>
                                    <span class="gdv-metadata-card__name">{{ $stg->label() }}</span>
                                    @if ($stg->attachment)
                                        <span class="gdv-metadata-card__file">
                                            📄 {{ $stg->attachment->filename }}
                                        </span>
                                    @endif
                                </div>

                                <div class="gdv-metadata-card__right">
                                    <span class="gdv-metadata-card__time">
                                        ⏱️ {{ $stg->durationForHumans() }}
                                    </span>
                                    <span class="gdv-status-pill gdv-status-pill--{{ $stgStatus }}">
                                        {{ $stg->statusEnum()?->label() ?? ucfirst($stgStatus) }}
                                    </span>
                                    <span class="gdv-metadata-card__chevron">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                                    </span>
                                </div>
                            </div>

                            <div class="gdv-metadata-card__body">
                                @if (filled($stg->error))
                                    <div class="gdv-error-alert" style="margin-bottom: 0.5rem; padding: 0.5rem 0.75rem;">
                                        <strong>⚠️ Error:</strong> {{ $cleanErrorText($stg->error) }}
                                    </div>
                                @endif

                                @if (filled($stg->message))
                                    <div style="font-size: 0.8125rem; color: #cbd5e1; margin-bottom: 0.5rem;">
                                        💬 {{ $stg->message }}
                                    </div>
                                @endif

                                @if (!empty($stgDetail))
                                    <div class="gdv-meta-tags-wrap">
                                        @foreach ($stgDetail as $k => $v)
                                            @php
                                                if (in_array($k, ['raw_request', 'raw_response', 'text'], true)) continue;
                                                $isExpandable = is_array($v) || strlen((string)$v) > 35;
                                                $formattedKey = ucwords(str_replace(['_', '-'], ' ', $k));
                                                $compactValString = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'Sí' : 'No') : (string) $v);
                                                $prettyValString = is_array($v) ? json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $v;
                                            @endphp
                                            @if ($isExpandable)
                                                <div
                                                    class="gdv-meta-tag gdv-meta-tag--expandable"
                                                    onclick="this.classList.toggle('is-expanded')"
                                                    title="Clic para expandir / comprimir"
                                                >
                                                    <div class="gdv-meta-tag__header-line">
                                                        <span class="gdv-meta-tag__key">{{ $formattedKey }}:</span>
                                                        <span class="gdv-meta-tag__val gdv-meta-tag__compact">{{ Str::limit($compactValString, 45) }}</span>
                                                        <span class="gdv-meta-tag__expand-hint">
                                                            <span class="gdv-hint-more">➕ Ver más</span>
                                                            <span class="gdv-hint-less">➖ Comprimir</span>
                                                        </span>
                                                    </div>
                                                    <div class="gdv-meta-expanded-content" onclick="event.stopPropagation()">
                                                        <pre style="margin: 0; white-space: pre-wrap; word-break: break-word;">{{ $prettyValString }}</pre>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="gdv-meta-tag">
                                                    <div class="gdv-meta-tag__header-line">
                                                        <span class="gdv-meta-tag__key">{{ $formattedKey }}:</span>
                                                        <span class="gdv-meta-tag__val">{{ $compactValString }}</span>
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @elseif (blank($stg->error) && blank($stg->message))
                                    <div style="font-size: 0.75rem; color: #64748b;">
                                        Paso completado sin metadatos adicionales.
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div style="text-align: center; color: #64748b; padding: 1.5rem; font-size: 0.8125rem;">
                            No hay etapas registradas para esta corrida.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>