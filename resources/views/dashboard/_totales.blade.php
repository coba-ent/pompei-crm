@if ($permisos['ventas'] || $permisos['otros_ingresos'] || $permisos['compras'] || $permisos['gastos'])
<div class="card mb-3" id="dashboard-totales">
    <div class="card-header">
        <h6 class="mb-0">Totales del Período</h6>
    </div>
    <div class="card-body">
        @if ($permisos['ventas'])
        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <span>Ventas</span>
                <span data-total-monto="ventas">$ 0,00</span>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-success" data-total-barra="ventas" style="width:0%"></div>
            </div>
        </div>
        @endif
        @if ($permisos['otros_ingresos'])
        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <span>Otros Ingresos</span>
                <span data-total-monto="otros_ingresos">$ 0,00</span>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-info" data-total-barra="otros_ingresos" style="width:0%"></div>
            </div>
        </div>
        @endif
        @if ($permisos['compras'])
        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <span>Compras</span>
                <span data-total-monto="compras">$ 0,00</span>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-warning" data-total-barra="compras" style="width:0%"></div>
            </div>
        </div>
        @endif
        @if ($permisos['gastos'])
        <div class="mb-0">
            <div class="d-flex justify-content-between">
                <span>Gastos</span>
                <span data-total-monto="gastos">$ 0,00</span>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-danger" data-total-barra="gastos" style="width:0%"></div>
            </div>
        </div>
        @endif
    </div>
</div>
@endif
