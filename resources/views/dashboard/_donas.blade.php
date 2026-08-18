@if ($permisos['ventas'] || $permisos['compras'] || $permisos['gastos'])
<div class="row mb-3">
    @if ($permisos['ventas'])
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Ventas por Categoría</h6></div>
            <div class="card-body"><div id="dona-ventas"></div></div>
        </div>
    </div>
    @endif
    @if ($permisos['compras'])
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Compras por Categoría</h6></div>
            <div class="card-body"><div id="dona-compras"></div></div>
        </div>
    </div>
    @endif
    @if ($permisos['gastos'])
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Gastos por Categoría</h6></div>
            <div class="card-body"><div id="dona-gastos"></div></div>
        </div>
    </div>
    @endif
</div>
@endif
