<div class="card mb-3" id="tn-panel-estado">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <span class="badge" id="tn-badge-estado">—</span>
                <div class="mt-2" id="tn-datos-tienda" style="display:none;">
                    <div><strong id="tn-tienda-nombre">—</strong> <span class="text-muted" id="tn-tienda-dominio"></span></div>
                    <div class="text-muted small">
                        País: <span id="tn-tienda-pais">—</span> ·
                        Moneda: <span id="tn-tienda-moneda">—</span>
                    </div>
                    <div class="text-muted small">
                        Credenciales guardadas el <span id="tn-credenciales-guardadas">—</span> ·
                        Última verificación <span id="tn-ultima-verificacion">—</span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" id="btn-probar-tn">
                    <i class="fas fa-satellite-dish me-1"></i> Probar conexión
                </button>
                <button type="button" class="btn btn-outline-danger" id="btn-desconectar-tn"
                        data-bs-toggle="modal" data-bs-target="#modal-desconectar-tn" style="display:none;">
                    <i class="fas fa-unlink me-1"></i> Desconectar
                </button>
            </div>
        </div>

        <div class="alert alert-danger mt-3 mb-0 d-none" id="tn-aviso-caida">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <span id="tn-aviso-caida-mensaje"></span>
            — <a href="#" data-bs-toggle="modal" data-bs-target="#modal-credenciales-tn">recargá el token</a>.
        </div>
    </div>
</div>
