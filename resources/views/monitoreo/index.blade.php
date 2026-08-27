@extends('layouts.default')

@section('content')
<style>
    /* Panel de Monitoreo (spec 073) — tabs en vez de acordeón: una tabla densa a la vez, a ancho
       completo, sin la ambigüedad visual de paneles parcialmente abiertos. El punto que
       corresponde a cada pestaña se enciende (rojo/naranja) cuando ese bloque tiene algo
       pendiente, para ver la jerarquía de "qué mirar primero" sin abrir nada. */
    /* Solapas tipo "carpeta": la pestaña activa queda blanca y se funde con el contenido de
       abajo; las inactivas quedan en un gris parejo, para que se note cuál está abierta sin
       depender de una rayita fina abajo. */
    #monitoreo-tabs {
        border-bottom: none;
        gap: 0.35rem;
        align-items: stretch;
        /* En pantallas angostas (laptop, ventana no maximizada) las 6 pestañas no entran en una
           línea. Wrappear a una segunda fila las deja "flotando" desconectadas del contenido de
           abajo — en vez de eso, se scrollean horizontalmente sin romper el layout. */
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
    }
    #monitoreo-tabs .nav-item {
        display: flex;
        flex: 0 0 auto;
    }
    #monitoreo-tabs .nav-link {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--bs-secondary-color, #6c757d);
        background: var(--bs-tertiary-bg, #eef0f4);
        border: 1px solid var(--bs-border-color, #e2e5eb);
        border-bottom: none;
        border-radius: 0.6rem 0.6rem 0 0;
        padding: 0.7rem 1.1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        height: 100%;
        white-space: nowrap;
        transition: background-color .15s ease, color .15s ease;
    }
    #monitoreo-tabs .nav-link:hover {
        background: #e4e7ec;
        color: var(--primary);
    }
    #monitoreo-tabs .nav-link.active {
        color: var(--primary);
        background: #fff;
        border-color: var(--bs-border-color, #e2e5eb);
        position: relative;
        top: 1px;
    }
    #monitoreo-tabs .tab-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #ced4da;
        flex: none;
    }
    #monitoreo-tabs .tab-dot.dot-critico { background: var(--bs-danger, #e5555c); }
    #monitoreo-tabs .tab-dot.dot-atencion { background: var(--bs-warning, #f89d16); }
    #monitoreo-tab-content {
        background: #fff;
        border: 1px solid var(--bs-border-color, #e2e5eb);
        border-radius: 0 0.6rem 0.6rem 0.6rem;
        padding: 1.25rem;
        position: relative;
        z-index: 1;
    }
