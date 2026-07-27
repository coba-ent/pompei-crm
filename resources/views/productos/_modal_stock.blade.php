{{-- Modal de ajuste de stock + histórico de movimientos (AJAX, sin recargar) --}}
<div class="modal fade" id="modal-stock" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="form-stock" novalidate>
                <input type="hidden" id="stock-producto-id" value="">

                <div class="modal-header">
                    <h5 class="modal-title">Ajuste de stock — <span id="stock-producto-nombre"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Depósito</label>
                            <select class="form-select" name="deposito_id" id="stock-deposito">
                                @foreach ($depositos as $deposito)
                                    <option value="{{ $deposito->id }}">{{ $deposito->nombre }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-field="deposito_id"></div>
                        </div>
                        <div class="col-md-4 d-none" id="stock-variante-wrap">
                            <label class="form-label">Variante</label>
                            <select class="form-select" name="variante_id" id="stock-variante"></select>
                            <div class="invalid-feedback" data-field="variante_id"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Operación</label>
                            <select class="form-select" name="operacion" id="stock-operacion">
                                <option value="aumento">Aumento</option>
                                <option value="disminucion">Disminución</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Cantidad</label>
                            <input type="number" step="0.001" min="0" class="form-control" name="cantidad" value="1">
                            <div class="invalid-feedback" data-field="cantidad"></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Descripción / motivo</label>
                            <input type="text" class="form-control" name="descripcion" placeholder="Opcional">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mb-3">
                        <button type="submit" class="btn btn-primary" id="btn-guardar-stock">Registrar ajuste</button>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </form>
        </div>
    </div>
</div>
