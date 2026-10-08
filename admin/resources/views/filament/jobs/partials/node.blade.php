{{--
    Un paso del pipeline estilo CI/CD (GitLab / GitHub Actions pipeline row).
    
    @var \App\Enums\PipelineStage $stage
    @var string                    $status      passed|failed|degraded|skipped|running|pending
    @var int                       $ms
    @var int                       $count       cuántos adjuntos participaron en la etapa
    @var \App\Models\Attachment|null $attachment
    @var array                     $executions  los JobStage que representan este nodo
--}}
@php
    use Illuminate\Support\Str;

    $statusLabel = match ($status) {
        'passed'   => 'Completada',
        'failed'   => 'Falló',
        'degraded' => 'Degradada',
        'running'  => 'En ejecución',
        'skipped'  => 'Omitida',
        default    => 'Pendiente',
    };

    $statusSubtitle = match ($status) {
        'passed'   => ($ms < 1000 ? $ms.' ms' : round($ms / 1000, 2).' s'),
        'failed'   => 'Falló' . ($ms > 0 ? ' · ' . ($ms < 1000 ? $ms.' ms' : round($ms / 1000, 2).' s') : ''),
        'degraded' => 'Degradado',
        'running'  => 'Ejecutando…',
        'skipped'  => 'Omitida',
        default    => 'Pendiente',
    };

    $nodeModalKey = 'gdv-modal-' . $stage->value . '-' . ($attachment?->id ?? 'main') . '-' . uniqid();

    $errors = array_values(array_filter(
        $executions ?? [],
        fn ($s): bool => filled($s->error) || $s->status === 'failed',
    ));
    $messages = array_values(array_filter(
        $executions ?? [],
        fn ($s): bool => blank($s->error) && filled($s->message),
    ));

    $humanizeText = function (?string $raw): string {
        if (empty($raw)) return 'Error en la ejecución de la etapa.';
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (isset($decoded['error']['message'])) {
                $m = $decoded['error']['message'];
                if (stripos($m, 'invalid attachment token') !== false) {
                    return 'El token de descarga del archivo adjunto en Gmail no es válido o expiró.';
                }
                return $m;
            }
        }
        if (stripos($raw, 'invalid attachment token') !== false) {
            return 'El token de descarga del archivo adjunto en Gmail no es válido o expiró.';
        }
        return Str::limit(strip_tags($raw), 160);
    };
@endphp

<div x-data="{ open: false }" class="gdv-node-wrapper">
    <!-- Fila del paso interactiva con Alpine y vanilla click -->
    <div
        class="gdv-pipeline-step gdv-pipeline-step--{{ $status }}"
        @click="open = true"
        role="button"
        tabindex="0"
        title="Clic para ver detalle de {{ $stage->label() }}"
    >
        <!-- Left: Status Icon (Verde si pasó, Rojo si falló, Gris por defecto) -->
        <div class="gdv-step-status gdv-step-status--{{ $status }}">
            @if ($status === 'passed')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            @elseif ($status === 'failed')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            @elseif ($status === 'degraded')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            @elseif ($status === 'running')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="gdv-spin"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="10"/></svg>
            @else
                {{-- Gris / Pendiente / Omitida --}}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            @endif
        </div>

        <!-- Center: Title & Duration -->
        <div class="gdv-step-info">
            <span class="gdv-step-title">{{ $stage->label() }}</span>
            <span class="gdv-step-sub @if ($status === 'failed') gdv-step-sub--error @endif">{{ $statusSubtitle }}</span>
        </div>

        <!-- Right: Action / Play Icon -->
        <div class="gdv-step-action">
            <svg viewBox="0 0 24 24" fill="currentColor"><polygon points="6 4 18 12 6 20 6 4"/></svg>
        </div>
    </div>

    <!-- Modal Teleportado a Body (sin interferencia de overflow o transforms) -->
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            id="{{ $nodeModalKey }}"
            class="gdv-modal-backdrop is-open"
            @click.self="open = false"
            @keydown.escape.window="open = false"
        >
            <div class="gdv-modal-card" @click.stop>
                <!-- Header -->
                <div class="gdv-modal-header">
                    <div class="gdv-modal-title-wrap">
                        <div class="gdv-step-status gdv-step-status--{{ $status }}" style="width: 28px; height: 28px;">
                            @if ($status === 'passed')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            @elseif ($status === 'failed')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>
                            @endif
                        </div>
                        <div>
                            <h4 class="gdv-modal-title">{{ $stage->label() }}</h4>
                            <span class="gdv-status-pill gdv-status-pill--{{ $status }}">{{ $statusLabel }}</span>
                        </div>
                    </div>
                    <button type="button" class="gdv-modal-close" @click="open = false" title="Cerrar (Esc)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px;">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="gdv-modal-body">
                    <dl class="gdv-info-grid">
                        <div class="gdv-info-item">
                            <dt>Estado</dt>
                            <dd class="@if ($status === 'failed') gdv-text--error @elseif ($status === 'passed') gdv-text--ok @else gdv-text--muted @endif">
                                {{ $statusLabel }}
                            </dd>
                        </div>
                        <div class="gdv-info-item">
                            <dt>Duración</dt>
                            <dd>{{ $status === 'pending' ? '—' : ($ms < 1000 ? $ms.' ms' : round($ms / 1000, 2).' s') }}</dd>
                        </div>
                        <div class="gdv-info-item">
                            <dt>Ejecuciones</dt>
                            <dd>{{ count($executions ?? []) > 0 ? count($executions).' vez' : '0' }}</dd>
                        </div>
                    </dl>

                    <div class="gdv-desc-box">
                        {{ $stage->description() }}
                    </div>

                    @if (!empty($errors))
                        <div class="gdv-error-alert">
                            <div style="font-weight: 700; margin-bottom: 4px;">⚠️ Error en esta etapa:</div>
                            @foreach ($errors as $failed)
                                <p style="margin: 0; font-size: 0.8125rem;">
                                    {{ $humanizeText($failed->error ?: $failed->message) }}
                                </p>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($messages as $noted)
                        <div style="font-size: 0.8125rem; color: #94a3b8; background: #1e293b; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid #334155;">
                            ℹ️ {{ $noted->message }}
                        </div>
                    @endforeach

                    @if ($attachment !== null)
                        <div class="gdv-file-box">
                            <div>
                                <div class="gdv-file-name">📄 {{ $attachment->filename }}</div>
                                <div class="gdv-file-meta">
                                    {{ $attachment->sizeForHumans() }}
                                    @if ($attachment->doc_type) · <strong>{{ strtoupper($attachment->doc_type) }}</strong> @endif
                                </div>
                            </div>
                            @if ($attachment->fileExists())
                                <a class="gdv-btn-download" href="{{ route('attachments.download', ['uuid' => $attachment->uuid]) }}" download>
                                    Descargar
                                </a>
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Footer -->
                <div class="gdv-modal-footer">
                    <button type="button" class="gdv-btn-close" @click="open = false">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>