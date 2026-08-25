<div class="modal fade" id="modal-pago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pago-modal-titulo">Pago</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="pago-id">
                <div class="row mb-3 text-center">
                    <div class="col-6">
                        <div class="text-muted">Total Compra</div>
                        <div class="h5" id="pago-total">$ 0,00</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted">A Pagar</div>
                        <div class="h5" id="pago-a-pagar">$ 0,00</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Monto</label>
                    <input type="number" step="0.01" class="form-control" id="pago-monto">
                </div>
                <div class="mb-3">
                    <label class="form-label">Fecha</label>
                    {{-- dd/mm/aaaa: ver `resources/js/fecha-ar.js`. Viaja ISO al backend. --}}
                    <input type="text" class="form-control" id="pago-fecha" data-fecha-ar>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nota</label>
                    <textarea class="form-control" id="pago-nota" rows="2"></textarea>
                </div>
                <label class="form-label">Elija Medio de Pago</label>
                <div class="row g-2" id="pago-cuentas"></div>

                {{-- Saldo a favor del proveedor (spec 072, US4). Bloque separado de las cuentas de
                     tesorería: no es plata que sale y no genera movimiento de tesorería (FR-019).
                     Sólo aparece si hay crédito disponible y la compra tiene saldo (FR-006). --}}
                <div id="pago-credito" class="mt-3 pt-3 border-top" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">Saldo a favor con el proveedor</label>
                        <span class="badge bg-success-subtle text-success" id="pago-credito-total"></span>
                    </div>
                    <button type="button" class="btn btn-outline-success w-100" id="btn-usar-saldo-favor-compra">
                        <i class="fas fa-hand-holding-dollar me-1"></i> Aplicar saldo a favor
                    </button>
                    <div class="small text-muted mt-1" id="pago-credito-detalle"></div>
                </div>
            </div>
            <div class="modal-footer" id="pago-modal-footer-alta">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
            </div>
            {{-- En alta el medio de pago se elige y paga de una; editando hay que poder cambiar
                 monto/fecha/nota/medio y recién ahí guardar (mismo criterio que Cobranza). --}}
            <div class="modal-footer" id="pago-modal-footer-edicion" style="display:none;">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-pago">Guardar</button>
            </div>
        </div>
    </div>
</div>
