{{-- Modales del envío manual a ARCA de una NC/ND (spec 097) — propios, independientes de
     _modales_arca.blade.php (Venta, spec 040): la NC/ND tiene una condición de elegibilidad
     distinta (depende también del comprobante original) y no conviene acoplar ambos flujos. --}}
<div class="modal fade" id="modal-confirmar-arca-nota" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Enviar a ARCA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Enviar esta Nota de Crédito/Débito a ARCA para solicitar su propio CAE? Es una acción real ante un ente fiscal.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btn-confirmar-arca-nota">
                    <i class="fas fa-paper-plane me-1"></i>Enviar a ARCA
                </button>
            </div>
        </div>
    </div>
</div>
{{-- Resultado real de un envío a ARCA de una NC/ND (FR-006) — modal persistente, no toast --}}
<div class="modal fade" id="modal-resultado-arca-nota" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-resultado-arca-nota-titulo">Resultado del envío a ARCA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="modal-resultado-arca-nota-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
