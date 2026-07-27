{{-- Modal para crear / renombrar una lista de precios (reemplaza el prompt nativo) --}}
<div class="modal fade" id="modal-lista-nombre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form id="form-lista-nombre" novalidate>
                <input type="hidden" id="lista-nombre-id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-lista-nombre-titulo">Nueva Lista de Precios</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Nombre de la lista</label>
                    <input type="text" class="form-control" id="lista-nombre-input" maxlength="100" autocomplete="off">
                    <div class="invalid-feedback" id="lista-nombre-error"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-lista-nombre">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal de confirmación de eliminación de una lista de precios --}}
<div class="modal fade" id="modal-lista-eliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar lista de precios</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar la lista <strong id="lista-eliminar-nombre"></strong>? Se quitará de todos los productos.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-lista">Eliminar</button>
            </div>
        </div>
    </div>
</div>
