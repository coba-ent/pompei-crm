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
                    <div class="mb-3">
                        <label class="form-label">Fecha</label>
                        <input type="date" class="form-control" id="movimiento-editar-fecha" name="fecha" required>
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
