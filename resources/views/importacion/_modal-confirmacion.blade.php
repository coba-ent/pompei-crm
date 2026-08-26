{{--
    Paso de revisión previo a escribir (spec 083, FR-001).

    El análisis corre por tandas contra el endpoint `importacion.prevalidar`, que NO escribe nada:
    hasta que el usuario no aprieta "Sí, importar", la base queda exactamente como estaba.

    Regla de diseño #2 del proyecto: modal de Bootstrap + AJAX, sin recargar la página. No es un
    cuarto paso del asistente — el asistente sigue siendo subir → mapear → resumen.
--}}
<div class="modal fade" id="modal-confirmacion-import" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clipboard-check me-1"></i> Revisá antes de importar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" id="btn-cerrar-confirmacion"></button>
            </div>

            <div class="modal-body">

                {{-- Análisis en curso --}}
                <div id="confirmacion-analizando">
                    <p class="text-muted small mb-2" id="confirmacion-analizando-detalle">Analizando el archivo…</p>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" id="confirmacion-analizando-barra" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <p class="text-muted small mt-2 mb-0">Todavía no se escribió nada. Podés cancelar sin consecuencias.</p>
                </div>

                {{-- Resultado del análisis --}}
                <div id="confirmacion-resultado" class="d-none">

                    <div class="row g-2 mb-3" id="confirmacion-conteos">
                        <div class="col-4">
                            <div class="border rounded p-2 text-center">
                                <div class="fs-4 fw-bold text-success" id="confirmacion-altas">0</div>
                                <div class="small text-muted">se van a crear</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 text-center">
                                <div class="fs-4 fw-bold text-primary" id="confirmacion-actualizaciones">0</div>
                                <div class="small text-muted">se van a actualizar</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 text-center">
                                <div class="fs-4 fw-bold text-danger" id="confirmacion-errores">0</div>
                                <div class="small text-muted">con errores</div>
                            </div>
                        </div>
                    </div>

                    {{-- FR-005b: qué campos se tocan y a cuántos registros. Es lo que evita la
                         sorpresa de descubrir DESPUÉS que se pisaron precios o stock. --}}
                    <div id="confirmacion-campos-bloque" class="mb-3 d-none">
                        <h6 class="fw-bold mb-2">Campos que se van a modificar</h6>
                        <div class="table-responsive" style="max-height:200px;overflow-y:auto;">
                            <table class="table table-sm table-bordered mb-0">
                                <thead><tr><th>Campo</th><th class="text-end" style="width:10rem;">Registros</th></tr></thead>
                                <tbody id="confirmacion-campos"></tbody>
                            </table>
                        </div>
                    </div>

                    {{-- FR-005: con una sola fila mala no se importa nada. Es un cambio deliberado
                         respecto de la tolerancia por fila de las specs 006/026. --}}
                    <div id="confirmacion-errores-bloque" class="d-none">
                        <div class="alert alert-danger py-2">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong>No se puede importar.</strong> Corregí las filas de abajo en el archivo y volvé a subirlo.
                            Mientras haya errores no se escribe nada.
                        </div>
                        <div class="border rounded" style="max-height:260px;overflow-y:auto;">
                            <table class="table table-sm mb-0">
                                <thead class="sticky-top bg-body"><tr><th style="width:6rem;">Fila</th><th>Motivo</th></tr></thead>
                                <tbody id="confirmacion-errores-detalle"></tbody>
                            </table>
                        </div>
                    </div>

                    <div id="confirmacion-advertencias-bloque" class="mt-3 d-none">
                        <h6 class="fw-bold text-warning mb-2">Advertencias <span class="text-muted fw-normal small">(no bloquean la importación)</span></h6>
                        <div class="border rounded" style="max-height:180px;overflow-y:auto;">
                            <table class="table table-sm mb-0">
                                <thead class="sticky-top bg-body"><tr><th style="width:6rem;">Fila</th><th>Motivo</th></tr></thead>
                                <tbody id="confirmacion-advertencias-detalle"></tbody>
                            </table>
                        </div>
                    </div>

                    <div id="confirmacion-vacio" class="alert alert-secondary py-2 mb-0 d-none">
                        El archivo no tiene filas de datos para importar.
                    </div>

                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="btn-cancelar-confirmacion">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-definitivo" disabled>
                    <i class="fas fa-check me-1"></i> Sí, importar
                </button>
            </div>
        </div>
    </div>
</div>
