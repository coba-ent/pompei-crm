{{-- Modal de alta / edición de rol con matriz de permisos por módulo (enviado por AJAX) --}}
<div class="modal fade" id="modal-rol" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="form-rol" novalidate>
                <input type="hidden" id="rol-id" name="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="modal-rol-titulo">Nuevo Rol</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nombre" required>
                        <div class="invalid-feedback" data-field="nombre"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <input type="text" class="form-control" name="descripcion">
                        <div class="invalid-feedback" data-field="descripcion"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Permisos por módulo</label>
                        <div id="permisos-matriz"></div>
                        <div class="invalid-feedback d-block" data-field="permisos"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
