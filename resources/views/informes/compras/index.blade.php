@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Informe de Compras</h4>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                @can('informes.exportar')
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-pdf">
                    <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                </button>
                <button type="button" class="btn btn-success" id="btn-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
                @endcan
            </div>
        </div>

        {{-- KPIs. La ecuación se muestra escrita, no sólo insinuada por el orden de las cards:
             es la forma de que quien lee el informe pueda verificar el número de arriba. --}}
        @include('informes.partials.pestanas', ['informe' => 'compras', 'rankings' => ['proveedores' => 'Proveedores', 'categorias' => 'Categorías', 'productos' => 'Productos', 'tipos_producto' => 'Tipo de Producto']])

        <div class="row mb-2 g-2 js-solo-detalle" id="panel-kpis">
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Total Compras Creadas</p>
                        <h4 class="mb-0" id="kpi-creadas">$ 0,00</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Total Nota de Débito</p>
                        <h4 class="mb-0" id="kpi-nd">$ 0,00</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Total Nota de Crédito</p>
                        <h4 class="mb-0" id="kpi-nc">$ 0,00</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100 border border-primary">
                    <div class="card-body p-3">
                        <p class="mb-1 fw-bold">Total Compras</p>
                        <h4 class="mb-0 text-primary" id="kpi-total">$ 0,00</h4>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-muted small mb-3" id="leyenda-ecuacion">
            Total Compras = Total Compras Creadas + Total Nota de Débito &minus; Total Nota de Crédito
        </p>

        <div class="js-solo-detalle row mb-3 g-2">
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Cantidad Prod./Serv.</p>
                        <h4 class="mb-0" id="kpi-unidades">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Cantidad Compras Creadas</p>
                        <h4 class="mb-0" id="kpi-cantidad">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Compra Promedio</p>
                        <h4 class="mb-0" id="kpi-promedio">$ 0,00</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">
                            Costo Actual
                            {{-- Tooltip obligatorio (FR-012): sin esta aclaración el número se lee
                                 como "lo que costó comprar", que es exactamente lo que NO es. --}}
                            <i class="fas fa-circle-info text-muted ms-1"
                               data-bs-toggle="tooltip"
                               id="tooltip-costo-actual"
                               title="Valoriza las cantidades compradas al costo VIGENTE HOY de cada producto, no al costo al que se compró. Si el costo del producto se editó después de la compra, este número cambia retroactivamente."></i>
                        </p>
                        <h4 class="mb-0" id="kpi-costo">$ 0,00</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group" style="width:230px">
                            <input type="text" id="filtro-rango-emision" class="form-control" placeholder="Emisión" autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-emision" title="Quitar filtro de Emisión"><i class="fas fa-times"></i></button>
                        </div>
                        <span id="dt-buttons-compras"></span>
                    </div>
                </div>

                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Id</label>
                                <input type="text" id="filtro-id" class="form-control" placeholder="Buscar por número de ID">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Producto/Servicio</label>
                                <input type="text" id="filtro-producto-servicio" class="form-control" placeholder="Buscar en la descripción">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo de Producto</label>
                                <select id="filtro-tipo-producto" class="form-select" multiple>
                                    @foreach ($tiposProducto as $tp)
                                        <option value="{{ $tp->id }}">{{ $tp->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Etiqueta</label>
                                <select id="filtro-etiqueta" class="form-select" multiple>
                                    @foreach ($etiquetas as $e)
                                        <option value="{{ $e->id }}">{{ $e->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Producto</label>
                                <select id="filtro-producto" class="form-select" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Facturado</label>
                                <select id="filtro-facturado" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="si">Sí</option>
                                    <option value="no">No</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Categoría de Compra</label>
                                <select id="filtro-categoria" class="form-select" multiple>
                                    @foreach ($categoriasCompra as $c)
                                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Proveedor</label>
                                <select id="filtro-proveedor" class="form-select" multiple></select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Tipo de Comprobante</label>
                                <select id="filtro-tipo-comprobante" class="form-select" multiple>
                                    @foreach (['A', 'B', 'C', 'X'] as $tc)
                                        <option value="{{ $tc }}">{{ $tc }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">N° de Comprobante</label>
                                <input type="text" id="filtro-nro-comprobante" class="form-control" placeholder="Todos">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Usuario</label>
                                <select id="filtro-usuario" class="form-select" multiple>
                                    @foreach ($usuarios as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Observación</label>
                                <input type="text" id="filtro-observacion" class="form-control" placeholder="Buscar en la nota interna">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Estado del Pago</label>
                                <select id="filtro-estado-pago" class="form-select" multiple>
                                    <option value="a_pagar">A Pagar</option>
                                    <option value="parcial">Parcial</option>
                                    <option value="pagado">Pagado</option>
                                    <option value="vencido">Vencido</option>
                                </select>
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

                <div class="table-responsive js-solo-detalle">
                    <table id="tabla-informe-compras" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Fecha</th>
                                <th>Comprobante</th>
                                <th>Proveedor</th>
                                <th>Producto/Servicio</th>
                                <th class="text-end">Cant.</th>
                                <th class="text-end">Precio</th>
                                <th class="text-end">Total Comprobante</th>
                                <th>Vencimiento</th>
                                <th>CUIT/DNI</th>
                                <th>Tipo</th>
                                <th>Tipo de Comprobante</th>
                                <th>Punto de Venta</th>
                                <th>N° Factura</th>
                                <th>Código</th>
                                <th>Tipo de Producto</th>
                                <th class="text-end">Costo</th>
                                <th class="text-end">Subtotal sin Descuento</th>
                                <th class="text-end">Descuento en $</th>
                                <th class="text-end">Subtotal con Descuento</th>
                                <th class="text-end">Importe Neto No Gravado</th>
                                <th class="text-end">Importe Neto Exento</th>
                                <th class="text-end">Importe Neto Gravado</th>
                                <th class="text-end">IVA 2,5%</th>
                                <th class="text-end">IVA 5%</th>
                                <th class="text-end">IVA 10,5%</th>
                                <th class="text-end">IVA 21%</th>
                                <th class="text-end">IVA 27%</th>
                                <th class="text-end">Perc. IVA</th>
                                <th class="text-end">Perc. IIBB</th>
                                <th class="text-end">Otras Percepciones</th>
                                <th class="text-end">Imp. Internos</th>
                                <th class="text-end">Total Compra</th>
                                <th>Etiquetas</th>
                                <th>Afecta Stock</th>
                                <th>Operación</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>


        @include('informes.partials.pivot', ['informe' => 'compras'])

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.InformeComprasConfig = {
        rutas: {
            data: @json(route('informes.compras.data')),
            stats: @json(route('informes.compras.stats')),
            exportar: @json(route('informes.compras.exportar')),
            pdf: @json(route('informes.compras.pdf')),
            pivotDataset: @json(route('informes.compras.pivot.dataset')),
            pivotExportar: @json(route('informes.compras.pivot.exportar')),
            pivotVistas: @json(route('informes.compras.pivot.vistas.index')),
            pivotVistaBase: @json(url('informes/compras/pivot/vistas')),
            proveedoresOpciones: @json(route('proveedores.opciones')),
            productosOpciones: @json(route('productos.opciones')),
        },
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/informes-pivot.js', 'resources/js/informes-pivot-pantalla.js', 'resources/js/informe-compras.js'])
@endsection
