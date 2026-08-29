{{--
    Spec 093, US1 — informe de qué cambió desde una corrida de import.

    La advertencia de método NO está escrita acá: viaja en la respuesta del endpoint
    (`advertencia_metodo`) y se pinta desde el JS. Es una limitación real del dato y tiene que
    llegar junto con él, no depender de que alguien la deje puesta en el HTML.
--}}
<div class="modal fade" id="modal-informe-cambios" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Qué cambió desde esta importación</h5>
                    <small class="text-muted" id="informe-cambios-subtitulo"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div id="informe-cambios-cargando" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-3 mb-0">Armando el informe…</p>
                </div>

                <div id="informe-cambios-contenido" class="d-none">
                    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
                        <i class="fas fa-triangle-exclamation mt-1"></i>
                        <span id="informe-cambios-advertencia"></span>
                    </div>

                    <div id="informe-cambios-deshecha" class="alert alert-secondary d-none" role="alert"></div>

                    {{-- Resumen --}}
                    <div class="row g-3 mb-4" id="informe-cambios-resumen"></div>

                    {{-- Campos --}}
                    <h6 class="fw-bold">Campos del producto</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Campo</th>
                                    <th class="text-end">Productos</th>
                                    <th>Ejemplo</th>
                                </tr>
                            </thead>
                            <tbody id="informe-cambios-campos"></tbody>
                        </table>
                    </div>

                    {{-- Precios por lista (FR-002: por lista, no agregado) --}}
                    <h6 class="fw-bold">Precios, por lista</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Lista</th>
                                    <th class="text-end">Productos</th>
                                    <th>Ejemplo</th>
                                    <th class="text-end">Variación</th>
                                </tr>
                            </thead>
                            <tbody id="informe-cambios-precios"></tbody>
                        </table>
                    </div>

                    {{-- Stock, ordenado por magnitud (FR-004) --}}
                    <h6 class="fw-bold">Stock <small class="text-muted fw-normal">— ordenado por magnitud del cambio</small></h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Producto</th>
                                    <th>Depósito</th>
                                    <th class="text-end">Antes</th>
                                    <th class="text-end">Ahora</th>
                                    <th class="text-end">Diferencia</th>
                                </tr>
                            </thead>
                            <tbody id="informe-cambios-stock"></tbody>
                        </table>
                    </div>
                </div>

                {{-- FR-007: "sin detalle disponible" NO es "sin cambios". --}}
                <div id="informe-cambios-sin-detalle" class="alert alert-info d-none mb-0" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
