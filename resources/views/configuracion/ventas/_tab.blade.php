<div class="row align-items-center mb-4">
    <div class="col-12">
        <h4 class="mb-0 text-primary fw-bold">Ventas</h4>
        <p class="text-muted mb-0">
            Valores por defecto que se autocompletan al abrir "Crear Venta", "Crear Presupuesto" y "Crear Compra". No afectan comprobantes ya creados.
        </p>
    </div>
</div>

<form id="form-configuracion-ventas">
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0 fw-bold">Ventas</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Categoría por defecto</label>
                    <select class="form-select" id="cv-categoria-id" name="categoria_id" style="width:100%">
                        <option value="">Sin valor por defecto</option>
                        @foreach ($categoriasVenta as $categoria)
                            <option value="{{ $categoria->id }}" {{ $configuracionVentas?->categoria_id == $categoria->id ? 'selected' : '' }}>
                                {{ $categoria->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Vendedor por defecto</label>
                    <select class="form-select" id="cv-vendedor-id" name="vendedor_id" style="width:100%">
                        <option value="">Sin valor por defecto</option>
                        @foreach ($vendedores as $vendedor)
                            <option value="{{ $vendedor->id }}" {{ $configuracionVentas?->vendedor_id == $vendedor->id ? 'selected' : '' }}>
                                {{ $vendedor->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Lista de Precios por defecto</label>
                    <select class="form-select" id="cv-lista-precio-id" name="lista_precio_id" style="width:100%">
                        <option value="">Sin valor por defecto (usa "Principal")</option>
                        @foreach ($listasPrecio as $lista)
                            <option value="{{ $lista->id }}" {{ $configuracionVentas?->lista_precio_id == $lista->id ? 'selected' : '' }}>
                                {{ $lista->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo de Comprobante por defecto</label>
                    <select class="form-select" id="cv-tipo-comprobante" name="tipo_comprobante">
                        <option value="">Sin valor por defecto (usa "B")</option>
                        @foreach (['A', 'B', 'C', 'E'] as $tipo)
                            <option value="{{ $tipo }}" {{ $configuracionVentas?->tipo_comprobante === $tipo ? 'selected' : '' }}>
                                {{ $tipo }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Días por defecto hasta "Vto. del Cobro"</label>
                    <input type="number" min="0" step="1" class="form-control" id="cv-dias-vto-cobro" name="dias_vto_cobro"
                           value="{{ $configuracionVentas?->dias_vto_cobro }}" placeholder="Sin valor por defecto">
                    <div class="form-text">Se suma a la fecha de Emisión. 0 significa la misma fecha que la Emisión.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0 fw-bold">Presupuestos</h6>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Reutiliza la Categoría, el Vendedor y la Lista de Precios por defecto configurados arriba en "Ventas" (mismos catálogos).
            </p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Días por defecto hasta "Vto. de Validez"</label>
                    <input type="number" min="0" step="1" class="form-control" id="cv-dias-validez-presupuesto" name="dias_validez_presupuesto"
                           value="{{ $configuracionVentas?->dias_validez_presupuesto }}" placeholder="Sin valor por defecto">
                    <div class="form-text">Se suma a la fecha de Emisión. 0 significa la misma fecha que la Emisión.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0 fw-bold">Compras</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Categoría de Compra por defecto</label>
                    <select class="form-select" id="cv-categoria-compra-id" name="categoria_compra_id" style="width:100%">
                        <option value="">Sin valor por defecto</option>
                        @foreach ($categoriasCompra as $categoria)
                            <option value="{{ $categoria->id }}" {{ $configuracionVentas?->categoria_compra_id == $categoria->id ? 'selected' : '' }}>
                                {{ $categoria->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipo de Comprobante por defecto</label>
                    <select class="form-select" id="cv-tipo-comprobante-compra" name="tipo_comprobante_compra">
                        <option value="">Sin valor por defecto (usa "B")</option>
                        @foreach (['A', 'B', 'C'] as $tipo)
                            <option value="{{ $tipo }}" {{ $configuracionVentas?->tipo_comprobante_compra === $tipo ? 'selected' : '' }}>
                                {{ $tipo }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Días por defecto hasta "Vto. de Pago"</label>
                    <input type="number" min="0" step="1" class="form-control" id="cv-dias-vto-pago-compra" name="dias_vto_pago_compra"
                           value="{{ $configuracionVentas?->dias_vto_pago_compra }}" placeholder="Sin valor por defecto">
                    <div class="form-text">Se suma a la fecha de Emisión. 0 significa la misma fecha que la Emisión.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end">
        <button type="submit" class="btn btn-primary" id="btn-guardar-configuracion-ventas">Guardar</button>
    </div>
</form>
