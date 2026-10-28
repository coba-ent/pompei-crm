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
                    {{-- spec 115/FR-017: filtro server-side por tipo de publicación. --}}
                    <div class="col-12 col-md-4 col-lg-3">
                        <label for="filtro-tipo" class="form-label mb-1">Tipo de publicación</label>
                        <select id="filtro-tipo" class="form-select">
                            <option value="">Todos</option>
                            <option value="gold_special">Clásica</option>
                            <option value="gold_pro">Premium</option>
                            <option value="sin_clasificar">Sin clasificar</option>
                        </select>
                    </div>
                </div>

{{-- ====================== PRECIOS RETENIDOS POR EL CORTE (spec 084, US1) ======================
     Va FUERA del `.table-responsive` de la tabla de vinculaciones: al insertarla quedó anidada
     dentro de él, así que esta tabla no tenía contenedor con scroll propio y la columna "Motivo"
     —la que explica por qué se frenó el precio— se cortaba sin forma de llegar a ella. --}}
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
                        <div class="table-responsive">
                            {{-- Sin `nowrap`: las columnas Producto y Motivo traen textos largos —el
                                 producto repite su código dos veces y el motivo es una frase entera—
                                 y forzarlos a una línea estiraba la tabla al punto de que el scroll
                                 no alcanzaba para llegar a las Acciones. Acá conviene que bajen de
                                 renglón. --}}
                            <table id="tabla-retenciones-precio" class="table table-hover display" style="width:100%">
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
                </div>

                {{-- spec 115 (US3): cambio de tipo de las filas marcadas en esta página. --}}
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-primary js-cambiar-tipo-lote" data-destino="gold_pro" disabled>
                        <i class="fas fa-arrow-up me-1"></i> Pasar selección a Premium
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary js-cambiar-tipo-lote" data-destino="gold_special" disabled>
                        <i class="fas fa-arrow-down me-1"></i> Pasar selección a Clásica
                    </button>
                    <small class="text-muted" id="conteo-seleccion-vinculaciones">Ninguna publicación seleccionada</small>
                </div>

                <div class="table-responsive">
                    <table id="tabla-ml-vinculaciones" class="table table-hover display nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th class="no-colvis"><input type="checkbox" class="form-check-input" id="check-todas-vinculaciones" title="Seleccionar todas las de esta página"></th>
                                <th class="no-colvis">Acciones</th>
                                <th>Publicación (ID)</th>
                                <th>Título</th>
                                <th title="Producto del CRM: #ID · código — nombre">Producto</th>
                                <th title="Tipo de publicación en Mercado Libre. Premium cobra más comisión y cotiza por la lista ML Premium.">Tipo</th>
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

{{-- spec 115 (US1): confirmación del cambio de tipo de una publicación. --}}
<div class="modal fade" id="modal-cambio-tipo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-cambio-tipo-titulo">Cambiar tipo de publicación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-4" id="cambio-tipo-cargando">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="text-muted small mt-2">Consultando la publicación en Mercado Libre…</div>
                </div>
                <div class="d-none" id="cambio-tipo-contenido">
                    <dl class="row mb-0 small">
                        <dt class="col-4">Publicación</dt>
                        <dd class="col-8" id="cambio-tipo-publicacion"></dd>
                        <dt class="col-4">Producto</dt>
                        <dd class="col-8" id="cambio-tipo-producto"></dd>
                        <dt class="col-4">Tipo</dt>
                        <dd class="col-8" id="cambio-tipo-tipos"></dd>
                        <dt class="col-4">Precio</dt>
                        <dd class="col-8" id="cambio-tipo-precios"></dd>
                        <dt class="col-4">Lista de precios</dt>
                        <dd class="col-8" id="cambio-tipo-lista"></dd>
                        <dt class="col-4">Cuotas actuales</dt>
                        <dd class="col-8" id="cambio-tipo-cuotas-actual"></dd>
                    </dl>
                    {{-- Ajuste 16/10/2026 (FR-019): las cuotas se eligen en la misma confirmación. --}}
                    <div class="mt-3 d-none" id="cambio-tipo-cuotas-bloque">
                        <label class="form-label small fw-semibold mb-1">Cuotas en la publicación</label>
                        <div id="cambio-tipo-cuotas"></div>
                        <div class="form-text" id="cambio-tipo-cuotas-ayuda">Cargo de Mercado Libre por cada venta al precio nuevo (comisión + costo de las cuotas).</div>
                    </div>
                    <div class="alert alert-warning small mt-3 mb-0 d-none" id="cambio-tipo-aviso-comision">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Premium cobra <strong>más comisión por venta</strong> que Clásica.
                    </div>
                    {{-- spec 116 (FR-016): el texto lo arma el JS con las cuotas vigentes. --}}
                    <div class="alert alert-warning small mt-3 mb-0 d-none" id="cambio-tipo-aviso-cuotas"></div>
                    <div class="alert alert-info small mt-3 mb-0 d-none" id="cambio-tipo-sin-cambios"></div>
                    <div class="alert alert-danger small mt-3 mb-0 d-none" id="cambio-tipo-bloqueo"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-cambio-tipo" disabled>Confirmar</button>
            </div>
        </div>
    </div>
