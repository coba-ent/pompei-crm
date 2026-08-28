{{--
    Panel del motor de tablas dinámicas (spec 069), compartido por Ventas y Compras.

    Se incluye OCULTO dentro de la pantalla del informe y se muestra al cambiar de pestaña, sin
    recargar: los filtros y el rango del informe son los mismos para el detalle y para el cruce
    (FR-017), así que separar en dos páginas obligaría a re-elegirlos.

    NO hay selector "Mostrar Como": quedó una sola opción (Tabla) por decisión del cliente, y un
    desplegable de un solo elemento es ruido. Ver `resources/js/informes-pivot.js`.

    @param string $informe  'ventas' | 'compras'
--}}
<div class="card d-none" id="panel-pivot">
    <div class="card-body">

        <div class="row g-2 align-items-end mb-3">
            <div class="col-6 col-md-3">
                <label class="form-label mb-1">Dato</label>
                <select id="pivot-dato" class="form-select"></select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1">Accion</label>
                <select id="pivot-accion" class="form-select"></select>
            </div>
            <div class="col-md-6 text-md-end">
                <button type="button" class="btn btn-outline-primary" id="btn-pivot-guardar">
                    <i class="fas fa-save me-1"></i> Guardar Informe
                </button>
                <button type="button" class="btn btn-success" id="btn-pivot-exportar">
                    <i class="fas fa-file-excel me-1"></i> Exportar Excel
                </button>
            </div>
        </div>

        {{-- Mensaje propio en vez de dejar que PivotTable.js dibuje una tabla vacía, que parece
             un error de la app y no un período sin movimientos (SC-007). --}}
        <div class="alert alert-info d-none" id="pivot-vacio">
            No hay movimientos en el período elegido para armar el cruce.
        </div>

        {{-- El cruce se recalcula ante cualquier cambio de filtro o de Dato/Accion, y con
             muchos datos tarda unos segundos entre que se pide el dataset y que PivotTable.js
             termina de dibujar. Sin señal visible la pantalla queda igual y no se sabe si está
             cargando (pedido del cliente, 28/08/2026). Es un overlay y no un modal bloqueante a
             propósito: deja ver atenuado el cruce anterior en vez de tapar la pantalla. --}}
        <div class="position-relative" id="pivot-zona">
            <div class="d-none" id="pivot-cargando">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Calculando…</span>
                </div>
                <p class="text-muted small mb-0 mt-2">Calculando el cruce…</p>
            </div>

            <div class="table-responsive">
                <div id="pivot-contenedor"></div>
            </div>
        </div>

    </div>
</div>

{{-- Modal "Guardar Informe": Bootstrap + AJAX, con el único campo que tiene el de Contagram. --}}
<div class="modal fade" id="modal-guardar-informe" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                {{-- El título dice si se está creando o editando: sin esa señal, guardar cambios
                     sobre un informe abierto parecía estar creando uno nuevo. Lo cambia el JS. --}}
                <h5 class="modal-title" id="modal-guardar-informe-titulo">Guardar Informe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Descripción</label>
                <input type="text" class="form-control" id="pivot-descripcion" maxlength="255"
                       placeholder="Ej.: Ventas por cliente y mes">
                <div class="invalid-feedback" id="pivot-descripcion-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                {{-- Sólo visible sobre un informe ya guardado: duplica en vez de sobrescribir. --}}
                <button type="button" class="btn btn-outline-primary d-none" id="btn-pivot-guardar-como-nuevo">
                    Guardar como nuevo
                </button>
                <button type="button" class="btn btn-primary" id="btn-pivot-guardar-confirmar">Guardar</button>
            </div>
        </div>
    </div>
</div>
