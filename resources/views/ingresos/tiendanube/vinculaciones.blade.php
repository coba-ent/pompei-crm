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
                <button type="button" class="btn btn-primary" id="btn-nueva-vinculacion">
                    <i class="fas fa-plus me-1"></i> Nueva vinculación
                </button>
                <button type="button" class="btn btn-outline-primary" id="btn-importar-vinculaciones">
                    <i class="fas fa-file-import me-1"></i> Importar desde Tiendanube
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
                    <h5 class="modal-title" id="modal-vinculacion-titulo">Nueva vinculación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="vinculacion-id">
                    <input type="hidden" id="vinculacion-tn-product-id">
                    <div class="mb-3">
                        <label class="form-label">Variante de Tiendanube</label>
                        <select class="form-select" id="vinculacion-variant-id" style="width:100%"></select>
                        <div class="form-text">Salen de las órdenes de Tiendanube ya sincronizadas; las ya vinculadas no aparecen.</div>
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

<div class="modal fade" id="modal-importar-vinculaciones" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form id="form-importar-vinculaciones">
                <div class="modal-header">
                    <h5 class="modal-title">Importar desde Tiendanube</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Archivo de productos exportado desde Tiendanube</label>
                        <input type="file" class="form-control" id="importar-archivo" accept=".xlsx,.xls,.csv">
                        <div class="form-text">Subí el archivo tal como lo exporta Tiendanube (Productos → Exportar), sin editarlo.</div>
                        <div class="invalid-feedback" id="error-importar-archivo"></div>
                    </div>
                    <div id="resultado-importar-vinculaciones"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-primary" id="btn-confirmar-importar-vinculaciones">Importar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('local-js')
<script>
    window.TiendanubeVinculacionesConfig = {
        rutas: {
            datatable: @json(route('ingresos.tiendanube.vinculaciones.datatable')),
            pendientes: @json(route('ingresos.tiendanube.vinculaciones.pendientes')),
            store: @json(route('ingresos.tiendanube.vinculaciones.store')),
            importar: @json(route('ingresos.tiendanube.vinculaciones.importar')),
            base: @json(url('ingresos/tiendanube/vinculaciones')),
            productosOpciones: @json(route('productos.opciones')),
        },
    };
</script>
@vite(['resources/js/tiendanube-vinculaciones.js'])
@endsection
