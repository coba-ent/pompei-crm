{{-- Modal de alta / edición de usuario (enviado por AJAX, sin recargar) --}}
<div class="modal fade" id="modal-usuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form id="form-usuario" novalidate>
                <input type="hidden" id="usuario-id" name="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="modal-usuario-titulo">Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                        <div class="invalid-feedback" data-field="name"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" required>
                        <div class="invalid-feedback" data-field="email"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contraseña <span class="text-danger" id="password-required">*</span></label>
                        <input type="password" class="form-control" name="password" autocomplete="new-password">
                        <div class="form-text" id="password-help">Dejar en blanco para conservar la actual.</div>
                        <div class="invalid-feedback" data-field="password"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Roles</label>
                        <div id="roles-checklist"></div>
                        <div class="invalid-feedback d-block" data-field="roles"></div>
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
