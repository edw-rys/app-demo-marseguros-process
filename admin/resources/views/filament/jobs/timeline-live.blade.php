{{--
    Timeline en vivo del pipeline.

    Es una CAPA encima del timeline estático (`timeline.blade.php`), no un
    reemplazo: el estático sigue debajo con todo lo que ya había, y el JS
    escribe las etapas nuevas arriba. Si el JS no corre —sin soporte, error de
    red, un filtro de extensión— el timeline de siempre se ve igual.

    Solo se renderiza para jobs QUE NO TERMINARON (lo filtra `JobInfolist`): en
    un job ya cerrado el stream no tiene nada que mandar y su indicador de
    "conectando" queda colgado para siempre al lado del contenido real.

    El JS va inline a propósito. Son ~180 líneas y necesita vivir en la página
    que lo usa: separarlo en un asset obligaría a agregarlo al build de Filament
    y a invalidar la caché de `/build`, que es `immutable` por un año.
--}}
@php
    // El mismo `Last-Event-ID` que el controller usa como cursor: si el stream
    // reconecta, el `EventSource` lo manda solo y no se repite lo ya pintado.
    $streamUrl = route('jobs.stream', ['uuid' => $record->uuid]);
@endphp

<div class="gdv-live" id="gdv-live" data-stream="{{ $streamUrl }}">
    {{-- Barra de estado: conexión y etapa en curso. --}}
    <div class="gdv-live__bar">
        <span data-role="dot" class="gdv-live__dot"></span>

        <span data-role="conn">Conectando al pipeline…</span>

        <span data-role="stage" class="gdv-badge gdv-badge--info" hidden>
            <span data-role="stage-label">—</span>
        </span>

        {{-- Valla de seguridad: si el stream no abre en 10 s, el texto de
             arriba queda mintiendo ("conectando" no es lo que está pasando). --}}
        <span data-role="timeout-warning" class="gdv-live__warn" hidden>
            Sin respuesta del pipeline. Puede seguir corriendo: recargá para ver el estado.
        </span>

        <button type="button" data-role="reload" class="gdv-dl gdv-dl--ghost" hidden>
            Ver detalle completo
        </button>
    </div>

    {{-- Contenedor de las etapas que llegan por el stream. --}}
    <ul data-role="live-stages"></ul>
</div>

