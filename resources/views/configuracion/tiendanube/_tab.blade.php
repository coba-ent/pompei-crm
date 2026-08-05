<div class="row align-items-center mb-4">
    <div class="col-12">
        <h4 class="mb-0 text-primary fw-bold">Tiendanube</h4>
        <p class="text-muted mb-0">
            Conectá tu tienda de Tiendanube por OAuth para operar catálogo y ventas desde el CRM.
        </p>
    </div>
</div>

@include('configuracion.tiendanube._panel_estado_rest')

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">Modo sólo lectura</h6>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="tn-modo-solo-lectura">
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted mb-0">
            Bloquea toda escritura hacia Tiendanube, registrándola en el historial en vez de
            ejecutarla. Permite apuntar a la tienda real del negocio sin riesgo mientras se sigue
            desarrollando el resto del módulo.
        </p>
        <div class="alert alert-warning mt-3 mb-0 d-none" id="tn-aviso-solo-lectura">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Modo sólo lectura activo: las escrituras hacia Tiendanube están bloqueadas.
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <h6 class="mb-0 fw-bold">Ventas de Tiendanube</h6>
    </div>
    <div class="card-body">
        <form id="form-ventas-tn">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="tn-creacion-automatica">
                        <label class="form-check-label" for="tn-creacion-automatica">Creación automática de ventas</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Frecuencia de sincronización</label>
                    <select class="form-select" id="tn-frecuencia-sync">
                        <option value="5">Cada 5 minutos</option>
                        <option value="10">Cada 10 minutos</option>
                        <option value="15">Cada 15 minutos</option>
                        <option value="30">Cada 30 minutos</option>
                        <option value="60">Cada hora</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Antigüedad de la ventana de sincronización (días)</label>
                    <input type="number" min="1" max="365" class="form-control" id="tn-dias-primera-sync">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Depósito de descuento de stock</label>
                    <select class="form-select" id="tn-deposito-id" style="width:100%">
                        <option value="">Usar el depósito por defecto{{ $depositoPorDefecto ? ' — '.$depositoPorDefecto->nombre : '' }}</option>
                        @foreach ($depositos as $deposito)
                            <option value="{{ $deposito->id }}">{{ $deposito->nombre }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Se descuenta de <strong id="tn-deposito-efectivo">{{ $depositoEfectivoTn?->nombre ?? '—' }}</strong>.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label d-flex align-items-center gap-1">
                        <span class="flex-grow-1">Categoría de las Ventas</span>
                        <a href="#" id="btn-renombrar-categoria" class="text-primary d-none" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>
                        <a href="#" id="btn-eliminar-categoria" class="text-danger d-none" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                    </label>
                    <select class="form-select" id="tn-categoria-venta-id" style="width:100%"></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label d-flex align-items-center gap-1">
                        <span class="flex-grow-1">Vendedor por defecto</span>
                        <a href="#" id="btn-renombrar-vendedor" class="text-primary d-none" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>
                        <a href="#" id="btn-eliminar-vendedor" class="text-danger d-none" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                    </label>
                    <select class="form-select" id="tn-vendedor-id" style="width:100%"></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cuenta de Tesorería para las cobranzas</label>
                    <select class="form-select" id="tn-cuenta-tesoreria-id" style="width:100%">
                        <option value="">Sin cuenta configurada (bloquea la conversión)</option>
                        @foreach ($cuentasTesoreria as $cuenta)
                            <option value="{{ $cuenta->id }}">{{ $cuenta->nombre }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Tiendanube admite varios medios de pago sin una pasarela única: todas las
                        cobranzas se imputan a esta cuenta, sin importar el medio real de cada orden.
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Lista de Precios que gestiona Tiendanube</label>
                    <select class="form-select" id="tn-lista-precio-id" style="width:100%">
                        <option value="">Sin Lista de Precios (sin sincronización de precios)</option>
                        @foreach ($listasPrecio as $lista)
                            <option value="{{ $lista->id }}">{{ $lista->nombre }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Cuando cambia el precio de un producto vinculado dentro de esta lista, el CRM
                        lo envía de inmediato a la variante de Tiendanube correspondiente.
                    </div>
                </div>
                <div class="col-12">
                    <div class="text-muted small" id="tn-ultima-sync-info"></div>
                    <div class="text-muted small" id="tn-stock-ultima-sync-info"></div>
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary" id="btn-guardar-ventas-tn">Guardar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">Historial de operaciones</h6>
        <span id="dt-buttons-tn-operaciones"></span>
    </div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Desde</label>
                <input type="date" class="form-control form-control-sm" id="tn-historial-desde">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Hasta</label>
                <input type="date" class="form-control form-control-sm" id="tn-historial-hasta">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Resultado</label>
                <select class="form-select form-select-sm" id="tn-historial-resultado">
                    <option value="">Todos</option>
                    <option value="exito">Éxito</option>
                    <option value="error">Error</option>
                    <option value="bloqueada">Bloqueada</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btn-filtrar-historial-tn">Filtrar</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-striped w-100" id="tabla-tn-operaciones">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Operación</th>
                        <th>Sentido</th>
                        <th>Resultado</th>
                        <th>Código</th>
                        <th>Duración</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
