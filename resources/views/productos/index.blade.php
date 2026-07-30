@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Productos &amp; Servicios</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <div class="btn-group me-1">
                    <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-warehouse me-1"></i> Ajuste de Stock
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item js-stock-op" href="#" data-tipo="aumento">Aumento</a></li>
                        <li><a class="dropdown-item js-stock-op" href="#" data-tipo="disminucion">Disminución</a></li>
                        <li><a class="dropdown-item js-stock-op" href="#" data-tipo="transferencia">Movimiento entre Depósitos</a></li>
                    </ul>
                </div>
                <a href="{{ route('importacion.index', 'productos') }}" class="btn btn-outline-primary me-1">
                    <i class="fas fa-file-import me-1"></i> Importar datos
                </a>
                <button type="button" class="btn btn-outline-primary me-1" id="btn-sincronizar-precios-ml"
                    title="Reintenta el envío de precios pendientes/con error hacia Mercado Libre (spec 016) — sólo productos vinculados a una publicación.">
                    <i class="fas fa-tags me-1"></i> Sincronizar precios ahora
                </button>
                <button type="button" class="btn btn-primary" id="btn-nuevo-producto">
                    <i class="fas fa-plus me-1"></i> Nuevo Producto
                </button>
            </div>
        </div>

        {{-- Panel de Totales ("Ver Totales" — oculto por defecto, se despliega con el ícono de ojo
             del toolbar; fiel a Contagram real, capturas/nuevas/49_productos_ver_totales.jpg). --}}
        <div class="row d-none mb-3" id="panel-totales">
            <div class="col-md-4 col-sm-6 mb-3 mb-md-0">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-primary text-primary">
                                <i class="fas fa-boxes"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">Unidades en Stock</p>
                                <h4 class="mb-0" id="stat-unidades">{{ number_format($stats['unidades_en_stock'], 0, ',', '.') }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3 mb-md-0">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-danger text-danger">
                                <i class="fas fa-tag"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">
                                    Costo Total
                                    <i class="fas fa-question-circle text-info" data-bs-toggle="tooltip" title="Cantidad en stock × costo"></i>
                                </p>
                                <h4 class="mb-0 text-danger" id="stat-costo-total">$ {{ number_format($stats['costo_total'], 2, ',', '.') }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="media ai-icon">
                            <span class="me-3 bgl-success text-success">
                                <i class="fas fa-dollar-sign"></i>
                            </span>
                            <div class="media-body">
                                <p class="mb-1">
                                    Valor Venta Total
                                    <i class="fas fa-question-circle text-info" data-bs-toggle="tooltip" title="Cantidad en stock × precio de venta"></i>
                                </p>
                                <h4 class="mb-0 text-success" id="stat-valor-venta-total">$ {{ number_format($stats['valor_venta_total'], 2, ',', '.') }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">

                {{-- Barra de controles: Filtros · Ver Totales · selector de columnas · Exportar --}}
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>

                    <button type="button" class="btn btn-outline-secondary" id="btn-ver-totales"
                            data-bs-toggle="tooltip" title="Ver Totales">
                        <i class="fas fa-eye-slash"></i>
                    </button>

                    <div class="dropdown">
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                                data-bs-auto-close="outside" title="Columnas">
                            <i class="fas fa-table-columns"></i>
                        </button>
                        <ul class="dropdown-menu p-2" id="menu-columnas" style="min-width:210px"></ul>
                    </div>

                    <button type="button" class="btn btn-outline-primary ms-auto" id="btn-exportar">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>

                {{-- Panel de Filtros (colapsable, como Contagram) --}}
                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Id</label>
                                <input type="number" id="filtro-id" class="form-control" placeholder="Buscar por número de ID">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Producto/Servicio</label>
                                <input type="text" id="filtro-buscar" class="form-control" placeholder="Buscar por Nombre o código">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del Producto/Servicio</label>
                                <select id="filtro-estado" class="form-select">
                                    <option value="activos" selected>Activos</option>
                                    <option value="inactivos">Inactivos</option>
                                    <option value="todos">Todos</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo</label>
                                <select id="filtro-tipo" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="producto">Producto</option>
                                    <option value="servicio">Servicio</option>
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
                            <div class="col-md-3">
                                <label class="form-label">Depósito</label>
                                <select id="filtro-deposito" class="form-select">
                                    <option value="">Todos</option>
                                    @foreach ($depositos as $deposito)
                                        <option value="{{ $deposito->id }}">{{ $deposito->nombre }}</option>
                                    @endforeach
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
                                <label class="form-label">Stock menor que</label>
                                <input type="number" id="filtro-stock-max" class="form-control" step="0.001">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Stock mayor que</label>
                                <input type="number" id="filtro-stock-min" class="form-control" step="0.001">
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

                {{-- Barra de selección (Selección Múltiple + Acciones Masivas, 004) — oculta hasta
                     que haya al menos 1 producto marcado. --}}
                <div class="alert bg-light border d-none align-items-center justify-content-between mb-3" id="barra-seleccion">
                    <span>
                        <strong id="barra-seleccion-cantidad">0</strong> productos seleccionados.
                        <a href="#" id="barra-seleccion-acciones">Haga click aquí para realizar acciones.</a>
                        <a href="#" id="barra-seleccion-todos" class="d-none"></a>
                    </span>
                    <button type="button" class="btn-close" id="barra-seleccion-cerrar" aria-label="Cerrar"></button>
                </div>

                <div class="table-responsive">
                    <table id="tabla-productos" class="table table-hover display responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" class="form-check-input" id="chk-seleccionar-todo">
                                </th>
                                <th>Id</th>
                                <th>Nombre</th>
                                <th>Código/SKU</th>
                                <th>Tipo</th>
                                <th>Tipo de Producto</th>
                                <th>Proveedor</th>
                                <th>Costo</th>
                                <th>Precio venta</th>
                                @foreach ($listasPrecio as $lista)
                                    <th>{{ $lista->nombre }}</th>
                                @endforeach
                                <th>IVA Ventas</th>
                                <th>IVA Compras</th>
                                <th>Stock total</th>
                                {{-- Una columna de stock por depósito activo, dinámica: si se da de
                                     alta un depósito nuevo, aparece su columna sin tocar código. --}}
                                @foreach ($depositosColumnas as $deposito)
                                    <th title="Unidades en el depósito {{ $deposito->nombre }}">{{ $deposito->nombre }}</th>
                                @endforeach
                                <th>Descripción</th>
                                <th>Imagen</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

            </div>
        </div>

    </div>
</div>

@include('productos._modal_form')
@include('productos._modal_stock')
@include('productos._modal_stock_global')
@include('productos._modal_listas')
@include('productos._modal_tipos')
@include('productos._modal_acciones_masivas')
@include('productos._modal_masiva_precios')
@include('productos._modal_masiva_iva')
@include('productos._modal_masiva_tipo_producto')

{{-- Modal de confirmación de eliminación --}}
<div class="modal fade" id="modal-eliminar-producto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Seguro que querés eliminar este producto? Esta acción no se puede deshacer.
                Si el producto tiene operaciones asociadas, sólo podrá inactivarse.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar">Eliminar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
    window.ProductosConfig = {
        rutas: {
            data: "{{ route('productos.data') }}",
            stats: "{{ route('productos.stats') }}",
            export: "{{ route('productos.export') }}",
            store: "{{ route('productos.store') }}",
            show: "{{ url('productos') }}",
            opciones: "{{ route('productos.opciones') }}",
            listas: "{{ url('listas-precio') }}",
            tipos: "{{ url('tipos-producto') }}",
            accionesMasivas: "{{ route('productos.acciones-masivas') }}",
            sincronizarPreciosMl: "{{ route('productos.sincronizarPreciosMl') }}",
            sincronizarPreciosTn: "{{ route('productos.sincronizarPreciosTn') }}",
        },
        listasPrecio: {!! $listasPrecio->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre])->toJson() !!},
        {{-- Mismo orden que el <thead>: las columnas del DataTable tienen que coincidir 1 a 1. --}}
        depositosColumnas: {!! $depositosColumnas->map(fn ($d) => ['id' => $d->id, 'nombre' => $d->nombre])->toJson() !!},
        tiposProducto: {!! $tiposProducto->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre])->toJson() !!},
        proveedores: {!! $proveedores->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])->toJson() !!},
    };
</script>
@vite(['resources/js/productos.js'])
@endsection
