<div class="modal fade" id="modal-gasto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-gasto-titulo">Nuevo Gasto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="gasto-id">
                <div class="mb-3">
                    <label class="form-label">Fecha</label>
                    <input type="date" class="form-control" id="gasto-fecha">
                </div>
                <div class="mb-3">
                    <label class="form-label">Monto ($)</label>
                    <input type="number" step="0.01" class="form-control" id="gasto-monto">
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-label mb-0">Seleccionar Categoría</label>
                        <div>
                            <a href="#" id="btn-nueva-categoria-gasto" class="small me-2">Crear Categoría de Gasto</a>
                            <a href="#" id="btn-nueva-subcategoria-gasto" class="small">Crear Subcategoría</a>
                        </div>
                    </div>
                    <select id="gasto-categoria" class="form-select" style="width:100%"></select>
                </div>
                <div class="mb-3" id="gasto-cuenta-wrapper">
                    <label class="form-label">Elija un medio de pago</label>
                    <select id="gasto-cuenta" class="form-select" style="width:100%"></select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-control" id="gasto-descripcion" rows="2"></textarea>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="gasto-pendiente">
                    <label class="form-check-label" for="gasto-pendiente">Marcar como pendiente</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-gasto">Crear</button>
            </div>
        </div>
    </div>
</div>
