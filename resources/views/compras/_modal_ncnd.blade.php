<div class="modal fade" id="modal-ncnd" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ncnd-titulo">Crear NC/ND</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                {{-- Paso 1 --}}
                <div id="ncnd-paso-1">
                    <div class="mb-3">
                        <label class="form-label">Seleccionar Tipo</label>
                        <select id="ncnd-tipo" class="form-select" style="width:100%">
                            <option value="credito">Nota de Crédito</option>
                            <option value="debito">Nota de Débito</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Documento que Ajusta</label>
                        <select id="ncnd-documento" class="form-select" style="width:100%" disabled></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">¿Querés que afecte Stock?</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="ncnd-afecta-stock" id="ncnd-afecta-si" value="1">
                            <label class="form-check-label" for="ncnd-afecta-si">Sí</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="ncnd-afecta-stock" id="ncnd-afecta-no" value="0" checked>
                            <label class="form-check-label" for="ncnd-afecta-no">No</label>
                        </div>
                        <div class="form-text text-warning d-none" id="ncnd-sin-productos">
                            Este comprobante no tiene productos (sólo conceptos/servicios) — no se puede afectar stock.
                        </div>
                    </div>
                    <div id="ncnd-stock-bloque" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Depósito</label>
                            <select id="ncnd-deposito" class="form-select" style="width:100%"></select>
                        </div>
                        <label class="form-label">Agregar Productos de la Compra</label>
                        <div id="ncnd-items"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mes de Imputación</label>
                        <input type="month" class="form-control" id="ncnd-mes-imputacion">
                    </div>
                </div>

                {{-- Paso 2 --}}
                <div id="ncnd-paso-2" class="d-none">
                    <div class="row g-2">
                        <div class="col-6 mb-3">
                            <label class="form-label">Tipo Comprobante</label>
                            <input type="text" class="form-control" id="ncnd-tipo-comprobante" placeholder="A/B/C">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">N° Comprobante</label>
                            <input type="text" class="form-control" id="ncnd-nro-comprobante" placeholder="0001-00000123">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fecha</label>
                        <input type="date" class="form-control" id="ncnd-fecha">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Monto</label>
                        <input type="text" inputmode="decimal" class="form-control" id="ncnd-monto">
                    </div>
                    <div class="mb-3" id="ncnd-descripcion-wrapper">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" id="ncnd-descripcion" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger me-auto d-none" id="btn-ncnd-eliminar">Eliminar</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-outline-secondary d-none" id="btn-ncnd-volver">Volver</button>
                <button type="button" class="btn btn-primary" id="btn-ncnd-siguiente">Siguiente</button>
                <button type="button" class="btn btn-primary d-none" id="btn-ncnd-guardar">Guardar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-eliminar-nota" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar Nota</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Seguro que querés eliminar esta Nota de Crédito/Débito? Si afecta stock, se revierte
                el movimiento. Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-nota">Eliminar</button>
            </div>
        </div>
    </div>
</div>
