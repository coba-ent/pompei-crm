<div class="row align-items-center mb-4">
    <div class="col-sm-6 mb-2">
        <h4 class="mb-0 text-primary fw-bold">Depósitos</h4>
    </div>
    <div class="col-sm-6 mb-2 text-sm-end">
        <button type="button" class="btn btn-primary" id="btn-configurar-depositos">
            <i class="fas fa-warehouse me-1"></i> Configurar Depósitos
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted mb-0">
            Administrá los depósitos disponibles para el control de stock de Productos.
            Los depósitos activos aparecen en el filtro y el selector de "Depósito" de la
            pantalla de Productos.
        </p>
    </div>
</div>

@include('configuracion._modal_depositos')
