{{--
    Modal "Nuevo Movimiento Entre Cuentas" (US3): partida doble. Los
    selectores de cuenta muestran el saldo actual (FR-017) vía Select2 ajax.
--}}
<div class="modal fade" id="modal-transferencia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-transferencia">
                <div class="modal-header">
                    <h5 class="modal-title">Nuevo Movimiento Entre Cuentas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Fecha</label>
                        <input type="date" class="form-control" id="transferencia-fecha" name="fecha" required>
                        <div class="invalid-feedback" data-error="fecha"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Monto</label>
                        <input type="number" step="0.01" class="form-control" id="transferencia-monto" name="monto" required>
                        <div class="invalid-feedback" data-error="monto"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Elija cuenta de salida</label>
                        <select class="form-select" id="transferencia-cuenta-salida" name="cuenta_salida_id" required></select>
                        <div class="invalid-feedback" data-error="cuenta_salida_id"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Elija cuenta de entrada</label>
                        <select class="form-select" id="transferencia-cuenta-entrada" name="cuenta_entrada_id" required></select>
                        <div class="invalid-feedback" data-error="cuenta_entrada_id"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observación</label>
                        <textarea class="form-control" id="transferencia-observacion" name="observacion" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear</button>
                </div>
            </form>
        </div>
    </div>
</div>
