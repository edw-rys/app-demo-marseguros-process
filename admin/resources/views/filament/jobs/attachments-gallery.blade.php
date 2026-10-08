{{--
    Galería simple y limpia de archivos adjuntos (sin datos técnicos ni JSONs).
--}}
@php
    use Illuminate\Support\Str;
    $availableDownloadsCount = $attachments->filter(fn ($a) => $a->fileExists())->count();
    $totalCount = $attachments->count();
@endphp

<div class="gdv-att-gallery">
    {{-- ── Barra Superior con Resumen y Descargar Todo ── --}}
    <div class="gdv-att-toolbar">
        <div class="gdv-att-toolbar__left">
            <span class="gdv-att-toolbar__title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px; color: #0284c7;">
                    <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
                {{ $totalCount }} {{ $totalCount === 1 ? 'Archivo Adjunto' : 'Archivos Adjuntos' }}
                @if ($availableDownloadsCount > 0)
                    <span style="font-size: 0.75rem; color: #10b981; margin-left: 0.5rem;">({{ $availableDownloadsCount }} en disco)</span>
                @endif
            </span>
        </div>

        @if ($availableDownloadsCount > 0)
            <a
                href="{{ route('jobs.attachments.download-all', ['uuid' => $email->uuid]) }}"
                class="gdv-btn-download-all"
                title="Descargar todos los archivos adjuntos en un solo archivo ZIP"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width: 16px; height: 16px;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Descargar Todo (.ZIP)
            </a>
        @endif
    </div>

    {{-- ── Lista de Archivos Adjuntos ── --}}
    @if ($totalCount === 0)
        <div class="gdv-att-empty">
            <p>No se recibieron archivos adjuntos en este correo.</p>
        </div>
    @else
        <div class="gdv-att-grid">
            @foreach ($attachments as $att)
                @php
                    $modalId = 'gdv-att-modal-' . $att->id;
                    $ext = strtolower(pathinfo($att->filename, PATHINFO_EXTENSION));

                    $docType = $att->doc_type;
                    $isUnknown = empty($docType) || $docType === 'desconocido';

                    $iconTheme = match ($ext) {
                        'pdf' => ['bg' => '#ef4444', 'label' => 'PDF'],
                        'jpg', 'jpeg', 'png', 'webp' => ['bg' => '#8b5cf6', 'label' => 'IMG'],
                        'doc', 'docx' => ['bg' => '#0284c7', 'label' => 'DOC'],
                        'xls', 'xlsx' => ['bg' => '#10b981', 'label' => 'XLS'],
                        default => ['bg' => '#64748b', 'label' => strtoupper($ext ?: 'FILE')],
                    };
                @endphp

                <div class="gdv-att-card-wrapper">
                    <div
                        class="gdv-att-card"
                        data-gdv-open="{{ $modalId }}"
                        onclick="var m = document.getElementById('{{ $modalId }}'); if(m){ if(m.parentElement !== document.body) document.body.appendChild(m); m.style.display = 'flex'; m.classList.add('is-open'); }"
                        role="button"
                        tabindex="0"
                        title="Clic para ver detalles de {{ $att->filename }}"
                        style="cursor: pointer;"
                    >
                        <!-- Cabecera de la Tarjeta -->
                        <div class="gdv-att-card__head">
                            <div class="gdv-att-card__icon" style="background: {{ $iconTheme['bg'] }};">
                                <span>{{ $iconTheme['label'] }}</span>
                            </div>

                            <span class="gdv-att-badge @if ($isUnknown) gdv-att-badge--unknown @else gdv-att-badge--doctype @endif">
                                {{ \App\Support\DocumentTypes::label($isUnknown ? null : $docType) }}
                            </span>
                        </div>

                        <!-- Cuerpo de la Tarjeta -->
                        <div class="gdv-att-card__body">
                            <h4 class="gdv-att-card__name" title="{{ $att->filename }}">
                                {{ $att->filename }}
                            </h4>

                            <div class="gdv-att-card__meta">
                                <span>{{ $att->sizeForHumans() }}</span>
                            </div>
                        </div>

                        <!-- Botones de Acción -->
                        <div class="gdv-att-card__foot" onclick="event.stopPropagation()">
                            <button
                                type="button"
                                class="gdv-att-btn gdv-att-btn--view"
                                data-gdv-open="{{ $modalId }}"
                                onclick="var m = document.getElementById('{{ $modalId }}'); if(m){ if(m.parentElement !== document.body) document.body.appendChild(m); m.style.display = 'flex'; m.classList.add('is-open'); }"
                            >
                                Ver Detalle
                            </button>

                            @if ($att->fileExists())
                                <a
                                    href="{{ route('attachments.download', ['uuid' => $att->uuid]) }}"
                                    class="gdv-att-btn gdv-att-btn--dl"
                                    title="Descargar archivo"
                                >
                                    Descargar
                                </a>
                            @endif
                        </div>
                    </div>

                    <!-- ── Modal Simple del Archivo Directo ── -->
                    <div
                        id="{{ $modalId }}"
                        class="gdv-modal-backdrop"
                        style="display: none;"
                        onclick="if (event.target === this) { this.style.display = 'none'; this.classList.remove('is-open'); }"
                    >
                        <div class="gdv-modal-card" onclick="event.stopPropagation()">
                            <!-- Header del Modal -->
                            <div class="gdv-modal-header">
                                <div class="gdv-modal-title-wrap">
                                    <div class="gdv-modal-icon" style="background: {{ $iconTheme['bg'] }}; color: #fff; font-weight: 800; font-size: 0.8125rem; width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                                        {{ $iconTheme['label'] }}
                                    </div>
                                    <div>
                                        <h4 class="gdv-modal-title" style="word-break: break-all;">{{ $att->filename }}</h4>
                                        <span class="gdv-att-badge @if ($isUnknown) gdv-att-badge--unknown @else gdv-att-badge--doctype @endif" style="margin-top: 4px; display: inline-block;">
                                            {{ $isUnknown ? 'Documento no clasificado' : strtoupper($docType) }}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="gdv-modal-close"
                                    data-gdv-close="{{ $modalId }}"
                                    onclick="var m = document.getElementById('{{ $modalId }}'); if(m){ m.style.display = 'none'; m.classList.remove('is-open'); }"
                                    title="Cerrar"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px;">
                                        <line x1="18" y1="6" x2="6" y2="18"></line>
                                        <line x1="6" y1="6" x2="18" y2="18"></line>
                                    </svg>
                                </button>
                            </div>

                            <!-- Body del Modal -->
                            <div class="gdv-modal-body">
                                <dl class="gdv-info-grid" style="grid-template-columns: repeat(2, 1fr);">
                                    <div class="gdv-info-item">
                                        <dt>Tamaño</dt>
                                        <dd>{{ $att->sizeForHumans() }}</dd>
                                    </div>
                                    <div class="gdv-info-item">
                                        <dt>Tipo de documento</dt>
                                        <dd>{{ \App\Support\DocumentTypes::label($att->doc_type) }}</dd>
                                    </div>
                                </dl>

                                <!-- Texto extraído si existe -->
                                @if (!empty($att->extracted_text))
                                    <div>
                                        <h5 style="font-size: 0.8125rem; font-weight: 700; color: #f8fafc; margin: 0 0 0.375rem;">
                                            Contenido detectado:
                                        </h5>
                                        <div class="gdv-extracted-text-box" style="background: #10141d; padding: 0.75rem; border-radius: 6px; border: 1px solid #283548; font-family: monospace; font-size: 0.75rem; color: #cbd5e1; max-height: 200px; overflow-y: auto; white-space: pre-wrap;">
                                            {{ Str::limit($att->extracted_text, 1000) }}
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <!-- Footer del Modal -->
                            <div class="gdv-modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                                @if ($att->fileExists())
                                    <a
                                        href="{{ route('attachments.download', ['uuid' => $att->uuid]) }}"
                                        class="gdv-btn-download"
                                    >
                                        Descargar Archivo
                                    </a>
                                @else
                                    <span style="font-size: 0.75rem; color: #94a3b8;">No disponible en disco</span>
                                @endif

                                <button
                                    type="button"
                                    class="gdv-btn-close"
                                    data-gdv-close="{{ $modalId }}"
                                    onclick="var m = document.getElementById('{{ $modalId }}'); if(m){ m.style.display = 'none'; m.classList.remove('is-open'); }"
                                >
                                    Cerrar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
