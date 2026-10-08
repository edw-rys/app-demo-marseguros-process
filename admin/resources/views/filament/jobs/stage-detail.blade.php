@php
    use Illuminate\Support\Str;

    $stageTitle = $pipelineStage?->label() ?? ucwords(str_replace(['_', '-'], ' ', $stageKey));
    $stageDesc  = $pipelineStage?->description() ?? '';

    $status = $stage?->status ?? 'pending';
    $statusLabel = match ($status) {
        'passed'   => 'Completada',
        'failed'   => 'Falló',
        'degraded' => 'Degradada',
        'running'  => 'En ejecución',
        'skipped'  => 'Omitida',
        default    => 'Pendiente',
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
        return Str::limit(strip_tags($raw), 300);
    };

    $detail = $stage?->detail_json ?? [];
@endphp
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $stageTitle }} — Detalle de Etapa | Marseguros</title>
    <link rel="stylesheet" href="{{ asset('css/gdv-pipeline.css') }}">
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #0f141c;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .stage-page-header {
            background: #18202f;
            border-bottom: 1px solid #283548;
            padding: 0.875rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .stage-page-header a, .stage-page-header button {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: #283548;
            color: #f8fafc;
            padding: 0.4rem 0.875rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #3b4d66;
            cursor: pointer;
            transition: background 0.15s;
        }

        .stage-page-header a:hover, .stage-page-header button:hover {
            background: #3b4d66;
        }

        .stage-container {
            max-width: 900px;
            width: 100%;
            margin: 1.5rem auto;
            padding: 0 1.5rem;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .stage-hero-card {
            background: #18202f;
            border: 1px solid #283548;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.25rem;
        }

        .stage-hero-main {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stage-hero-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stage-hero-icon--passed { border: 2.5px solid #10b981; color: #10b981; background: rgba(16, 185, 129, 0.15); }
        .stage-hero-icon--failed { border: 2.5px solid #ef4444; color: #ef4444; background: rgba(239, 68, 68, 0.15); }
        .stage-hero-icon--running { border: 2.5px solid #0284c7; color: #0284c7; background: rgba(2, 132, 199, 0.15); }
        .stage-hero-icon--pending, .stage-hero-icon--skipped { border: 2.5px solid #64748b; color: #94a3b8; background: transparent; }

        .stage-hero-icon svg { width: 24px; height: 24px; }

        .stage-hero-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
        }

        .stage-hero-sub {
            margin: 0.25rem 0 0;
            font-size: 0.8125rem;
            color: #94a3b8;
        }

        .stage-section-card {
            background: #18202f;
            border: 1px solid #283548;
            border-radius: 10px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .stage-section-title {
            font-size: 0.9375rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stage-grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }

        .stage-info-cell {
            background: #10141d;
            border: 1px solid #283548;
            border-radius: 6px;
            padding: 0.625rem 0.875rem;
        }

        .stage-info-cell dt {
            font-size: 0.6875rem;
            color: #94a3b8;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .stage-info-cell dd {
            margin: 0;
            font-size: 0.875rem;
            color: #f8fafc;
            font-weight: 600;
            word-break: break-all;
        }

        .stage-json-box {
            background: #0b0e14;
            border: 1px solid #283548;
            border-radius: 8px;
            padding: 1rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8125rem;
            color: #38bdf8;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <header class="stage-page-header">
        <a href="{{ route('filament.admin.resources.jobs.view', ['record' => $email->id]) }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><polyline points="15 18 9 12 15 6"/></svg>
            Volver al Job #{{ $email->id }}
        </a>

        <div style="font-size: 0.875rem; font-weight: 700; color: #cbd5e1;">
            Marseguros Docs Validator
        </div>

        <button type="button" onclick="window.close()">
            Cerrar pestaña ✕
        </button>
    </header>

    <main class="stage-container">
        <!-- Hero de la Etapa -->
        <div class="stage-hero-card">
            <div class="stage-hero-main">
                <div class="stage-hero-icon stage-hero-icon--{{ $status }}">
                    @if ($status === 'passed')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    @elseif ($status === 'failed')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    @elseif ($status === 'running')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="gdv-spin"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="10"/></svg>
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>
                    @endif
                </div>
                <div>
                    <h1 class="stage-hero-title">{{ $stageTitle }}</h1>
                    <p class="stage-hero-sub">{{ $stageDesc ?: 'Detalle de ejecución y datos capturados.' }}</p>
                </div>
            </div>

            <span class="gdv-status-pill gdv-status-pill--{{ $status }}" style="font-size: 0.8125rem; padding: 0.3rem 0.75rem;">
                {{ $statusLabel }}
            </span>
        </div>

        <!-- Alerta de Error si aplica -->
        @if ($stage && filled($stage->error))
            <div class="gdv-error-alert" style="padding: 1rem 1.25rem;">
                <h4 style="margin: 0 0 0.35rem; font-size: 0.9375rem;">⚠️ Error en esta etapa:</h4>
                <p style="margin: 0; font-size: 0.875rem;">{{ $cleanErrorText($stage->error) }}</p>
            </div>
        @endif

        <!-- Observación / Mensaje -->
        @if ($stage && filled($stage->message))
            <div style="background: #18202f; border: 1px solid #3b82f6; border-left: 4px solid #3b82f6; padding: 0.875rem 1rem; border-radius: 8px; font-size: 0.875rem; color: #e2e8f0;">
                💬 {{ $stage->message }}
            </div>
        @endif

        <!-- Datos del Job y Archivo -->
        <div class="stage-section-card">
            <h3 class="stage-section-title">
                📋 Contexto de Ejecución
            </h3>
            <dl class="stage-grid-2" style="margin: 0;">
                <div class="stage-info-cell">
                    <dt>Asunto del Correo</dt>
                    <dd>{{ $email->subject ?: '—' }}</dd>
                </div>
                <div class="stage-info-cell">
                    <dt>Remitente</dt>
                    <dd>{{ $email->sender ?: '—' }}</dd>
                </div>
                <div class="stage-info-cell">
                    <dt>Duración</dt>
                    <dd>{{ $stage?->durationForHumans() ?? '—' }}</dd>
                </div>
                <div class="stage-info-cell">
                    <dt>Ejecución / Secuencia</dt>
                    <dd>Ejecución #{{ $stage?->run ?? 1 }} · Secuencia #{{ $stage?->sequence ?? 1 }}</dd>
                </div>
            </dl>
        </div>

        <!-- Archivo Adjunto si aplica -->
        @if ($attachment !== null)
            <div class="stage-section-card">
                <h3 class="stage-section-title">
                    📄 Archivo Adjunto Asociado
                </h3>
                <div style="display: flex; align-items: center; justify-content: space-between; background: #10141d; border: 1px solid #283548; padding: 0.875rem 1rem; border-radius: 8px;">
                    <div>
                        <div style="font-weight: 700; color: #f8fafc; font-size: 0.9375rem;">{{ $attachment->filename }}</div>
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.2rem;">
                            {{ $attachment->sizeForHumans() }} · Tipo: <strong>{{ strtoupper($attachment->doc_type ?: 'Sin clasificar') }}</strong>
                        </div>
                    </div>
                    @if ($attachment->fileExists())
                        <a href="{{ route('attachments.download', ['uuid' => $attachment->uuid]) }}" class="gdv-btn-download">
                            Descargar Archivo
                        </a>
                    @endif
                </div>
            </div>
        @endif

        <!-- Metadatos y Datos Capturados -->
        <div class="stage-section-card">
            <h3 class="stage-section-title">
                🔍 Metadatos y Datos Capturados
            </h3>
            @if (!empty($detail))
                <div class="gdv-meta-tags-wrap" style="margin-bottom: 0.75rem;">
                    @foreach ($detail as $k => $v)
                        @php
                            if (in_array($k, ['raw_request', 'raw_response', 'text'], true)) continue;
                            $formattedVal = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'Sí' : 'No') : (string) $v);
                            $formattedKey = ucwords(str_replace(['_', '-'], ' ', $k));
                        @endphp
                        <div class="gdv-meta-tag" style="padding: 0.35rem 0.65rem;">
                            <div class="gdv-meta-tag__header-line">
                                <span class="gdv-meta-tag__key">{{ $formattedKey }}:</span>
                                <span class="gdv-meta-tag__val">{{ Str::limit($formattedVal, 80) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <h4 style="font-size: 0.8125rem; color: #94a3b8; margin: 0.5rem 0 0.25rem;">Estructura JSON Completa:</h4>
                <div class="stage-json-box">
                    {{ json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}
                </div>
            @else
                <div style="color: #64748b; font-size: 0.8125rem;">
                    Esta etapa no generó metadatos estructurados adicionales.
                </div>
            @endif
        </div>
    </main>
</body>
</html>
