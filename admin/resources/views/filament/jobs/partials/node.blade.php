{{--
    Un paso del pipeline estilo CI/CD (GitLab / GitHub Actions pipeline row).
    Al pulsar, abre una pestaña nueva con el detalle completo de la etapa.
    
    @var \App\Enums\PipelineStage $stage
    @var string                    $status      passed|failed|degraded|skipped|running|pending
    @var int                       $ms
    @var int                       $count       cuántos adjuntos participaron en la etapa
    @var \App\Models\Attachment|null $attachment
    @var array                     $executions  los JobStage que representan este nodo
    @var \App\Models\ProcessedEmail $email
--}}
@php
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

    $stageUrl = isset($email)
        ? route('jobs.stage.show', [
            'uuid'          => $email->uuid,
            'stage_key'     => $stage->value,
            'attachment_id' => $attachment?->id,
        ])
        : '#';
@endphp

<div class="gdv-node-wrapper">
    <!-- Fila del paso interactiva: enlace a ventana nueva con el detalle -->
    <a
        href="{{ $stageUrl }}"
        target="_blank"
        class="gdv-pipeline-step gdv-pipeline-step--{{ $status }}"
        title="Abrir detalle de {{ $stage->label() }} en pestaña nueva"
        style="text-decoration: none;"
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
    </a>
</div>