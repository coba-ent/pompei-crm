@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Informe de Ventas</h4>
            </div>
            {{-- Los rótulos son los literales de Contagram, no genéricos (FR-020). --}}
            <div class="col-sm-6 mb-2 text-sm-end">
                <button type="button" class="btn btn-outline-secondary" id="btn-exportar-pdf">
                    <i class="fas fa-file-pdf me-1"></i> Exportar a PDF
                </button>
                <button type="button" class="btn btn-success" id="btn-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar Resumen
                </button>
            </div>
        </div>

        {{-- Bloque 1: la ecuación se escribe, no se insinúa por el orden de las cards. Es lo que
             permite a quien lee el informe verificar el número destacado (FR-010). --}}
        @include('informes.partials.pestanas', ['informe' => 'ventas', 'rankings' => ['clientes' => 'Clientes', 'categorias' => 'Categorías', 'productos' => 'Productos', 'tipos_producto' => 'Tipo de Producto', 'vendedores' => 'Vendedores']])

        <div class="row mb-2 g-2 js-solo-detalle" id="panel-kpis">
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Total Ventas Creadas</p>
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
                        <p class="mb-1 fw-bold">Total Ventas</p>
                        <h4 class="mb-0 text-primary" id="kpi-total">$ 0,00</h4>
                    </div>
                </div>
            </div>
        </div>

        <p class="js-solo-detalle text-muted small mb-3">
            Total Ventas = Total Ventas Creadas + Total Nota de Débito &minus; Total Nota de Crédito
        </p>

        {{-- Bloque 2: cantidades, promedio y costo vigente. --}}
        <div class="js-solo-detalle row mb-3 g-2">
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">
                            Cantidad Prod./Serv.
                            <i class="fas fa-circle-info text-muted ms-1" data-bs-toggle="tooltip"
                               title="Suma de las cantidades de todas las líneas del período, no la cantidad de líneas: 10 unidades en una sola línea son 10, no 1."></i>
                        </p>
                        <h4 class="mb-0" id="kpi-unidades">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Cantidad Ventas Creadas</p>
                        <h4 class="mb-0" id="kpi-cantidad">0</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Venta Promedio</p>
                        <h4 class="mb-0" id="kpi-promedio">$ 0,00</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">
                            Costo Actual
                            {{-- Sin esta aclaración el número se lee como "lo que costó lo vendido",
                                 que es justo lo que NO es: eso es el CMV del bloque de abajo. --}}
                            <i class="fas fa-circle-info text-muted ms-1" data-bs-toggle="tooltip"
                               id="tooltip-costo-actual"
                               title="Valoriza las cantidades vendidas al costo VIGENTE HOY de cada producto, no al costo al que se compró. Si el costo del producto se editó después de la venta, este número cambia retroactivamente."></i>
                        </p>
                        <h4 class="mb-0" id="kpi-costo">$ 0,00</h4>
                    </div>
                </div>
            </div>
        </div>

        {{-- Bloque 3: el resultado del período. --}}
        <div class="js-solo-detalle row mb-2 g-2">
            <div class="col-6 col-md-4">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">Precio Neto</p>
                        <h4 class="mb-0" id="kpi-neto">$ 0,00</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="widget-stat card mb-0 h-100">
                    <div class="card-body p-3">
                        <p class="mb-1">
                            Costo Mercadería Vendida
                            <i class="fas fa-circle-info text-muted ms-1" data-bs-toggle="tooltip"
                               id="tooltip-cmv"
                               title="Costo que tenía cada producto al momento de la venta, por la cantidad vendida. No se recalcula si después cambia el costo del producto. Las ventas anteriores a la puesta en marcha de este cálculo no tienen ese costo guardado: para ésas se usa el promedio ponderado de las compras registradas del producto, y un producto que nunca se compró aporta 0."></i>
                        </p>
                        <h4 class="mb-0" id="kpi-cmv">$ 0,00</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="widget-stat card mb-0 h-100 border border-primary">
                    <div class="card-body p-3">
                        <p class="mb-1 fw-bold">Resultado</p>
                        <h4 class="mb-0 text-primary" id="kpi-resultado">$ 0,00</h4>
                    </div>
                </div>
            </div>
        </div>

        <p class="js-solo-detalle text-muted small mb-3">
            Resultado = Precio Neto &minus; Costo Mercadería Vendida
        </p>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#panel-filtros">
                        <i class="fas fa-filter me-1"></i> Filtros
                    </button>
                    <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-group" style="width:230px">
                            <input type="text" id="filtro-rango-emision" class="form-control" placeholder="Emisión">
                            <button type="button" class="btn btn-outline-secondary" id="btn-limpiar-rango-emision" title="Quitar filtro de Emisión"><i class="fas fa-times"></i></button>
                        </div>
                        <span id="dt-buttons-ventas"></span>
                    </div>
                </div>

                {{-- Los 19 campos relevados, en las 5 filas del panel de Contagram (FR-018).
                     Los 3 campos restantes de los "22" que declara el relevamiento no están
                     identificados en ninguna fuente y quedan como brecha documentada; no se
                     inventan. --}}
                <div class="collapse mb-3" id="panel-filtros">
                    <div class="border rounded p-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Id</label>
                                <input type="text" id="filtro-id" class="form-control" placeholder="Buscar por número de ID">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Producto/Servicio</label>
                                <select id="filtro-producto" class="form-select" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo de Producto/Servicio</label>
                                <select id="filtro-tipo-producto" class="form-select" multiple>
                                    @foreach ($tiposProducto as $tp)
                                        <option value="{{ $tp->id }}">{{ $tp->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cliente</label>
                                <select id="filtro-cliente" class="form-select" multiple></select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Productos</label>
                                <select id="filtro-solo-productos" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="si">Sólo con producto de catálogo</option>
                                    <option value="no">Sólo conceptos libres</option>
                                </select>
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
                                <label class="form-label">Vendedor</label>
                                <select id="filtro-vendedor" class="form-select" multiple>
                                    @foreach ($vendedores as $v)
                                        <option value="{{ $v->id }}">{{ $v->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Categoría de Venta</label>
                                <select id="filtro-categoria" class="form-select" multiple>
                                    @foreach ($categoriasVenta as $c)
                                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Proveedor</label>
                                <select id="filtro-proveedor" class="form-select" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Etiqueta</label>
                                <select id="filtro-etiqueta" class="form-select" multiple>
                                    @foreach ($etiquetas as $e)
                                        <option value="{{ $e->id }}">{{ $e->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Tipo de Factura</label>
                                <select id="filtro-tipo-comprobante" class="form-select" multiple>
                                    @foreach (['A', 'B', 'C', 'E'] as $tc)
                                        <option value="{{ $tc }}">{{ $tc }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">N° de Factura</label>
                                <input type="text" id="filtro-nro-comprobante" class="form-control" placeholder="Todos">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Usuario</label>
                                <select id="filtro-usuario" class="form-select" multiple>
                                    @foreach ($usuarios as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Nota Cliente</label>
                                <input type="text" id="filtro-nota-cliente" class="form-control" placeholder="Buscar en la nota al cliente">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nota Interna</label>
                                <input type="text" id="filtro-nota-interna" class="form-control" placeholder="Buscar en la nota interna">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Estado del Cobro</label>
                                <select id="filtro-estado-cobro" class="form-select" multiple>
                                    <option value="pendiente">Pendiente</option>
                                    <option value="parcial">Parcial</option>
                                    <option value="cobrado">Cobrado</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    Tipo
                                    <i class="fas fa-circle-info text-muted ms-1" data-bs-toggle="tooltip"
                                       title="Tipo de operación del comprobante. No confundir con Tipo de Factura, que es el comprobante fiscal."></i>
                                </label>
                                <select id="filtro-tipo-operacion" class="form-select" multiple>
                                    <option value="venta">Venta</option>
                                    <option value="nc">Nota de Crédito</option>
                                    <option value="nd">Nota de Débito</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Remitos</label>
                                <select id="filtro-remitos" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="si">Con remito</option>
                                    <option value="no">Sin remito</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Tipo de Remito</label>
                                <select id="filtro-tipo-remito" class="form-select" multiple>
                                    @foreach (['A', 'B', 'C', 'X', 'E'] as $tr)
                                        <option value="{{ $tr }}">{{ $tr }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">N° de Remito</label>
                                <input type="text" id="filtro-nro-remito" class="form-control" placeholder="Todos">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Transportista</label>
                                <select id="filtro-transportista" class="form-select" multiple>
                                    @foreach ($transportistas as $t)
                                        <option value="{{ $t->id }}">{{ $t->nombre }}</option>
                                    @endforeach
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

                {{-- Las 12 columnas del relevamiento, en su orden exacto, con scroll horizontal
                     (FR-015). --}}
                <div class="table-responsive js-solo-detalle">
                    <table id="tabla-informe-ventas" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Id</th>
                                <th>Fecha</th>
                                <th>Comprobante</th>
                                <th>Cliente</th>
                                <th>Prod./Serv.</th>
                                <th class="text-end">Cant.</th>
                                <th class="text-end">Precio Unitario</th>
                                <th class="text-end">Costo Total Actual</th>
                                <th class="text-end">CMV Total</th>
                                <th class="text-end">Precio Total Neto</th>
                                <th class="text-end">Result.</th>
                                <th class="text-end">Total Comprobante</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>


        @include('informes.partials.pivot', ['informe' => 'ventas'])

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.InformeVentasConfig = {
        rutas: {
            data: @json(route('informes.ventas.data')),
            stats: @json(route('informes.ventas.stats')),
            exportar: @json(route('informes.ventas.exportar')),
            pdf: @json(route('informes.ventas.pdf')),
            pivotDataset: @json(route('informes.ventas.pivot.dataset')),
            pivotExportar: @json(route('informes.ventas.pivot.exportar')),
            pivotVistas: @json(route('informes.ventas.pivot.vistas.index')),
            pivotVistaBase: @json(url('informes/ventas/pivot/vistas')),
            clientesOpciones: @json(route('clientes.opciones')),
            productosOpciones: @json(route('productos.opciones')),
            proveedoresOpciones: @json(route('proveedores.opciones')),
        },
    };
</script>
@vite(['resources/js/rango-emision.js', 'resources/js/informes-pivot.js', 'resources/js/informes-pivot-pantalla.js', 'resources/js/informe-ventas.js'])
@endsection
