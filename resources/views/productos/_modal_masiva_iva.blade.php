<div class="modal fade" id="modal-masiva-iva" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-masiva-iva">
                <div class="modal-header">
                    <h5 class="modal-title">Edición IVA por Defecto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="fw-bold">IVA que se aplicará por defecto a los productos al momento de Comprar y/o Vender</p>

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">IVA Venta</label>
                            <select class="form-select" name="valor_venta" id="masiva-iva-venta">
                                @foreach (\App\Models\Producto::OPCIONES_IVA as $codigo => $opcion)
                                    <option value="{{ $codigo }}" @selected((string) $codigo === '21')>{{ $opcion['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">IVA Compra</label>
                            <select class="form-select" name="valor_compra" id="masiva-iva-compra">
                                @foreach (\App\Models\Producto::OPCIONES_IVA as $codigo => $opcion)
                                    <option value="{{ $codigo }}" @selected((string) $codigo === '21')>{{ $opcion['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Actualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>
