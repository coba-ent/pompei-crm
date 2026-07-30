<div class="modal fade" id="modal-nueva-categoria-gasto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-nueva-categoria-gasto-titulo">Crear Categoría de Gasto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Nombre</label>
                <input type="text" class="form-control" id="nueva-categoria-gasto-nombre">
                <div class="invalid-feedback d-block" id="nueva-categoria-gasto-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-crear-categoria-gasto">Crear</button>
            </div>
        </div>
    </div>
</div>

{{-- Modal de confirmación de eliminación de una categoría/subcategoría de gasto --}}
<div class="modal fade" id="modal-categoria-gasto-eliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar categoría</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar la categoría <strong id="categoria-gasto-eliminar-nombre"></strong>?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-categoria-gasto">Eliminar</button>
            </div>
        </div>
    </div>
</div>
