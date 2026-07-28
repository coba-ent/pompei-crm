<div class="modal fade" id="modal-credenciales-ml" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-credenciales-ml">
                <div class="modal-header">
                    <h5 class="modal-title">Credenciales de la aplicación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">App ID</label>
                        <input type="text" class="form-control" name="client_id" id="ml-cred-client-id" autocomplete="off">
                        <div class="invalid-feedback" data-field="client_id"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Clave secreta</label>
                        <input type="password" class="form-control" name="client_secret" id="ml-cred-client-secret" autocomplete="new-password">
                        <small class="text-muted d-block mt-1" id="ml-cred-secret-cargado" style="display:none;">
                            Ya hay una clave cargada. Dejá este campo vacío para conservarla.
                        </small>
                        <div class="invalid-feedback" data-field="client_secret"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sitio de operación</label>
                        <select class="form-select" name="site_id" id="ml-cred-site-id"></select>
                        <div class="invalid-feedback" data-field="site_id"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-credenciales-ml">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
