{{--
    Timeline en vivo del pipeline.

    Es una CAPA encima del timeline estático (`timeline.blade.php`), no un
    reemplazo: el estático sigue debajo con todo lo que ya había, y el JS
    escribe las etapas nuevas arriba. Si el JS no corre —sin soporte, error de
    red, un filtro de extensión— el timeline de siempre se ve igual.

    El JS va inline a propósito. Son ~150 líneas y necesita vivir en la página
    que lo usa: separarlo en un asset obligaría a agregarlo al build de Filament
    y a invalidar la caché de `/build`, que es `immutable` por un año.
--}}
@php
    // El mismo `Last-Event-ID` que el controller usa como cursor: si el stream
    // reconecta, el `EventSource` lo manda solo y no se repite lo ya pintado.
    $streamUrl = route('jobs.stream', ['uuid' => $record->uuid]);
@endphp

<div id="gdv-live"
     data-stream="{{ $streamUrl }}">

    {{-- Barra de estado: conexión y etapa en curso. --}}
    <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
        <span data-role="dot"
              class="h-2 w-2 rounded-full bg-gray-400"></span>

        <span data-role="conn"
              class="font-medium text-gray-600 dark:text-gray-400">
            Conectando al pipeline…
        </span>

        <span data-role="stage"
              class="hidden items-center gap-1 rounded bg-info-100 px-2 py-0.5 text-info-700 dark:bg-info-900 dark:text-info-300">
            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-info-500"></span>
            <span data-role="stage-label">—</span>
        </span>

        <button type="button"
                data-role="reload"
                class="ml-auto hidden rounded border border-gray-300 px-2 py-0.5 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
            Ver detalle completo
        </button>
    </div>

    {{-- Contenedor de las etapas que llegan por el stream. --}}
    <ul data-role="live-stages" class="space-y-0"></ul>
</div>

