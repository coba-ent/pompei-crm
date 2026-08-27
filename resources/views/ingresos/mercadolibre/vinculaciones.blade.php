@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="mb-4">
            <div class="mb-2">
                <h4 class="mb-0 text-primary fw-bold">Vinculación de publicaciones</h4>
                <p class="text-muted mb-0">Relación 1 a 1 entre publicaciones de Mercado Libre y productos del CRM.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('ingresos.mercadolibre.index') }}" class="btn btn-outline-secondary" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Volver al listado de Mercado Libre">
                    <i class="fas fa-arrow-left me-1"></i> Volver a Mercado Libre
                </a>
                <button type="button" class="btn btn-primary" id="btn-vincular-automaticamente" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Vincula automáticamente las publicaciones cuyo SKU coincide con el código de un producto del CRM">
                    <i class="fas fa-bolt me-1"></i> Vincular automáticamente
                </button>
                <button type="button" class="btn btn-outline-primary" id="btn-sincronizacion-forzada" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Reenvía stock y precio de todas las publicaciones ya vinculadas a Mercado Libre, ahora mismo">
                    <i class="fas fa-rotate me-1"></i> Sincronización forzada
                </button>
                <button type="button" class="btn btn-outline-danger" id="btn-eliminar-todas-vinculaciones" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Desvincula todas las publicaciones de sus productos del CRM (no borra publicaciones ni productos)">
                    <i class="fas fa-trash me-1"></i> Eliminar todas las vinculaciones
                </button>
                <span id="dt-buttons-ml-vinculaciones"></span>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                {{-- spec 065/FR-025: filtro server-side por tipo de logística. Select2 por regla
                     obligatoria #5 (select de datos dinámicos), sin recargar la página (#2). --}}
                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-4 col-lg-3">
                        <label for="filtro-logistica" class="form-label mb-1">Tipo de logística</label>
                        <select id="filtro-logistica" class="form-select">
                            <option value="">Todas</option>
                            <option value="fulfillment">Full</option>
                            <option value="xd_drop_off">Colecta</option>
                            <option value="self_service">Flex</option>
                            <option value="custom">A cargo del vendedor</option>
                            <option value="not_specified">Sin especificar</option>
                            <option value="sin_clasificar">Sin clasificar</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
{{-- ====================== PRECIOS RETENIDOS POR EL CORTE (spec 084, US1) ====================== --}}
        <div class="card border-warning mb-4 d-none" id="card-retenciones-precio">
            <div class="card-header bg-warning-subtle d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-0"><i class="fas fa-hand me-1"></i> Precios frenados, esperando aprobación</h5>
                    <small class="text-muted">
                        Estas bajadas <strong>no se publicaron</strong>: en Mercado Libre sigue el precio anterior.
                    </small>
                </div>
                <span class="badge bg-warning text-dark" id="conteo-retenciones-precio">0</span>
            </div>
            <div class="card-body">
                <table id="tabla-retenciones-precio" class="table table-hover display nowrap" style="width:100%">
                    <thead>
                        <tr>
                            <th>Publicación</th>
                            <th>Producto</th>
                            <th>Tipo</th>
                            <th class="text-end">Publicado hoy</th>
                            <th class="text-end">Se quiso publicar</th>
                            <th class="text-end">Caída</th>
                            <th>Motivo</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>

                    <table id="tabla-ml-vinculaciones" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Acciones</th>
                                <th>Publicación (ID)</th>
                                <th>Título</th>
                                <th>Producto</th>
                                <th>Fecha</th>
                                <th title="Cómo despacha Mercado Libre esta publicación. Las que están en Full las despacha Mercado Libre desde su propio centro de distribución: el CRM no les envía stock.">
                                    Logística
                                </th>
                                <th title="Unidades disponibles en el depósito que publica Mercado Libre. NO es el stock total del producto sumando todos los depósitos.">
                                    Stock publicado
                                </th>
                                <th>Sincronización</th>
                                <th title="Diferencia entre el stock del CRM y lo último que se confirmó publicado en Mercado Libre (spec 063).">
                                    Diferencia
                                </th>
                                <th>Precio</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modal-vinculacion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-vinculacion">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-vinculacion-titulo">Editar vinculación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="vinculacion-id">
                    <div class="mb-3">
                        <label class="form-label">Publicación de Mercado Libre</label>
                        <select class="form-select" id="vinculacion-ml-item-id" style="width:100%"></select>
                        <div class="form-text">Salen de las órdenes de Mercado Libre ya sincronizadas; las ya vinculadas no aparecen.</div>
                        <div class="invalid-feedback" id="error-ml-item-id"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título (opcional, para reconocerla en el listado)</label>
                        <input type="text" class="form-control" id="vinculacion-titulo-ml">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Producto del CRM</label>
                        <select class="form-select" id="vinculacion-producto-id" style="width:100%"></select>
                        <div class="invalid-feedback" id="error-producto-id"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-eliminar-vinculacion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar vinculación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Seguro que querés eliminar esta vinculación? Las Ventas ya creadas con este producto no se
                modifican; las órdenes futuras de esta publicación quedarán sin resolver.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-vinculacion">Eliminar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-eliminar-todas-vinculaciones" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar todas las vinculaciones</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Esta acción borra <strong>todas</strong> las vinculaciones con Mercado Libre del lado del CRM —
                    no despublica ni modifica nada en Mercado Libre. Es <strong>irreversible</strong>: vas a tener
                    que volver a vincular los productos (por ejemplo con "Vincular automáticamente").
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-todas-vinculaciones">Eliminar todas</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-resultado-vinculacion-automatica" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Resultado de la vinculación automática</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="resultado-vinculacion-automatica-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
    window.MercadoLibreVinculacionesConfig = {
        rutas: {
            datatable: @json(route('ingresos.mercadolibre.vinculaciones.datatable')),
            pendientes: @json(route('ingresos.mercadolibre.vinculaciones.pendientes')),
            vincularAutomaticamente: @json(route('ingresos.mercadolibre.vinculaciones.vincularAutomaticamente')),
            sincronizacionForzada: @json(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada')),
            sincronizacionForzadaEstado: @json(route('ingresos.mercadolibre.vinculaciones.sincronizacionForzada.estado')),
            eliminarTodas: @json(route('ingresos.mercadolibre.vinculaciones.eliminarTodas')),
            base: @json(url('ingresos/mercadolibre/vinculaciones')),
            productosOpciones: @json(route('productos.opciones')),
            retenciones: @json(route('ingresos.mercadolibre.retencionesPrecio.index')),
            retencionesBase: @json(url('ingresos/mercadolibre/retenciones-precio')),
        },
    };
</script>
@vite(['resources/js/mercadolibre-vinculaciones.js'])
@endsection
