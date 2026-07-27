{{--
    Modal genérico para previsualizar cualquier documento imprimible/PDF de la
    app (presupuesto, remito, futuros comprobantes) sin salir de la pantalla.
    Uso desde cualquier *.js: window.AppPdf.abrir(url, 'Título del documento').
--}}
<div class="modal fade" id="modal-pdf" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-pdf-titulo">Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="modal-pdf-iframe" src="about:blank" title="Vista previa del documento" style="width:100%; height:75vh; border:0;"></iframe>
            </div>
            <div class="modal-footer">
                <a id="modal-pdf-abrir" href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                    <i class="fas fa-up-right-from-square me-1"></i> Abrir en pestaña nueva
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        function bsModal(el) {
            if (!el) { return null; }
            return window.bootstrap
                ? window.bootstrap.Modal.getOrCreateInstance(el)
                : { show: function () { el.classList.add('show'); el.style.display = 'block'; }, hide: function () { el.classList.remove('show'); el.style.display = 'none'; } };
        }

        window.AppPdf = {
            /** Muestra `url` (vista imprimible/PDF) dentro del modal compartido. */
            abrir: function (url, titulo) {
                var el = document.getElementById('modal-pdf');
                var modal = bsModal(el);
                if (!modal) {
                    window.open(url, '_blank');
                    return;
                }
                document.getElementById('modal-pdf-titulo').textContent = titulo || 'Documento';
                document.getElementById('modal-pdf-iframe').src = url;
                document.getElementById('modal-pdf-abrir').setAttribute('href', url);
                modal.show();
            },
        };

        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('modal-pdf');
            if (!el) { return; }
            // Corta la carga del documento al cerrar (evita diálogos de impresión colgados).
            el.addEventListener('hidden.bs.modal', function () {
                document.getElementById('modal-pdf-iframe').src = 'about:blank';
            });
        });
    })();
</script>
