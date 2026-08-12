{{-- Modales del envío manual a ARCA (spec 040) — compartidos por el listado y el detalle de venta --}}
<div class="modal fade" id="modal-confirmar-arca" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Enviar a ARCA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Enviar esta Venta a ARCA para solicitar el CAE? Es una acción real ante un ente fiscal.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btn-confirmar-arca">
                    <i class="fas fa-paper-plane me-1"></i>Enviar a ARCA
                </button>
            </div>
        </div>
    </div>
</div>
{{-- Resultado real de un envío a ARCA (spec 040, FR-007) — modal persistente, no toast --}}
<div class="modal fade" id="modal-resultado-arca" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-resultado-arca-titulo">Resultado del envío a ARCA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="modal-resultado-arca-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
