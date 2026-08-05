@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Informe de Stock</h4>
            </div>
        </div>

        {{-- Cards de KPIs --}}
        <div class="row">
            <div class="col-xl-4 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Unidades en Stock</h6>
                            <span class="icon-box icon-box-sm bg-warning-light rounded">
                                <i class="fas fa-warehouse text-warning"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-unidades">{{ number_format($stats['unidades_en_stock'], 0, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2"><span class="fs-13 text-muted">Según los filtros vigentes</span></div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Costo Total</h6>
                            <span class="icon-box icon-box-sm bg-danger-light rounded">
                                <i class="fas fa-coins text-danger"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-costo-total">$ {{ number_format($stats['costo_total'], 2, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2"><span class="fs-13 text-muted">Cantidad en stock × costo</span></div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="card ic-chart-card">
                    <div class="card-header d-block border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Valor Venta Total</h6>
                            <span class="icon-box icon-box-sm bg-success-light rounded">
                                <i class="fas fa-sack-dollar text-success"></i>
                            </span>
                        </div>
                        <span class="data-value" id="stat-valor-venta-total">$ {{ number_format($stats['valor_venta_total'], 2, ',', '.') }}</span>
                    </div>
                    <div class="card-body pt-2"><span class="fs-13 text-muted">Cantidad en stock × precio de venta</span></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">

                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <span id="dt-buttons-informe-stock"></span>
                </div>

                {{-- Panel de Filtros (colapsable) --}}
                <div class="collapse show mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Usuario</label>
                                <select id="filtro-usuario" class="form-select">
                                    <option value="">Todos</option>
                                    @foreach ($usuarios as $usuario)
                                        <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Operación</label>
                                <select id="filtro-operacion" class="form-select">
                                    <option value="">Todas</option>
                                    <option value="salida">Salida</option>
                                    <option value="entrada">Entrada</option>
                                    <option value="ajuste">Ajuste</option>
                                    <option value="transferencia">Transferencia</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Proveedor</label>
                                <select id="filtro-proveedor" class="form-select">
                                    <option value="">Todos</option>
                                    @foreach ($proveedores as $proveedor)
                                        <option value="{{ $proveedor->id }}">{{ $proveedor->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo de Producto</label>
                                <select id="filtro-tipo-producto" class="form-select">
                                    <option value="">Todos</option>
                                    @foreach ($tiposProducto as $tipo)
                                        <option value="{{ $tipo->id }}">{{ $tipo->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Productos</label>
                                <select id="filtro-producto" class="form-select">
                                    @if ($productoPreseleccionado)
                                        <option value="{{ $productoPreseleccionado->id }}" selected>{{ $productoPreseleccionado->nombre }}</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del Producto/Servicio</label>
                                <select id="filtro-estado" class="form-select">
                                    <option value="todos" selected>Todos</option>
                                    <option value="activos">Activos</option>
                                    <option value="inactivos">Inactivos</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Rango de fechas</label>
                                <input type="text" id="filtro-rango-fechas" class="form-control" placeholder="Todas las fechas">
                                <input type="hidden" id="filtro-fecha-desde">
                                <input type="hidden" id="filtro-fecha-hasta">
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-light" id="btn-limpiar-filtros">Limpiar</button>
                                <button type="button" class="btn btn-primary" id="btn-aplicar-filtros">
                                    <i class="fas fa-search me-1"></i> Buscar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="tabla-informe-stock" class="table table-hover display responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Operación</th>
                                <th>Detalle</th>
                                <th>Producto</th>
                                <th class="text-end">Cantidad</th>
                                <th class="text-end">Stock Saldo</th>
                                <th>Usuario</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

            </div>
        </div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.InformeStockConfig = {
        rutas: {
            data: "{{ route('informes.stock.data') }}",
            stats: "{{ route('informes.stock.stats') }}",
            opciones: "{{ route('productos.opciones') }}",
        },
        productoId: {{ $productoId ? (int) $productoId : 'null' }},
    };
</script>
@vite(['resources/js/informe-stock.js'])
@endsection
