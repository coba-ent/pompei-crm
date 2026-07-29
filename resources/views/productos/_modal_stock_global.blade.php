{{-- Modal global de operaciones de stock: Aumento / Disminución / Movimiento
     entre Depósitos (lanzado desde el dropdown "Ajuste de Stock"). AJAX, sin recargar. --}}
<div class="modal fade" id="modal-stock-op" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-stock-op" novalidate>
                <input type="hidden" id="stock-op-tipo" value="aumento">

                <div class="modal-header">
                    <h5 class="modal-title" id="modal-stock-op-titulo">Nuevo Aumento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Fecha</label>
                            <input type="date" class="form-control" name="fecha" value="{{ now()->toDateString() }}">
                            <div class="invalid-feedback" data-field="fecha"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cantidad</label>
                            <input type="number" step="0.001" min="0" class="form-control" name="cantidad" placeholder="Cantidad">
                            <div class="invalid-feedback" data-field="cantidad"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Producto</label>
                            <select class="form-select" name="producto_id" id="stock-op-producto"></select>
                            <div class="invalid-feedback d-block" data-field="producto_id"></div>
                        </div>

                        <div class="col-md-6 d-none" id="stock-op-variante-wrap">
                            <label class="form-label">Variante</label>
                            <select class="form-select" name="variante_id" id="stock-op-variante"></select>
                            <div class="invalid-feedback" data-field="variante_id"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" id="stock-op-deposito-label">Depósito</label>
                            <select class="form-select" name="deposito_id" id="stock-op-deposito">
                                @include('elements._options_depositos', ['depositos' => $depositos])
                            </select>
                            <div class="invalid-feedback" data-field="deposito_id"></div>
                            <div class="invalid-feedback" data-field="deposito_salida_id"></div>
                        </div>

                        <div class="col-md-6 d-none" id="stock-op-entrada-wrap">
                            <label class="form-label">Depósito de Entrada</label>
                            <select class="form-select" name="deposito_entrada_id" id="stock-op-entrada">
                                @include('elements._options_depositos', ['depositos' => $depositos])
                            </select>
                            <div class="invalid-feedback" data-field="deposito_entrada_id"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" id="stock-op-nota-label">Nota interna</label>
                            <textarea class="form-control" name="descripcion" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-stock-op">Crear</button>
                </div>
            </form>
        </div>
    </div>
</div>
