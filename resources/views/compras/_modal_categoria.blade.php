<div class="modal fade" id="modal-nueva-categoria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-nueva-categoria-titulo">Crear Categoría de Compras</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Nombre</label>
                <input type="text" class="form-control" id="nueva-categoria-nombre">
                <div class="invalid-feedback d-block" id="nueva-categoria-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-crear-categoria">Crear</button>
            </div>
        </div>
    </div>
</div>

{{-- Modal de confirmación de eliminación de una categoría de compras --}}
<div class="modal fade" id="modal-categoria-eliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar categoría</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar la categoría <strong id="categoria-eliminar-nombre"></strong>?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-categoria">Eliminar</button>
            </div>
        </div>
    </div>
</div>
