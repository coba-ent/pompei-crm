<div class="modal fade" id="modal-pago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Pago</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
            </div>
        </div>
    </div>
</div>
