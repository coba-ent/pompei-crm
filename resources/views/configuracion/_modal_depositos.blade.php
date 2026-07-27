{{-- Modal principal: "Configuración de Depósitos" — lista editable inline --}}
<div class="modal fade" id="modal-depositos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configuración de Depósitos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="btn-agregar-deposito">
                    <i class="fas fa-plus me-1"></i> Agregar Depósito
                </button>
                <div id="depositos-lista"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Guardar</button>
            </div>
        </div>
    </div>
</div>

{{-- Modal para crear / renombrar un depósito --}}
<div class="modal fade" id="modal-deposito-nombre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form id="form-deposito-nombre" novalidate>
                <input type="hidden" id="deposito-nombre-id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-deposito-nombre-titulo">Nuevo Depósito</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="deposito-nombre-input" maxlength="100" autocomplete="off">
                    <div class="invalid-feedback" id="deposito-nombre-error"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-deposito-nombre">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal de confirmación de eliminación de un depósito --}}
<div class="modal fade" id="modal-deposito-eliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar depósito</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar el depósito <strong id="deposito-eliminar-nombre"></strong>?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-deposito">Eliminar</button>
            </div>
        </div>
    </div>
</div>
