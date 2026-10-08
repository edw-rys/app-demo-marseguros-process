/**
 * gdv-pipeline.js — Controlador para zoom y canvas arrastrable de GDV.
 */
(function() {
    // Asegurar que el scroll del body nunca quede bloqueado
    document.body.style.overflow = '';

    // Zoom Controls
    window.__gdvZoomScales = window.__gdvZoomScales || {};

    window.__gdvZoom = function(canvasId, delta) {
        var wf = document.getElementById(canvasId + '-workflow');
        if (!wf) return;
        var current = window.__gdvZoomScales[canvasId] || 1;
        var next = Math.min(Math.max(current + delta, 0.6), 1.6);
        window.__gdvZoomScales[canvasId] = next;
        wf.style.transform = 'scale(' + next + ')';
    };

    window.__gdvResetZoom = function(canvasId) {
        var wf = document.getElementById(canvasId + '-workflow');
        if (!wf) return;
        window.__gdvZoomScales[canvasId] = 1;
        wf.style.transform = 'scale(1)';
    };

    // Modal helpers (para galería de adjuntos)
    window.__gdvOpenModal = function(id) {
        var el = document.getElementById(id);
        if (el) {
            if (el.parentElement !== document.body) {
                document.body.appendChild(el);
            }
            el.style.display = 'flex';
            el.classList.add('is-open');
        }
    };

    window.__gdvCloseModal = function(id) {
        if (id) {
            var el = document.getElementById(id);
            if (el) {
                el.style.display = 'none';
                el.classList.remove('is-open');
            }
        } else {
            document.querySelectorAll('.gdv-modal-backdrop').forEach(function(m) {
                m.style.display = 'none';
                m.classList.remove('is-open');
            });
        }
        document.body.style.overflow = '';
    };

    // Tecla Escape para cerrar cualquier modal abierto
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.__gdvCloseModal();
        }
    });

    // Drag to pan canvas
    function initDraggableCanvases() {
        document.querySelectorAll('.gdv-canvas-container').forEach(function(slider) {
            if (slider.__gdvInitialized) return;
            slider.__gdvInitialized = true;
            var isDown = false;
            var startX, scrollLeft;

            slider.addEventListener('mousedown', function(e) {
                if (e.target.closest('.gdv-node-wrapper') || e.target.closest('.gdv-canvas-controls') || e.target.closest('.gdv-modal-card') || e.target.closest('a')) return;
                isDown = true;
                startX = e.pageX - slider.offsetLeft;
                scrollLeft = slider.scrollLeft;
            });

            slider.addEventListener('mouseleave', function() { isDown = false; });
            slider.addEventListener('mouseup', function() { isDown = false; });

            slider.addEventListener('mousemove', function(e) {
                if (!isDown) return;
                var x = e.pageX - slider.offsetLeft;
                var walk = (x - startX) * 1.5;
                if (Math.abs(walk) > 4) {
                    slider.scrollLeft = scrollLeft - walk;
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDraggableCanvases);
    } else {
        initDraggableCanvases();
    }
})();
