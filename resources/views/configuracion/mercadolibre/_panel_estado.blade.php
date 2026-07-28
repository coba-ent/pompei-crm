<div class="card mb-3" id="ml-panel-estado">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <span class="badge" id="ml-badge-estado">—</span>
                <div class="mt-2" id="ml-datos-cuenta" style="display:none;">
                    <div><strong id="ml-cuenta-nickname">—</strong> <span class="text-muted" id="ml-cuenta-tipo"></span></div>
                    <div class="text-muted small">
                        Id: <span id="ml-cuenta-id">—</span> ·
                        Correo: <span id="ml-cuenta-email">—</span> ·
                        Sitio: <span id="ml-cuenta-site">—</span>
                    </div>
                    <div class="text-muted small">
                        Vinculada el <span id="ml-cuenta-vinculada">—</span> ·
                        Acceso vence <span id="ml-cuenta-vence">—</span> ·
                        Último renovado <span id="ml-cuenta-ultimo-refresh">—</span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="#" class="btn btn-primary" id="btn-conectar-ml" style="display:none;">
                    <i class="fas fa-plug me-1"></i> Conectar con Mercado Libre
                </a>
                <button type="button" class="btn btn-outline-primary" id="btn-probar-ml" style="display:none;">
                    <i class="fas fa-satellite-dish me-1"></i> Probar conexión
                </button>
                <button type="button" class="btn btn-outline-danger" id="btn-desconectar-ml"
                        data-bs-toggle="modal" data-bs-target="#modal-desconectar-ml" style="display:none;">
                    <i class="fas fa-unlink me-1"></i> Desconectar
                </button>
            </div>
        </div>

        <div class="alert alert-danger mt-3 mb-0 d-none" id="ml-aviso-caida">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <span id="ml-aviso-caida-mensaje"></span>
            — <a href="#" id="ml-aviso-caida-reconectar">volvé a conectar la cuenta</a>.
        </div>
    </div>
</div>
