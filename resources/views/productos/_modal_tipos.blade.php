{{-- Modal para crear / renombrar un "Tipo de Producto" (reemplaza prompt nativo) --}}
<div class="modal fade" id="modal-tipo-nombre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form id="form-tipo-nombre" novalidate>
                <input type="hidden" id="tipo-nombre-id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tipo-nombre-titulo">Nuevo Tipo de Producto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="tipo-nombre-input" maxlength="100" autocomplete="off">
                    <div class="invalid-feedback" id="tipo-nombre-error"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-tipo-nombre">Crear</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal de confirmación de eliminación de un tipo de producto --}}
<div class="modal fade" id="modal-tipo-eliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar tipo de producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar el tipo <strong id="tipo-eliminar-nombre"></strong>? Los productos que lo usaban quedarán sin tipo.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-tipo">Eliminar</button>
            </div>
        </div>
    </div>
</div>