</div>

{{-- spec 115 (US3): confirmación, progreso y resumen del cambio de tipo masivo. --}}
<div class="modal fade" id="modal-cambio-tipo-lote" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-cambio-tipo-lote-titulo">Cambiar tipo de publicación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-4" id="cambio-tipo-lote-cargando">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
                <div class="d-none" id="cambio-tipo-lote-confirmacion">
                    <div class="alert alert-warning small d-none" id="cambio-tipo-lote-aviso-comision">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Premium cobra <strong>más comisión por venta</strong> que Clásica.
                    </div>
                    {{-- spec 116 (FR-017) --}}
                    <div class="alert alert-warning small d-none" id="cambio-tipo-lote-aviso-cuotas">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Las publicaciones con <strong>cuotas sin interés</strong> las pierden al pasar a Clásica.
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Cuotas en todas las publicaciones</label>
                        <div id="cambio-tipo-lote-cuotas"></div>
                        <div class="form-text">Si la categoría de alguna publicación no admite la opción elegida, esa publicación no se cambia y figura en el resumen.</div>
                    </div>
                    <h6>Se van a cambiar (<span id="cambio-tipo-lote-conteo-cambiar">0</span>)</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Publicación</th><th>Producto</th><th class="text-end">Precio actual</th><th class="text-end">Precio nuevo</th></tr></thead>
                            <tbody id="cambio-tipo-lote-cambiar"></tbody>
                        </table>
                    </div>
                    <h6>Omitidas (<span id="cambio-tipo-lote-conteo-omitidas">0</span>)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Publicación</th><th>Motivo</th></tr></thead>
                            <tbody id="cambio-tipo-lote-omitidas"></tbody>
                        </table>
                    </div>
                    <p class="text-muted small mt-3 mb-0">
                        Al procesar cada publicación se vuelve a verificar contra Mercado Libre su tipo vigente
                        y que el destino esté disponible.
                    </p>
                </div>
                <div class="d-none" id="cambio-tipo-lote-progreso">
                    <p class="mb-2" id="cambio-tipo-lote-progreso-texto">Procesando…</p>
                    <div class="progress" style="height: 1.25rem;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="cambio-tipo-lote-barra" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>
                <div class="d-none" id="cambio-tipo-lote-resumen"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" id="btn-cerrar-cambio-tipo-lote">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-cambio-tipo-lote" disabled>Confirmar</button>
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
            // spec 115: __ID__ se reemplaza por el id del vínculo en el JS.
            cambioTipoPrevisualizar: @json(route('ingresos.mercadolibre.vinculaciones.cambioTipo.previsualizar', '__ID__')),
            cambioTipoEjecutar: @json(route('ingresos.mercadolibre.vinculaciones.cambioTipo.ejecutar', '__ID__')),
            cambioTipoLotePrevisualizar: @json(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.previsualizar')),
            cambioTipoLoteEjecutar: @json(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.ejecutar')),
            cambioTipoLoteEstado: @json(route('ingresos.mercadolibre.vinculaciones.cambioTipoLote.estado')),
        },
    };
</script>
@vite(['resources/js/mercadolibre-vinculaciones.js'])
@endsection
