<div class="modal fade" id="modal-ingreso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-ingreso-titulo">Nuevo Ingreso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ingreso-id">
                <div class="mb-3">
                    <label class="form-label">Fecha</label>
                    <input type="date" class="form-control" id="ingreso-fecha">
                </div>
                <div class="mb-3">
                    <label class="form-label">Monto ($)</label>
                    <input type="number" step="0.01" class="form-control" id="ingreso-monto">
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-label mb-0">Categoría</label>
                        <a href="#" id="btn-nueva-categoria-ingreso" class="small">Crear Categoría de Ingreso</a>
                    </div>
                    <select id="ingreso-categoria" class="form-select" style="width:100%"></select>
                </div>
                <div class="mb-3" id="ingreso-cuenta-wrapper">
                    <label class="form-label">Medio de Cobro</label>
                    <select id="ingreso-cuenta" class="form-select" style="width:100%"></select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-control" id="ingreso-descripcion" rows="2"></textarea>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="ingreso-pendiente">
                    <label class="form-check-label" for="ingreso-pendiente">Marcar como pendiente</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-ingreso">Crear</button>
            </div>
        </div>
    </div>
</div>