@once
    @push('scripts')
        <script>
            window.__gdvLiveTimeline = (function () {
                'use strict';

                // ── Clases ───────────────────────────────────────────────────
                // Las MISMAS que usa `partials/node.blade.php` a mano, para que
                // una etapa en vivo se vea idéntica a una ya guardada. Si se
                // cambia una hay que cambiar la otra.
                //
                // No son utilidades de Tailwind porque en este panel no existen:
                // el `theme.css` de Filament solo trae clases `fi-*`. Ver el
                // comentario de `public/css/gdv-pipeline.css`.
                var STATUS = {
                    passed:   { node: 'gdv-node--passed',   dot: 'gdv-dot--passed',   label: '',                text: 'OK' },
                    failed:   { node: 'gdv-node--failed',   dot: 'gdv-dot--failed',   label: 'gdv-text--error', text: 'Error' },
                    degraded: { node: 'gdv-node--degraded', dot: 'gdv-dot--degraded', label: 'gdv-text--warn',  text: 'Degradado' },
                    skipped:  { node: 'gdv-node--skipped',  dot: 'gdv-dot--skipped',  label: '',                text: 'Omitida' },
                    running:  { node: 'gdv-node--running',  dot: 'gdv-dot--running',  label: '',                text: 'En curso' }
                };

                var root = document.getElementById('gdv-live');
                if (!root) return null;

                var url = root.dataset.stream;
                var list = root.querySelector('[data-role="live-stages"]');
                var elConn = root.querySelector('[data-role="conn"]');
                var elDot = root.querySelector('[data-role="dot"]');
                var elStage = root.querySelector('[data-role="stage"]');
                var elStageLabel = root.querySelector('[data-role="stage-label"]');
                var elTimeoutWarning = root.querySelector('[data-role="timeout-warning"]');
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

                // "Conectando…" sin que pase nada es peor que no decir nada: el
                // usuario espera un evento que no va a llegar y no sabe si el
                // job se trabó o si solo falta el stream.
                var watchdog = setTimeout(function () {
                    if (elTimeoutWarning) elTimeoutWarning.hidden = false;
                    if (elReload) elReload.hidden = false;
                }, 10000);

                function el(tag, className, content) {
                    var node = document.createElement(tag);
                    if (className) node.className = className;
                    if (content != null) node.textContent = content;
                    return node;
                }

                function setConn(state, label) {
                    if (elConn) elConn.textContent = label;
                    if (elDot) {
                        elDot.className = 'gdv-live__dot gdv-live__dot--' +
                            (state === 'live'        ? 'live' :
                             state === 'reconnecting' ? 'reconnecting' :
                             state === 'error'        ? 'error' : 'idle');
                    }
                }

                function setStage(label) {
                    if (!elStage || !elStageLabel) return;
                    if (!label) {
                        elStage.hidden = true;
                        return;
                    }
                    elStage.hidden = false;
                    elStageLabel.textContent = label;
                }

                // ── Pintado de una etapa ───────────────────────────────────
                function renderStage(stage) {
                    var style = STATUS[stage.status] || STATUS.skipped;

                    // Cabecera de archivo, igual que el blade estático: las
                    // etapas por adjunto (detect_kind, extract_text, classify,
                    // extract_fields) se repetirían sin contexto si no.
                    if (stage.filename && stage.filename !== lastFilename) {
                        list.appendChild(el('li', 'gdv-log__file', stage.filename));
                        lastFilename = stage.filename;
                    }

                    var row = el('li', 'gdv-log__row');
                    row.appendChild(el('span', 'gdv-log__dot gdv-log__dot--' + stage.status));

                    var body = el('div', 'gdv-log__body');
                    var head = el('div', 'gdv-log__head');

                    head.appendChild(el('span', 'gdv-log__title', stage.label));
                    head.appendChild(el('span', 'gdv-log__status ' + style.label, style.text));

                    if (stage.duration_ms != null) {
                        head.appendChild(el('span', 'gdv-log__duration', formatMs(stage.duration_ms)));
                    }

                    body.appendChild(head);

                    if (stage.message) {
                        body.appendChild(el('p', 'gdv-log__msg', stage.message));
                    }
                    if (stage.error) {
                        body.appendChild(el('p', 'gdv-log__err', stage.error));
                    }
                    if (stage.description && stage.status === 'running') {
                        // Solo mientras corre: es el texto que explica qué está
                        // pasando ahora mismo.
                        body.appendChild(el('p', 'gdv-log__msg', stage.description));
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

                    source.addEventListener('open', function () {
                        clearTimeout(watchdog);
                        if (elTimeoutWarning) elTimeoutWarning.hidden = true;
                        setConn('live', 'En vivo');
                    });

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
                        if (!liveAtOpen) {
                            setConn('done', 'Job finalizado · mostrando etapas guardadas');
                            return;
                        }

                        // El detalle completo (adjuntos, resultados, respuesta)
                        // lo arma el server en el render; acá solo hay etapas.
                        setConn('done', 'Job finalizado · recargando detalle…');
                        setTimeout(function () { window.location.reload(); }, 600);
                    });

                    source.addEventListener('timeout', function () {
                        if (source) { source.close(); source = null; }
                        setConn('reconnecting', 'Pausa programada · reconectando…');
                        setTimeout(connect, 1000);
                    });

                    source.addEventListener('aviso', function (e) {
                        var data = JSON.parse(e.data);
                        setConn('error', data.message);
                    });

                    source.onerror = function () {
                        if (!source || source.readyState === EventSource.CLOSED) {
                            setConn('error', 'Se perdió la conexión. Abriendo el detalle…');
                            if (elReload) elReload.hidden = false;
                        } else {
                            setConn('reconnecting', 'Reconectando…');
                        }
                    };
                }

                // Se abre el stream SIEMPRE, no solo para jobs vivos.
                setConn('reconnecting', 'Conectando…');
                connect();

                if (elReload) {
                    elReload.addEventListener('click', function () {
                        window.location.reload();
                    });
                }

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