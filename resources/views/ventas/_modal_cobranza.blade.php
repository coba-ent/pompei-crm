<div class="modal fade" id="modal-cobranza" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cobranza-modal-titulo">Cobranza</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cobranza-id" value="">
                <div class="row mb-3 text-center">
                    <div class="col-6">
                        <div class="text-muted">Total Venta</div>
                        <div class="h5" id="cobranza-total">$ 0,00</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted">A Cobrar</div>
                        <div class="h5" id="cobranza-a-cobrar">$ 0,00</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Cobrar</label>
                    <input type="number" step="0.01" class="form-control" id="cobranza-monto">
                </div>
                <div class="mb-3">
                    <label class="form-label">Fecha</label>
                    {{-- dd/mm/aaaa: ver `resources/js/fecha-ar.js`. Viaja ISO al backend. --}}
                    <input type="text" class="form-control" id="cobranza-fecha" data-fecha-ar>
                </div>
                {{-- Vuelto (spec 110). Para el pago con un medio que el CRM no registra tal cual
                     —el caso son dólares—: se cobra el importe convertido y se devuelve la
                     diferencia en el acto. Va SIEMPRE visible y vacío: un campo que apareciera sólo
                     al superar el saldo obligaría a cargar mal el monto primero para verlo.
                     El importe de arriba pasa a ser lo RECIBIDO; a la venta se le imputa el neto. --}}
                <div class="mb-3">
                    <label class="form-label">
                        Vuelto
                        <span class="text-muted small">(opcional — si le devolvés cambio al cliente)</span>
                    </label>
                    <input type="number" step="0.01" min="0" class="form-control" id="cobranza-vuelto" placeholder="0,00">
                </div>
                <div class="mb-3" id="cobranza-vuelto-cuenta-wrap" style="display:none;">
                    <label class="form-label">¿De qué cuenta sale el vuelto?</label>
                    <select class="form-select" id="cobranza-vuelto-cuenta"></select>
                </div>
                <div class="alert alert-info py-2 px-3 mb-3" id="cobranza-neto-aviso" style="display:none;">
                    <div class="d-flex justify-content-between small">
                        <span>Recibís</span><strong id="cobranza-neto-recibido">$ 0,00</strong>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span>Devolvés</span><strong id="cobranza-neto-vuelto">$ 0,00</strong>
                    </div>
                    <div class="d-flex justify-content-between border-top mt-1 pt-1">
                        <span>Se imputa a la venta</span><strong id="cobranza-neto-valor">$ 0,00</strong>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Nota</label>
                    <input type="text" class="form-control" id="cobranza-nota">
                </div>
                <label class="form-label">Medio de Cobro</label>
                <div class="row g-2" id="cobranza-cuentas"></div>

                {{-- Saldo a favor (spec 072). Va en un bloque SEPARADO de las cuentas de tesorería
                     a propósito: no es plata que entra, no tiene cuenta asociada y no genera
                     movimiento de tesorería (FR-019). Sólo aparece si el cliente tiene crédito
                     disponible y la venta tiene saldo pendiente (FR-006) — con un cliente sin
                     crédito el modal se ve exactamente igual que antes. --}}
                <div id="cobranza-credito" class="mt-3 pt-3 border-top" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">Saldo a favor del cliente</label>
                        <span class="badge bg-success-subtle text-success" id="cobranza-credito-total"></span>
                    </div>
                    <button type="button" class="btn btn-outline-success w-100" id="btn-usar-saldo-favor">
                        <i class="fas fa-hand-holding-dollar me-1"></i> Aplicar saldo a favor
                    </button>
                    <div class="small text-muted mt-1" id="cobranza-credito-detalle"></div>
                </div>
            </div>
            <div class="modal-footer" id="cobranza-modal-footer-edicion" style="display:none;">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-cobranza">Guardar</button>
            </div>
        </div>
    </div>
</div>
