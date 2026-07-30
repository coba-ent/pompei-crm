<div class="card mb-3" id="tn-panel-estado">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <span class="badge" id="tn-badge-estado">—</span>
                <div class="mt-2" id="tn-datos-conexion" style="display:none;">
                    <div class="text-muted small">
                        Conectada el <span id="tn-conectada-en">—</span> ·
                        <strong><span id="tn-productos-total">—</span></strong> productos en el catálogo
                    </div>
                    <div class="small" id="tn-dias-restantes">—</div>
                    <div class="text-muted small" id="tn-scopes-otorgados">—</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary" id="btn-conectar-tn" disabled
                        title="Requiere intervención técnica — ver aviso abajo" style="display:none;">
                    <i class="fas fa-plug me-1"></i> Conectar con Tiendanube
                </button>
                <button type="button" class="btn btn-outline-danger" id="btn-desconectar-tn"
                        data-bs-toggle="modal" data-bs-target="#modal-desconectar-tn" style="display:none;">
                    <i class="fas fa-unlink me-1"></i> Desconectar
                </button>
            </div>
        </div>

        <div class="alert alert-warning mt-3 mb-0 d-none" id="tn-aviso-sin-conexion">
            <i class="fas fa-exclamation-triangle me-1"></i>
            <span id="tn-aviso-sin-conexion-mensaje"></span>
            La conexión no se puede rehacer sola desde esta pantalla: Tiendanube sólo permite este tipo
            de conexión desde una sesión técnica local, no desde el servidor del CRM. Para conectar o
            reconectar la tienda, contactá a soporte técnico.
        </div>
    </div>
</div>
