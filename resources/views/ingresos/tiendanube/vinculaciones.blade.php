@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-6 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Vinculación de variantes</h4>
                <p class="text-muted mb-0">Relación 1 a 1 entre variantes de Tiendanube y productos del CRM.</p>
            </div>
            <div class="col-sm-6 mb-2 text-sm-end">
                <a href="{{ route('ingresos.tiendanube.index') }}" class="btn btn-outline-secondary me-1">
                    <i class="fas fa-arrow-left me-1"></i> Volver a Tiendanube
                </a>
                <button type="button" class="btn btn-primary" id="btn-vincular-automaticamente">
                    <i class="fas fa-bolt me-1"></i> Vincular automáticamente
                </button>
                <button type="button" class="btn btn-outline-primary" id="btn-sincronizacion-forzada">
                    <i class="fas fa-rotate me-1"></i> Sincronización forzada
                </button>
                <button type="button" class="btn btn-outline-danger" id="btn-eliminar-todas-vinculaciones">
                    <i class="fas fa-trash me-1"></i> Eliminar todas las vinculaciones
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tabla-tn-vinculaciones" class="table table-hover display responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>Acciones</th>
                                <th>Producto (ID)</th>
                                <th>Variante (ID)</th>
                                <th>Nombre</th>
                                <th>Producto</th>
                                <th>Fecha</th>
                                <th>Stock</th>
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
                    <input type="hidden" id="vinculacion-tn-product-id">
                    <div class="mb-3">
                        <label class="form-label">Variante de Tiendanube</label>
                        <select class="form-select" id="vinculacion-variant-id" style="width:100%" disabled></select>
                        <div class="invalid-feedback" id="error-variant-id"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nombre (opcional, para reconocerla en el listado)</label>
                        <input type="text" class="form-control" id="vinculacion-nombre-variante-tn">
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
                modifican; las órdenes futuras de esta variante quedarán sin resolver.
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
                    Esta acción borra <strong>todas</strong> las vinculaciones con Tiendanube del lado del CRM —
                    no despublica ni modifica nada en Tiendanube. Es <strong>irreversible</strong>: vas a tener
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
    window.TiendanubeVinculacionesConfig = {
        rutas: {
            datatable: @json(route('ingresos.tiendanube.vinculaciones.datatable')),
            vincularAutomaticamente: @json(route('ingresos.tiendanube.vinculaciones.vincularAutomaticamente')),
            sincronizacionForzada: @json(route('ingresos.tiendanube.vinculaciones.sincronizacionForzada')),
            eliminarTodas: @json(route('ingresos.tiendanube.vinculaciones.eliminarTodas')),
            base: @json(url('ingresos/tiendanube/vinculaciones')),
            productosOpciones: @json(route('productos.opciones')),
        },
    };
</script>
@vite(['resources/js/tiendanube-vinculaciones.js'])
@endsection
