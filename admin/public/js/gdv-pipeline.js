/**
 * gdv-pipeline.js — Controlador global para canvas, zoom y modales de GDV.
 */
(function() {
    window.__gdvOpenModal = function(id) {
        // Cerrar todos los modales abiertos
        document.querySelectorAll('.gdv-modal-backdrop').forEach(function(m) {
            m.classList.remove('is-open');
        });

        var el = document.getElementById(id);
        if (el) {
            // Mover al body si no está allí (evita problemas de transform: scale en el canvas)
            if (el.parentElement !== document.body) {
                document.body.appendChild(el);
            }
            el.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        } else {
            console.warn('[GDV] Modal no encontrado con ID:', id);
        }
    };

    window.__gdvCloseModal = function(id) {
        if (id) {
            var el = document.getElementById(id);
            if (el) el.classList.remove('is-open');
        } else {
            document.querySelectorAll('.gdv-modal-backdrop.is-open').forEach(function(m) {
                m.classList.remove('is-open');
            });
        }

        if (!document.querySelector('.gdv-modal-backdrop.is-open')) {
            document.body.style.overflow = '';
        }
    };

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

    // Delegación global de clics (Capture Phase: infalible ante Livewire o SVG clicks)
    document.addEventListener('click', function(e) {
        var openBtn = e.target.closest('[data-gdv-open]');
        if (openBtn) {
            var targetId = openBtn.getAttribute('data-gdv-open');
            if (targetId) {
                e.preventDefault();
                e.stopPropagation();
                window.__gdvOpenModal(targetId);
                return;
            }
        }

        var closeBtn = e.target.closest('[data-gdv-close]');
        if (closeBtn) {
            var closeId = closeBtn.getAttribute('data-gdv-close');
            e.preventDefault();
            e.stopPropagation();
            window.__gdvCloseModal(closeId);
            return;
        }

        if (e.target.classList && e.target.classList.contains('gdv-modal-backdrop')) {
            e.preventDefault();
            e.stopPropagation();
            e.target.classList.remove('is-open');
            if (!document.querySelector('.gdv-modal-backdrop.is-open')) {
                document.body.style.overflow = '';
            }
        }
    }, true);

    // Tecla Escape para cerrar
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
                if (e.target.closest('.gdv-node-wrap') || e.target.closest('.gdv-canvas-controls') || e.target.closest('.gdv-modal-card')) return;
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
