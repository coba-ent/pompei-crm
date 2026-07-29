<div class="modal fade" id="modal-credenciales-tn" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-credenciales-tn">
                <div class="modal-header">
                    <h5 class="modal-title">Credenciales de la Aplicación personalizada</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Identificador de tienda (store ID)</label>
                        <input type="text" class="form-control" name="store_id" id="tn-cred-store-id" autocomplete="off">
                        <div class="invalid-feedback" data-field="store_id"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Token de acceso</label>
                        <input type="password" class="form-control" name="access_token" id="tn-cred-access-token" autocomplete="new-password">
                        <small class="text-muted d-block mt-1" id="tn-cred-token-cargado" style="display:none;">
                            Ya hay un token cargado. Dejá este campo vacío para conservarlo.
                        </small>
                        <div class="invalid-feedback" data-field="access_token"></div>
                    </div>
                    <div class="alert alert-info small mb-0">
                        Generá el token desde el panel de administración de tu tienda Tiendanube, al crear
                        una <strong>Aplicación personalizada</strong>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-credenciales-tn">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
