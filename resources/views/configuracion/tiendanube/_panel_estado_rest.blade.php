<div class="card mb-3" id="tn-rest-panel-estado">
    <div class="card-header">
        <h6 class="mb-0 fw-bold">Conexión REST (Application del Partner Portal)</h6>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <span class="badge" id="tn-rest-badge-estado">—</span>
                <div class="mt-2" id="tn-rest-datos-conexion" style="display:none;">
                    <div class="text-muted small">
                        Conectada el <span id="tn-rest-conectada-en">—</span> ·
                        <strong><span id="tn-rest-tienda-nombre">—</span></strong>
                        (<span id="tn-rest-tienda-dominio">—</span>)
                    </div>
                    <div class="text-muted small" id="tn-rest-scopes-otorgados">—</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('configuracion.tiendanube.conectarRest') }}" class="btn btn-primary" id="btn-conectar-tn-rest" style="display:none;">
                    <i class="fas fa-plug me-1"></i> Conectar
                </a>
                <button type="button" class="btn btn-outline-danger" id="btn-desconectar-tn-rest"
                        data-bs-toggle="modal" data-bs-target="#modal-desconectar-tn-rest" style="display:none;">
                    <i class="fas fa-unlink me-1"></i> Desconectar
                </button>
            </div>
        </div>

        <div class="alert alert-danger mt-3 mb-0 d-none" id="tn-rest-aviso-caida">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <span id="tn-rest-aviso-caida-mensaje"></span>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-desconectar-tn-rest" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Desconectar la conexión REST</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p>Se va a borrar el token de acceso de esta Application.</p>
                <p class="mb-0 text-muted">
                    Se conserva el historial de operaciones. No afecta a la conexión MCP de arriba. Vas a
                    poder volver a conectarla cuando quieras presionando "Conectar" de nuevo.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-desconectar-tn-rest">Desconectar</button>
            </div>
        </div>
    </div>
</div>
