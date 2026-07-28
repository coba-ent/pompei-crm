<div class="modal fade" id="modal-reemplazo-cuenta-ml" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar cambio de cuenta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p>
                    Autorizaste con una cuenta distinta de la que está conectada. Confirmar reemplaza la
                    conexión actual; mientras tanto la cuenta vigente sigue operando con normalidad.
                </p>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="text-muted small">Cuenta actual</div>
                            <div class="fw-bold" id="ml-reemplazo-actual-nickname">—</div>
                            <div class="text-muted small" id="ml-reemplazo-actual-id">—</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border border-primary rounded p-2 h-100">
                            <div class="text-muted small">Cuenta nueva</div>
                            <div class="fw-bold" id="ml-reemplazo-nueva-nickname">—</div>
                            <div class="text-muted small" id="ml-reemplazo-nueva-id">—</div>
                            <div class="text-muted small" id="ml-reemplazo-nueva-email">—</div>
                        </div>
                    </div>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    Esta autorización retenida es válida hasta <span id="ml-reemplazo-expira">—</span>.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btn-descartar-reemplazo-ml">Descartar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-reemplazo-ml">Confirmar reemplazo</button>
            </div>
        </div>
    </div>
</div>