</style>
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-3">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Monitoreo</h4>
                <small class="text-muted">Estado de las integraciones y del stock que hay que atender.</small>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <span class="text-muted small me-2">Servidor: <span id="pulso-servidor">—</span></span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refrescar-todo">
                    <i class="fas fa-rotate me-1"></i> Refrescar
                </button>
            </div>
        </div>

        {{-- ====================== PULSO ====================== --}}
        <div class="mb-3" id="bloque-pulso">
            <div id="pulso-error" class="alert alert-warning d-none">
                No se pudo leer el estado de las sincronizaciones.
            </div>
            <div class="row mb-0 g-2" id="pulso-contenido">
                <div class="col-6" style="flex:1 1 33.333%; max-width:33.333%;">
                    <div class="widget-stat card mb-0 h-100">
                        <div class="card-body p-3">
                            <div class="media ai-icon">
                                <span class="me-3 bgl-info text-info"><i class="fas fa-cart-shopping"></i></span>
                                <div class="media-body">
                                    <p class="mb-1">Sincronización de órdenes</p>
                                    <h4 class="mb-0" id="pulso-sync-ordenes">—</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6" style="flex:1 1 33.333%; max-width:33.333%;">
                    <div class="widget-stat card mb-0 h-100">
                        <div class="card-body p-3">
                            <div class="media ai-icon">
                                <span class="me-3 bgl-warning text-warning"><i class="fas fa-boxes-stacked"></i></span>
                                <div class="media-body">
                                    <p class="mb-1">Sincronización de stock</p>
                                    <h4 class="mb-0" id="pulso-sync-stock">—</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6" style="flex:1 1 33.333%; max-width:33.333%;">
                    <div class="widget-stat card mb-0 h-100">
                        <div class="card-body p-3">
                            <div class="media ai-icon">
                                <span class="me-3 bgl-success text-success"><i class="fas fa-tags"></i></span>
                                <div class="media-body">
                                    <p class="mb-1">Publicaciones vinculadas</p>
                                    <h4 class="mb-0" id="pulso-publicaciones">—</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ====================== BLOQUES ====================== --}}
        {{-- Tabs en vez de acordeón: una tabla densa a la vez, a ancho completo. Las DataTables
             siguen inicializándose todas al cargar (ocultas o no); `shown.bs.tab` dispara
             `columns.adjust()` para corregir el ancho calculado mientras la pestaña no estaba
             visible (ver monitoreo.js). --}}
        <ul class="nav" id="monitoreo-tabs" role="tablist">
            <li class="nav-item" role="presentation" id="bloque-reponer">
                <button class="nav-link active" id="tab-btn-reponer" data-bs-toggle="tab" data-bs-target="#tab-reponer" type="button" role="tab">
                    <span class="tab-dot" id="dot-reponer"></span>
                    A reponer
                    <span class="badge bg-warning text-dark ms-1 d-none" id="conteo-reponer">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation" id="bloque-publicaciones">
                <button class="nav-link" id="tab-btn-publicaciones" data-bs-toggle="tab" data-bs-target="#tab-publicaciones" type="button" role="tab">
                    <span class="tab-dot" id="dot-publicaciones"></span>
                    Publicaciones con error
                    <span class="badge bg-danger ms-1 d-none" id="conteo-publicaciones">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation" id="bloque-riesgo-ml">
                <button class="nav-link" id="tab-btn-riesgo-ml" data-bs-toggle="tab" data-bs-target="#tab-riesgo-ml" type="button" role="tab">
                    <span class="tab-dot" id="dot-riesgo-ml"></span>
                    Riesgo de publicación
                    <span class="badge bg-warning text-dark ms-1 d-none" id="conteo-riesgo-ml">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation" id="bloque-sin-stock">
                <button class="nav-link" id="tab-btn-sin-stock" data-bs-toggle="tab" data-bs-target="#tab-sin-stock" type="button" role="tab">
                    Sin stock
                    <span class="badge bg-secondary ms-1 d-none" id="conteo-sin-stock">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation" id="bloque-ordenes">
                <button class="nav-link" id="tab-btn-ordenes" data-bs-toggle="tab" data-bs-target="#tab-ordenes" type="button" role="tab">
                    Órdenes sin venta
                    <span class="badge bg-secondary ms-1 d-none" id="conteo-ordenes">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation" id="bloque-precios-ml">
                <button class="nav-link" id="tab-btn-precios-ml" data-bs-toggle="tab" data-bs-target="#tab-precios-ml" type="button" role="tab">
                    Precios ML
                    <span class="badge bg-danger ms-1 d-none" id="conteo-precios-ml">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation" id="bloque-ventas">
                <button class="nav-link" id="tab-btn-ventas" data-bs-toggle="tab" data-bs-target="#tab-ventas" type="button" role="tab">
                    Últimas ventas
                </button>
            </li>
        </ul>

        <div class="tab-content" id="monitoreo-tab-content">

            {{-- ====================== PUBLICACIONES QUE NO ACTUALIZAN STOCK ====================== --}}
            <div class="tab-pane fade js-bloque-body" id="tab-publicaciones" role="tabpanel">
                <p class="text-muted small">Mercado Libre rechazó el stock que el CRM le intentó publicar.</p>

                <div class="alert alert-warning d-none" data-error="publicaciones">
                    No se pudo cargar este bloque.
                </div>
                <div class="alert alert-success d-none" data-vacio="publicaciones">
                    <i class="fas fa-check me-1"></i> Todas las publicaciones están sincronizadas.
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover w-100" id="tabla-publicaciones">
                        <thead>
                            <tr>
                                <th>Publicación</th>
                                <th>Título</th>
                                <th>Stock CRM</th>
                                <th>Publicado</th>
                                <th>Intentos</th>
                                <th>Desde</th>
                                <th>Motivo</th>
                                <th></th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>

            {{-- ====================== A REPONER ====================== --}}
            <div class="tab-pane fade show active js-bloque-body" id="tab-reponer" role="tabpanel">
                <p class="text-muted small">
                    Stock en <strong>Local</strong> en o por debajo del punto de reposición. Es la pregunta
                    &laquo;¿le compro al proveedor o traigo de Full?&raquo;. Los productos sin punto de
                    reposición no se controlan.
                </p>

                <div class="alert alert-warning d-none" data-error="reponer">No se pudo cargar este bloque.</div>
                <div class="alert alert-success d-none" data-vacio="reponer">
                    <i class="fas fa-check me-1"></i> No hay nada por debajo de su punto de reposición.
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover w-100" id="tabla-reponer">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto</th>
                                <th>Local</th>
                                <th>Full</th>
                                <th>Punto rep.</th>
                                <th>Faltan</th>
                                <th>Proveedor</th>
                                <th></th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>

            {{-- ====================== RIESGO DE PUBLICACIÓN ====================== --}}
            <div class="tab-pane fade js-bloque-body" id="tab-riesgo-ml" role="tabpanel">
                <p class="text-muted small">
                    Publicados en Mercado Libre con <strong>Local + Full</strong> en o por debajo del punto
                    de reposición. Es la pregunta &laquo;¿se me cae la publicación?&raquo;. Ordenado por
                    días de stock según lo vendido en las últimas dos semanas.
                </p>

                <div class="alert alert-warning d-none" data-error="riesgo-ml">No se pudo cargar este bloque.</div>
                <div class="alert alert-success d-none" data-vacio="riesgo-ml">
                    <i class="fas fa-check me-1"></i> Ninguna publicación está en riesgo por stock.
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover w-100" id="tabla-riesgo-ml">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Publicación</th>
                                <th>Local</th>
                                <th>Full</th>
                                <th>Vendible</th>
                                <th>Punto rep.</th>
                                <th>Por día</th>
                                <th>Días</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>

            {{-- ====================== SIN STOCK ====================== --}}
            <div class="tab-pane fade js-bloque-body" id="tab-sin-stock" role="tabpanel">
                <p class="text-muted small">Sin stock ni en el depósito de Mercado Libre ni en Full. No vende, pero no es una falla.</p>

                <div class="alert alert-warning d-none" data-error="sin-stock">No se pudo cargar este bloque.</div>
                <div class="alert alert-success d-none" data-vacio="sin-stock">
                    <i class="fas fa-check me-1"></i> Todas las publicaciones tienen stock en algún depósito.
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover w-100" id="tabla-sin-stock">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Publicación</th>
                                <th>Local</th>
                                <th>Full</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>

            {{-- ====================== ÓRDENES SIN VENTA ====================== --}}
            <div class="tab-pane fade js-bloque-body" id="tab-ordenes" role="tabpanel">
                <p class="text-muted small">Órdenes de Mercado Libre que todavía no generaron una Venta, con su motivo.</p>

                <div class="alert alert-warning d-none" data-error="ordenes">No se pudo cargar este bloque.</div>
                <div class="alert alert-success d-none" data-vacio="ordenes">
                    <i class="fas fa-check me-1"></i> Todas las órdenes tienen su venta.
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover w-100" id="tabla-ordenes">
                        <thead>
                            <tr>
                                <th>Orden</th>
                                <th>Comprador</th>
                                <th>Total</th>
                                <th>Cuándo</th>
                                <th>Estado</th>
                                <th>Motivo</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>

            {{-- ====================== ÚLTIMAS VENTAS ====================== --}}
            <div class="tab-pane fade js-bloque-body" id="tab-ventas" role="tabpanel">
                <p class="text-muted small">La cadena de punta a punta: la venta y sus movimientos de stock.</p>

                <div class="alert alert-warning d-none" data-error="ventas">No se pudo cargar este bloque.</div>
                <div class="alert alert-secondary d-none" data-vacio="ventas">
                    Todavía no hay ventas creadas por las integraciones.
                </div>

                <div class="table-responsive">
                    <table class="table table-sm w-100">
                        <thead>
                            <tr>
                                <th>Venta</th>
                                <th>Origen</th>
                                <th>Total</th>
                                <th>Depósito</th>
                                <th>Cuándo</th>
                                <th>Movimientos</th>
                                <th>Neto</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-ventas"></tbody>
                    </table>
                </div>
            </div>

            {{-- ====================== PRECIOS PUBLICADOS EN MERCADO LIBRE (spec 084) ====================== --}}
            <div class="tab-pane fade js-bloque-body" id="tab-precios-ml" role="tabpanel">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <p class="text-muted small mb-0">
                        Lo que está publicado en Mercado Libre contra lo que dice el CRM.
                        Cada publicación se compara contra la lista que le corresponde por su tipo.
                        <span class="d-block" id="precios-ml-corrida">Sin corridas todavía.</span>
                    </p>
                    @can('monitoreo.gestionar')
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-correr-precios-ml">
                            Chequear ahora
                        </button>
                    @endcan
                </div>

                <div class="alert alert-warning d-none" data-error="precios-ml">No se pudo cargar este bloque.</div>
                <div class="alert alert-success d-none" id="precios-ml-ok">
                    Todas las publicaciones tienen el precio que corresponde.
                </div>

                <div id="precios-ml-resumen" class="row g-2 mb-3 d-none"></div>

                <div class="table-responsive d-none" id="precios-ml-tabla-wrap">
                    <table class="table table-sm w-100">
                        <thead>
                            <tr>
                                <th>Publicación</th>
                                <th>Producto</th>
                                <th>Tipo</th>
                                <th class="text-end">En el CRM</th>
                                <th class="text-end">En Mercado Libre</th>
                                <th class="text-end">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-precios-ml"></tbody>
                    </table>
                </div>

                <div id="precios-ml-avisos"></div>
            </div>

        </div>

    </div>