@once
    @push('scripts')
        <script>
            window.__gdvLiveTimeline = (function () {
                'use strict';

                // ── Paleta ──────────────────────────────────────────────────
                // Las mismas clases que usa `timeline.blade.php` a mano, para
                // que una etapa en vivo se vea idéntica a una ya guardada. Si
                // se cambia uno hay que cambiar el otro.
                var STATUS = {
                    passed:   { dot: 'bg-success-500', label: 'text-success-700 dark:text-success-400', text: 'OK' },
                    failed:   { dot: 'bg-danger-500',  label: 'text-danger-700 dark:text-danger-400',  text: 'Falló' },
                    degraded: { dot: 'bg-warning-500',  label: 'text-warning-700 dark:text-warning-400',  text: 'Degradado' },
                    skipped:  { dot: 'bg-gray-400',    label: 'text-gray-500 dark:text-gray-400',      text: 'Omitido' },
                    running:  { dot: 'bg-info-500',    label: 'text-info-700 dark:text-info-400',      text: 'Corriendo' }
                };

                var root = document.getElementById('gdv-live');
                if (!root) return null;

                var url = root.dataset.stream;
                var list = root.querySelector('[data-role="live-stages"]');
                var elConn = root.querySelector('[data-role="conn"]');
                var elDot = root.querySelector('[data-role="dot"]');
                var elStage = root.querySelector('[data-role="stage"]');
                var elStageLabel = root.querySelector('[data-role="stage-label"]');
                var elReload = root.querySelector('[data-role="reload"]');

                var source = null;
                // Secuencia ya pintada. Evita duplicados si un evento llega dos
                // veces (la reconexión con `Last-Event-ID` puede repetir el
                // último si el servidor lo mandó justo antes de caer).
                var lastSequence = 0;
                // Adjuntos ya vistos: se agrupan las etapas por adjunto igual que
                // el blade estático, para que se lea como el mismo documento.
                var lastFilename = null;
                // Si el job seguía vivo cuando se abrió esta página. Distingue
                // "lo vi terminar" de "ya estaba terminado", que es lo que evita
                // el bucle de recargas (ver el handler de `fin`).
                var liveAtOpen = false;
                // Marca que ya llegó el `estado` inicial. Sin esto, una
                // reconexión posterior sobrescribiría `liveAtOpen` con el
                // estado en ese momento, que ya es terminal.
                var sawState = false;

                function text(value) { return value == null ? '' : String(value); }

                function el(tag, className, content) {
                    var node = document.createElement(tag);
                    if (className) node.className = className;
                    if (content != null) node.textContent = content;
                    return node;
                }

                function setConn(state, label) {
                    elConn.textContent = label;
                    elDot.className = 'h-2 w-2 rounded-full ' + (
                        state === 'live'      ? 'bg-success-500 animate-pulse' :
                        state === 'reconnecting' ? 'bg-warning-500 animate-pulse' :
                        state === 'error'      ? 'bg-danger-500' :
                                                 'bg-gray-400'
                    );
                }

                function setStage(label) {
                    if (!label) {
                        elStage.classList.add('hidden');
                        elStage.classList.remove('inline-flex');
                        return;
                    }
                    elStage.classList.remove('hidden');
                    elStage.classList.add('inline-flex');
                    elStageLabel.textContent = label;
                }

                // ── Pintado de una etapa ───────────────────────────────────
                function renderStage(stage) {
                    var style = STATUS[stage.status] || STATUS.skipped;

                    // Cabecera de archivo, igual que el blade estático: las
                    // etapas por adjunto (detect_kind, extract_text, classify,
                    // extract_fields) se repetirían sin contexto si no.
                    if (stage.filename && stage.filename !== lastFilename) {
                        var head = el('li', 'flex items-center gap-2 pt-3 pb-1 text-xs font-medium text-gray-500 dark:text-gray-400');
                        head.appendChild(el('span', 'font-mono', stage.filename));
                        list.appendChild(head);
                        lastFilename = stage.filename;
                    }

                    var row = el('li', 'flex items-start gap-3 py-1.5 gdv-live-row');
                    row.dataset.sequence = stage.sequence;

                    var dot = el('span', 'mt-1.5 h-2 w-2 shrink-0 rounded-full ' + style.dot);
                    if (stage.status === 'running') {
                        dot.classList.add('animate-pulse', 'ring-2', 'ring-info-300');
                    }
                    row.appendChild(dot);

                    var body = el('div', 'min-w-0 flex-1');
                    var head2 = el('div', 'flex flex-wrap items-baseline gap-x-2');

                    head2.appendChild(el('span', 'text-sm font-medium text-gray-900 dark:text-gray-100', stage.label));
                    head2.appendChild(el('span', 'text-xs ' + style.label, style.text));

                    if (stage.duration_ms != null) {
                        head2.appendChild(el('span', 'text-xs text-gray-400 dark:text-gray-500', formatMs(stage.duration_ms)));
                    }
                    if (stage.filename) {
                        head2.appendChild(el('span', 'truncate font-mono text-xs text-gray-400 dark:text-gray-500', stage.filename));
                    }

                    body.appendChild(head2);

                    if (stage.message) {
                        body.appendChild(el('p', 'text-xs text-gray-600 dark:text-gray-400', stage.message));
                    }
                    if (stage.error) {
                        body.appendChild(el('p', 'text-xs text-danger-600 dark:text-danger-400', stage.error));
                    }
                    if (stage.description && stage.status === 'running') {
                        // Solo mientras corre: es el texto que explica qué está
                        // pasando ahora mismo.
                        body.appendChild(el('p', 'text-xs text-gray-400 dark:text-gray-500 italic', stage.description));
                    }

                    row.appendChild(body);
                    list.appendChild(row);
                }

                function formatMs(ms) {
                    if (ms == null) return '—';
                    if (ms < 1000) return ms + ' ms';
                    return (ms / 1000).toFixed(2).replace(/\.00$/, '') + ' s';
                }

                // Marca como "terminada" una etapa que venía en `running` y
                // después llegó con su estado final. El server manda la fila de
                // nuevo (misma `sequence`), así que se actualiza en el lugar en
                // vez de duplicarla.
                function updateRunning(sequence) {
                    var previous = list.querySelector('[data-sequence="' + sequence + '"]');
                    if (previous) previous.remove();

                    // Reemplazar en vivo es opcional: si la etapa terminó tan
                    // rápido que el front nunca la vio corriendo, lo simple es
                    // dejarla aparecer ya en su estado final.
                }

                function connect() {
                    if (source) source.close();

                    // `EventSource` reconecta solo cuando el servidor cierra
                    // limpio; si el evento `fin` no llegó (error de red, worker
                    // reiniciado), reconecta solo con el cursor en
                    // `last_event_id`.
                    source = new EventSource(url + (lastSequence ? '?last_event_id=' + lastSequence : ''));
                    source.lastEventId = String(lastSequence);

                    source.addEventListener('open', function () { setConn('live', 'En vivo'); });

                    source.addEventListener('estado', function (e) {
                        var data = JSON.parse(e.data);
                        // El primer `estado` es el estado real del job, no una
                        // reprint de lo que ya sabemos: es el que dice si hay
                        // algo que esperar.
                        if (!sawState) { liveAtOpen = !data.finished; sawState = true; }

                        if (data.current_stage && !data.finished) {
                            setStage(data.current_stage);
                        } else {
                            setStage(null);
                        }
                    });

                    source.addEventListener('etapa', function (e) {
                        var stage = JSON.parse(e.data);
                        if (stage.sequence <= lastSequence) return;
                        lastSequence = stage.sequence;
                        if (stage.status === 'running') {
                            updateRunning(stage.sequence);
                            setStage(stage.label);
                        }
                        renderStage(stage);
                    });

                    source.addEventListener('fin', function () {
                        setStage(null);
                        if (source) { source.close(); source = null; }

                        // Recargar SOLO si se estaba siguiendo el job en vivo.
                        //
                        // Abriendo un job ya terminado, el `fin` llega en el
                        // primer loop del stream — y ahí recargar es un bucle
                        // infinito: la página recargada vuelve a abrir el
                        // stream, reproduce las etapas, emite `fin` otra vez y
                        // recarga. Cada vuelta a los ~600 ms, para siempre.
                        if (!liveAtOpen) {
                            setConn('done', 'Job finalizado · mostrando etapas guardadas');
                            return;
                        }

                        // El detalle completo (adjuntos, resultados, respuesta)
                        // lo arma el server en el render; acá solo hay etapas.
                        // Recargar es lo que lo trae, y es el momento correcto:
                        // el job ya no va a cambiar más.
                        setConn('done', 'Job finalizado · recargando detalle…');
                        setTimeout(function () { window.location.reload(); }, 600);
                    });

                    source.addEventListener('timeout', function (e) {
                        // Corte por `stream.max_seconds`, NO fin del job. Se
                        // cierra para que el `EventSource` reconecte limpio y
                        // el replay siga desde `last_event_id`. Recargar la
                        // página acá estaría mal: el job puede seguir corriendo.
                        if (source) { source.close(); source = null; }
                        setConn('reconnecting', 'Pausa programada · reconectando…');
                        setTimeout(connect, 1000);
                    });

                    source.addEventListener('aviso', function (e) {
                        var data = JSON.parse(e.data);
                        setConn('error', data.message);
                    });

                    source.onerror = function () {
                        // `EventSource` reintenta solo salvo que se haya cerrado
                        // con `source.close()`, que es el caso de `fin`. Si el
                        // readyState es CLOSED ya no va a reintentar.
                        if (!source || source.readyState === EventSource.CLOSED) {
                            setConn('error', 'Se perdió la conexión. Abriendo el detalle…');
                            elReload.classList.remove('hidden');
                        } else {
                            setConn('reconnecting', 'Reconectando…');
                        }
                    };
                }

                // Se abre el stream SIEMPRE, no solo para jobs vivos. Es lo que hace que
                // abrir un job ya terminado muestre el replay de sus etapas,
                // como en n8n al abrir un workflow viejo. Que se recargue o no
                // la página después lo decide `liveAtOpen` en el handler de
                // `fin`.
                setConn('reconnecting', 'Conectando…');
                connect();

                elReload.addEventListener('click', function () {
                    window.location.reload();
                });

                // Al salir de la pestaña el stream sigue abierto y retiene un
                // worker de php-fpm. `EventSource` no tiene evento de "cerrar"
                // y `beforeunload` no siempre corre (cerrar la laptop, back/forward
                // cache), así que además se corta por `pagehide`, que sí es
                // fiable. Es la diferencia entre un panel sano y uno que se
                // cuelga a la quinta pestaña.
                window.addEventListener('pagehide', function () {
                    if (source) { source.close(); source = null; }
                });

                return { close: function () { if (source) source.close(); } };
            })();
        </script>
    @endpush
@endonce