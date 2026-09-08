{{-- Modal principal: "Configuración de Vendedores" — lista editable inline --}}
<div class="modal fade" id="modal-vendedores" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configuración de Vendedores</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="btn-agregar-vendedor">
                    <i class="fas fa-plus me-1"></i> Agregar Vendedor
                </button>
                <div id="vendedores-lista"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Guardar</button>
            </div>
        </div>
    </div>
</div>

{{-- Modal para crear / renombrar un vendedor --}}
<div class="modal fade" id="modal-vendedor-nombre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form id="form-vendedor-nombre" novalidate>
                <input type="hidden" id="vendedor-nombre-id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-vendedor-nombre-titulo">Nuevo Vendedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="vendedor-nombre-input" maxlength="255" autocomplete="off">
                    <div class="invalid-feedback" id="vendedor-nombre-error"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-vendedor-nombre">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
