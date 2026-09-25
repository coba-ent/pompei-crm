{{-- Edición de un movimiento nativo (Saldo Inicial / Movimiento entre Cuenta) — FR-024. --}}
<div class="modal fade" id="modal-movimiento-editar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-movimiento-editar">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Movimiento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="movimiento-editar-id" name="id">

                    {{-- Caja (spec 111). En un movimiento suelto va un solo selector; en una
                         transferencia van dos —"Sale de" y "Entra a"—, porque origen y destino son
                         datos distintos y nunca pueden terminar siendo la misma caja. El JS muestra
                         el bloque que corresponda según el movimiento que se abrió. --}}
                    <div class="mb-3" id="movimiento-editar-caja-simple">
                        <label class="form-label">Caja</label>
                        <select class="form-select" id="movimiento-editar-cuenta" name="cuenta_tesoreria_id"></select>
                        <div class="invalid-feedback" data-error="cuenta_tesoreria_id"></div>
                    </div>

                    <div id="movimiento-editar-caja-transferencia" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Sale de</label>
                            <select class="form-select" id="movimiento-editar-cuenta-origen"></select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Entra a</label>
                            <select class="form-select" id="movimiento-editar-cuenta-destino"></select>
                            <div class="invalid-feedback d-block" data-error="cuenta_contraparte_id"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Fecha</label>
                        <input type="text" class="form-control" id="movimiento-editar-fecha" name="fecha" required data-fecha-ar>
                        <div class="invalid-feedback" data-error="fecha"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Monto</label>
                        <input type="number" step="0.01" class="form-control" id="movimiento-editar-monto" name="monto" required>
                        <div class="invalid-feedback" data-error="monto"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observación</label>
                        <textarea class="form-control" id="movimiento-editar-observacion" name="observacion" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