</div>

@can('monitoreo.gestionar')
    @include('monitoreo._modal_punto_reposicion')
@endcan
@endsection

@section('local-js')
<script>
    window.MonitoreoConfig = {
        rutas: {
            pulso: "{{ route('monitoreo.pulso') }}",
            publicaciones: "{{ route('monitoreo.publicaciones') }}",
            reponer: "{{ route('monitoreo.reponer') }}",
            riesgoMl: "{{ route('monitoreo.riesgoMl') }}",
            sinStock: "{{ route('monitoreo.sinStock') }}",
            ordenes: "{{ route('monitoreo.ordenes') }}",
            ventas: "{{ route('monitoreo.ventas') }}",
            preciosMl: "{{ route('monitoreo.preciosMercadoLibre') }}",
            @can('monitoreo.gestionar')
                destrabar: "{{ route('monitoreo.destrabar') }}",
                reactivar: "{{ route('monitoreo.reactivar') }}",
                sincronizar: "{{ route('monitoreo.sincronizar') }}",
                puntoReposicion: "{{ route('monitoreo.puntoReposicion') }}",
                correrPreciosMl: "{{ route('monitoreo.preciosMercadoLibre.correr') }}",
            @endcan
        },
        puedeGestionar: @json(auth()->user()->tienePermiso('monitoreo.gestionar')),
        bloque: @json($bloque),
    };
</script>
@vite(['resources/js/monitoreo.js'])
@endsection
