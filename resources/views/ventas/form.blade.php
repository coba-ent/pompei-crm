@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-8 mb-2">
                <h4 class="mb-0 text-primary fw-bold">{{ $venta ? 'Editar Venta '.$venta->nro_comprobante : 'Nueva Venta' }}</h4>
            </div>
            <div class="col-sm-4 mb-2 text-sm-end">
                <a href="{{ route('ventas.index') }}" class="btn btn-light">Cancelar</a>
                <button type="button" class="btn btn-outline-primary" id="btn-guardar-venta">
                    <i class="fas fa-save me-1"></i> Guardar
                </button>
                @unless ($venta)
                    <button type="button" class="btn btn-success" id="btn-cobrar-venta">
                        <i class="fas fa-dollar-sign me-1"></i> Cobrar
                    </button>
                @endunless
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Cliente</label>
                        <select id="f-cliente" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Emisión</label>
                        {{-- Texto + `data-fecha-ar` y NO `type=date`: el nativo se dibuja con el locale
                             del navegador y mostraba 08/05/2026 para el 5 de agosto. El valor que viaja
                             al backend sigue siendo ISO, vía `AppFecha.get()`. Ver `resources/js/fecha-ar.js`. --}}
                        <input type="text" id="f-fecha-emision" class="form-control" data-fecha-ar
                               data-fecha="{{ old('fecha_emision', now()->local()->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Vto. del Cobro <i class="fas fa-question-circle text-info" data-bs-toggle="tooltip" title="Fecha estimada de cobro"></i></label>
                        <input type="text" id="f-fecha-vto-cobro" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tipo de Comprobante</label>
                        <select id="f-tipo-comprobante" class="form-select">
                            <option value="A">A</option>
                            <option value="B" selected>B</option>
                            <option value="C">C</option>
                            <option value="E">E</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label d-flex align-items-center gap-1">
                            <span class="flex-grow-1">Categoría</span>
                            <a href="#" id="btn-renombrar-categoria" class="text-primary d-none" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>
                            <a href="#" id="btn-eliminar-categoria" class="text-danger d-none" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                        </label>
                        <select id="f-categoria" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Servicio Desde</label>
                        <input type="text" id="f-servicio-desde" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Servicio Hasta</label>
                        <input type="text" id="f-servicio-hasta" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Lista de Precios</label>
                        <select id="f-lista-precio" class="form-select" style="width:100%">
                            <option value="">Principal</option>
                            @foreach ($listasPrecio as $lista)
                                <option value="{{ $lista->id }}">{{ $lista->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label d-flex align-items-center gap-1">
                            <span class="flex-grow-1">Vendedor</span>
                            <a href="#" id="btn-renombrar-vendedor" class="text-primary d-none" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>
                            <a href="#" id="btn-eliminar-vendedor" class="text-danger d-none" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                        </label>
                        <select id="f-vendedor" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Depósito</label>
                        <select id="f-deposito" class="form-select" style="width:100%">
                            {{-- Opción vacía a propósito: si el comprobante no tiene depósito guardado
                                 —como las ventas de integraciones anteriores al fix—, el selector debe
                                 quedar en blanco y obligar a elegir, en vez de mostrar el primero de la
                                 lista como si fuera el real. --}}
                            <option value=""></option>
                            @foreach ($depositos ?? [] as $deposito)
                                <option value="{{ $deposito->id }}">{{ $deposito->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <hr>

                <div class="row g-3 align-items-end mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Seleccionar o Crear Producto/Servicio</label>
                        <input type="text" id="f-producto" class="form-control" autocomplete="off" placeholder="Buscar producto...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Etiquetas</label>
                        <select id="f-etiquetas" class="form-select" multiple="multiple" style="width:100%"></select>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle" id="tabla-items">
                        <thead>
                            <tr>
                                <th>Producto</th><th>Cant.</th><th>Precio</th><th>Desc. %</th>
                                <th>Subtotal</th><th>IVA</th><th>Total</th><th></th>
                            </tr>
                        </thead>
                        <tbody id="items-body"></tbody>
                    </table>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="mb-3">
                            <label class="form-label">Nota para el Cliente</label>
                            <textarea id="f-nota-cliente" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nota interna</label>
                            <textarea id="f-nota-interna" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Formas de Pago</label>
                                <input type="text" id="f-formas-pago" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Métodos de Envío</label>
                                <input type="text" id="f-metodos-envio" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="bg-light rounded p-3">
                            <div class="mb-3">
                                <label class="form-label" id="f-descuento-general-label">Descuento General (%)</label>
                                <div class="input-group">
                                    <input type="number" id="f-descuento-general" class="form-control" min="0" max="100" step="0.01">
                                    <button type="button" id="f-descuento-general-toggle" class="btn btn-outline-secondary" data-modo="porcentaje">%</button>
                                </div>
                            </div>

                            <div id="conceptos-body" class="mb-2"></div>
                            <div class="d-flex gap-3 mb-3">
                                <a href="#" class="js-add-concepto" data-tipo="percepcion">+ Percepciones</a>
                                <a href="#" class="js-add-concepto" data-tipo="impuesto_interno">+ Impuestos Internos</a>
                                <a href="#" class="js-add-concepto" data-tipo="interes">+ Intereses</a>
                            </div>

                            <table class="table table-sm mb-0">
                                <tr><td>Subtotal sin Descuento</td><td class="text-end" id="tot-subtotal-sin-descuento">$ 0,00</td></tr>
                                <tr><td>Descuento</td><td class="text-end" id="tot-descuento">$ 0,00</td></tr>
                                <tr><td>Subtotal con Descuento</td><td class="text-end" id="tot-subtotal-con-descuento">$ 0,00</td></tr>
                                <tr class="fw-bold"><td>Total Venta</td><td class="text-end" id="tot-total">$ 0,00</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@include('presupuestos._modal_categoria')
@include('presupuestos._modal_vendedor')
@include('clientes._modal_form')
{{-- Ver/Editar de producto desde el detalle (spec 052): reutiliza los modales de Productos. --}}
@include('productos._modal_form')
@include('productos._modal_ver')
@include('productos._modal_listas')
@include('productos._modal_tipos')
@endsection

@php
    $clienteOrigen = $venta?->cliente ?? $presupuestoOrigen?->cliente;
    $datosVenta = $venta ? $venta->only(['id', 'cliente_id', 'categoria_id', 'lista_precio_id', 'vendedor_id', 'deposito_id', 'descuento_general_pct', 'descuento_general_tipo', 'descuento_general_monto', 'tipo_comprobante', 'nota_cliente', 'nota_interna', 'formas_pago', 'metodos_envio']) : null;
    $datosItems = ($venta?->items ?? $presupuestoOrigen?->items ?? collect())->map(fn ($i) => $i->only(['producto_id', 'descripcion', 'cantidad', 'precio_unitario', 'descuento_pct', 'iva_pct']))->values();
    $datosConceptos = ($venta?->conceptos ?? $presupuestoOrigen?->conceptos ?? collect())->map(fn ($c) => $c->only(['tipo', 'concepto', 'monto']))->values();
    $datosEtiquetas = ($venta?->etiquetas ?? $presupuestoOrigen?->etiquetas ?? collect())->pluck('nombre');
    $datosCliente = $clienteOrigen ? ['id' => $clienteOrigen->id, 'nombre' => $clienteOrigen->nombre] : null;
@endphp
@section('local-js')
<script>
    window.VentaFormData = {
        venta: @json($datosVenta),
        items: @json($datosItems),
        conceptos: @json($datosConceptos),
        etiquetas: @json($datosEtiquetas),
        cliente: @json($datosCliente),
        presupuestoId: @json($presupuestoOrigen?->id),
        descuentoGeneralPct: @json($venta?->descuento_general_pct ?? $presupuestoOrigen?->descuento_general_pct),
        descuentoGeneralTipo: @json($venta?->descuento_general_tipo ?? $presupuestoOrigen?->descuento_general_tipo ?? 'porcentaje'),
        descuentoGeneralMonto: @json($venta?->descuento_general_monto ?? $presupuestoOrigen?->descuento_general_monto),
        categoriaId: @json($venta?->categoria_id ?? $presupuestoOrigen?->categoria_id),
        listaPrecioId: @json($venta?->lista_precio_id ?? $presupuestoOrigen?->lista_precio_id),
        vendedorId: @json($venta?->vendedor_id ?? $presupuestoOrigen?->vendedor_id),
        depositoId: @json($venta?->deposito_id),
        {{-- En edición hay que devolver la fecha que la venta YA tiene: el input arranca en hoy
             (es lo correcto para un alta), así que sin esto el submit la pisaba con la de hoy. --}}
        fechaEmision: @json(optional($venta?->fecha_emision)->format('Y-m-d')),
        fechaVtoCobro: @json(optional($venta?->fecha_vto_cobro ?? $presupuestoOrigen?->fecha_vto_cobro)->format('Y-m-d')),
        servicioDesde: @json(optional($venta?->servicio_desde ?? $presupuestoOrigen?->servicio_desde)->format('Y-m-d')),
        servicioHasta: @json(optional($venta?->servicio_hasta ?? $presupuestoOrigen?->servicio_hasta)->format('Y-m-d')),
        notaCliente: @json($venta?->nota_cliente ?? $presupuestoOrigen?->nota_cliente),
        notaInterna: @json($venta?->nota_interna ?? $presupuestoOrigen?->nota_interna),
        formasPago: @json($venta?->formas_pago ?? $presupuestoOrigen?->formas_pago),
        metodosEnvio: @json($venta?->metodos_envio ?? $presupuestoOrigen?->metodos_envio),
        defaults: @json($defaults ?? null),
    };
    window.VentasConfig = {
        submitToken: "{{ $submitToken ?? '' }}",
        rutas: {
            store: "{{ route('ventas.store') }}",
            update: @json($venta ? route('ventas.update', $venta) : null),
            index: "{{ route('ventas.index') }}",
            clientesOpciones: "{{ route('clientes.opciones') }}",
            clientesStore: "{{ route('clientes.store') }}",
            clientesUpdateBase: "{{ url('clientes') }}",
            clientesLocalidades: "{{ route('geo.localidades') }}",
            clientesVerificarDocumento: "{{ route('clientes.verificar-documento') }}",
            productosOpciones: "{{ route('productos.opciones') }}",
            categoriaVentaStore: "{{ route('categorias.venta.store') }}",
            categoriaUpdateBase: "{{ url('categorias') }}",
            categoriaDestroyBase: "{{ url('categorias') }}",
            vendedorStore: "{{ route('vendedores.store') }}",
            vendedorUpdateBase: "{{ url('vendedores') }}",
            vendedorDestroyBase: "{{ url('vendedores') }}",
        },
        categorias: @json($categoriasVenta->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'es_sistema' => $c->es_sistema])),
        vendedores: @json($vendedores->map(fn ($v) => ['id' => $v->id, 'nombre' => $v->nombre])),
    };
    {{-- Ver/Editar de producto desde el detalle (spec 052): mismos modales/config que Productos. --}}
    window.ProductosConfig = {
        rutas: {
            store: "{{ route('productos.store') }}",
            show: "{{ url('productos') }}",
            listas: "{{ url('listas-precio') }}",
            tipos: "{{ url('tipos-producto') }}",
        },
        listasPrecio: @json($listasPrecio->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre])),
        tiposProducto: @json($tiposProducto->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre])),
        proveedores: @json($proveedores->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])),
    };
</script>
@vite(['resources/js/cliente-modal.js', 'resources/js/producto-modales.js', 'resources/js/ventas.js'])
@endsection
