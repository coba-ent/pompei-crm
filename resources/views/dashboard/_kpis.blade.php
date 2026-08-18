@if ($permisos['ventas'] || $resultadoVisible)
<div class="row" id="dashboard-kpis">
    @if ($permisos['ventas'])
    <div class="col-md-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-1">Ventas Creadas</h6>
                <h4 class="mb-1" data-kpi-valor="ventas_creadas">$ 0,00</h4>
                <span data-kpi-variacion="ventas_creadas"></span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-1">Venta Promedio</h6>
                <h4 class="mb-1" data-kpi-valor="venta_promedio">$ 0,00</h4>
                <span data-kpi-variacion="venta_promedio"></span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-1">Cantidad de Ventas</h6>
                <h4 class="mb-1" data-kpi-valor="cantidad_ventas">0</h4>
                <span data-kpi-variacion="cantidad_ventas"></span>
            </div>
        </div>
    </div>
    @endif
    @if ($resultadoVisible)
    <div class="col-md-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-1">Resultado</h6>
                <h4 class="mb-1" data-kpi-valor="resultado">$ 0,00</h4>
                <span data-kpi-variacion="resultado"></span>
            </div>
        </div>
    </div>
    @endif
</div>
@endif
