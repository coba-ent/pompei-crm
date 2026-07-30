<div class="modal fade" id="modal-nuevo-vendedor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-nuevo-vendedor-titulo">Crear Vendedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Nombre</label>
                <input type="text" class="form-control" id="nuevo-vendedor-nombre">
                <div class="invalid-feedback d-block" id="nuevo-vendedor-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-crear-vendedor">Crear</button>
            </div>
        </div>
    </div>
</div>

{{-- Modal de confirmación de eliminación de un vendedor --}}
<div class="modal fade" id="modal-vendedor-eliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar vendedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar el vendedor <strong id="vendedor-eliminar-nombre"></strong>?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-vendedor">Eliminar</button>
            </div>
        </div>
    </div>
</div>
