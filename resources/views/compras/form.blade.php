@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-8 mb-2">
                <h4 class="mb-0 text-primary fw-bold">{{ $compra ? 'Editar Compra '.$compra->nro_comprobante : 'Nueva Compra' }}</h4>
            </div>
            <div class="col-sm-4 mb-2 text-sm-end">
                <a href="{{ route('compras.index') }}" class="btn btn-light">Cancelar</a>
                <button type="button" class="btn btn-outline-primary" id="btn-guardar-compra">
                    <i class="fas fa-save me-1"></i> Guardar
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Proveedor</label>
                        <select id="f-proveedor" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label d-flex align-items-center gap-1">
                            <span class="flex-grow-1">Categoría</span>
                            <a href="#" id="btn-renombrar-categoria" class="text-primary d-none" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>
                            <a href="#" id="btn-eliminar-categoria" class="text-danger d-none" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                        </label>
                        <select id="f-categoria" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tipo de Comprobante</label>
                        <select id="f-tipo-comprobante" class="form-select">
                            <option value="A">A</option>
                            <option value="B" selected>B</option>
                            <option value="C">C</option>
                            <option value="S">Sin Factura</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Contador <i class="fas fa-question-circle text-info" data-bs-toggle="tooltip" title="Mes de imputación de IVA Compras, independiente de la Emisión"></i></label>
                        <input type="month" id="f-mes-imputacion-iva" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Emisión</label>
                        {{-- En edición tiene que arrancar en la fecha que la compra YA tiene; `now()`
                             es sólo el default del alta. Sin esto el submit la pisaba con la de hoy. --}}
                        {{-- Texto + `data-fecha-ar` y NO `type=date`: el nativo se dibuja con el locale
                             del navegador y mostraba 08/05/2026 para el 5 de agosto. El valor que viaja
                             al backend sigue siendo ISO, vía `AppFecha.get()`. Ver `resources/js/fecha-ar.js`. --}}
                        <input type="text" id="f-fecha-emision" class="form-control" data-fecha-ar
                               data-fecha="{{ old('fecha_emision', optional($compra?->fecha_emision)->format('Y-m-d') ?? now()->local()->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Vto. del Pago</label>
                        <input type="text" id="f-fecha-vto-pago" class="form-control" data-fecha-ar>
                    </div>
                    {{-- El modelo y el listado ya tenían Servicio Desde/Hasta (se filtra por ellos y
                         `StoreCompraRequest` los valida), pero el formulario nunca los mostró: no había
                         forma de cargarlos salvo importándolos. En Contagram están acá, junto a Emisión
                         y Vto. del Pago (docs/informe_contagram_egresos.md). --}}
                    <div class="col-md-3">
                        <label class="form-label">Servicio Desde</label>
                        <input type="text" id="f-servicio-desde" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Servicio Hasta</label>
                        <input type="text" id="f-servicio-hasta" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Depósito</label>
                        <select id="f-deposito" class="form-select" style="width:100%">
                            {{-- Ver el comentario equivalente en ventas/form.blade.php: sin la opción
                                 vacía, un comprobante sin depósito muestra el primero como si fuera suyo. --}}
                            <option value=""></option>
                            @foreach ($depositos ?? [] as $deposito)
                                <option value="{{ $deposito->id }}">{{ $deposito->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">N° de Comprobante</label>
                        <input type="text" id="f-nro-comprobante" class="form-control" maxlength="20">
                    </div>
                </div>

                <hr>

                <div class="row g-3 align-items-end mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Seleccionar o Crear Producto/Servicio</label>
                        <input type="text" id="f-producto" class="form-control" autocomplete="off" placeholder="Buscar producto...">
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle" id="tabla-items">
                        <thead>
                            <tr>
                                <th>Producto</th><th>Cant.</th><th>Costo</th><th>Desc. %</th>
                                <th>Subtotal</th><th>IVA</th><th>Total</th><th></th>
                            </tr>
                        </thead>
                        <tbody id="items-body"></tbody>
                    </table>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="mb-3">
                            <label class="form-label">Nota interna</label>
                            <textarea id="f-nota-interna" class="form-control" rows="2"></textarea>
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

                            <table class="table table-sm mb-0" id="tabla-totales">
                                <tr><td id="lbl-importe-neto">Importe Neto No Gravado</td><td class="text-end" id="tot-subtotal-sin-descuento">$ 0,00</td></tr>
                                <tr><td>Descuento</td><td class="text-end" id="tot-descuento">$ 0,00</td></tr>
                                <tr><td>Subtotal con Descuento</td><td class="text-end" id="tot-subtotal-con-descuento">$ 0,00</td></tr>
                                <tr class="fw-bold"><td>Total Compra</td><td class="text-end" id="tot-total">$ 0,00</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@include('compras._modal_categoria')
{{-- Ver/Editar de producto desde el detalle (spec 052): reutiliza los modales de Productos. --}}
@include('productos._modal_form')
@include('productos._modal_ver')
@include('productos._modal_listas')
@include('productos._modal_tipos')
@endsection

@php
    $datosCompra = $compra ? $compra->only(['id', 'proveedor_id', 'categoria_id', 'deposito_id', 'nro_comprobante', 'tipo_comprobante', 'nota_interna', 'fecha_vto_pago', 'mes_imputacion_iva', 'descuento_general_pct', 'descuento_general_tipo', 'descuento_general_monto']) : null;
    $datosItems = ($compra?->items ?? collect())->map(fn ($i) => $i->only(['producto_id', 'descripcion', 'cantidad', 'precio_unitario', 'descuento_pct', 'iva_pct']))->values();
    $datosConceptos = ($compra?->conceptos ?? collect())->map(fn ($c) => $c->only(['tipo', 'concepto', 'monto']))->values();
    $datosProveedor = $compra?->proveedor ? ['id' => $compra->proveedor->id, 'nombre' => $compra->proveedor->nombre] : null;
@endphp
@section('local-js')
<script>
    window.CompraFormData = {
        compra: @json($datosCompra),
        items: @json($datosItems),
        conceptos: @json($datosConceptos),
        proveedor: @json($datosProveedor),
        categoriaId: @json($compra?->categoria_id ?? (($defaults ?? null)['categoriaId'] ?? null)),
        depositoId: @json($compra?->deposito_id ?? (($defaults ?? null)['depositoId'] ?? null)),
        nroComprobante: @json($compra?->nro_comprobante ?? (($defaults ?? null)['nroComprobanteSugerido'] ?? null)),
        notaInterna: @json($compra?->nota_interna),
        fechaVtoPago: @json(optional($compra?->fecha_vto_pago)->format('Y-m-d') ?: (($defaults ?? null)['fechaVtoPago'] ?? null)),
        servicioDesde: @json(optional($compra?->servicio_desde)->format('Y-m-d')),
        servicioHasta: @json(optional($compra?->servicio_hasta)->format('Y-m-d')),
        mesImputacionIva: @json(optional($compra?->mes_imputacion_iva)->format('Y-m')),
        tipoComprobanteDefault: @json($compra ? null : (($defaults ?? null)['tipoComprobante'] ?? null)),
        descuentoGeneralPct: @json($compra?->descuento_general_pct),
        descuentoGeneralTipo: @json($compra?->descuento_general_tipo ?? 'porcentaje'),
        descuentoGeneralMonto: @json($compra?->descuento_general_monto),
    };
    window.ComprasConfig = {
        submitToken: "{{ $submitToken ?? '' }}",
        rutas: {
            store: "{{ route('compras.store') }}",
            update: @json($compra ? route('compras.update', $compra) : null),
            index: "{{ route('compras.index') }}",
            proveedoresOpciones: "{{ route('proveedores.opciones') }}",
            productosOpciones: "{{ route('productos.opciones') }}",
            categoriaCompraStore: "{{ route('categorias.compra.store') }}",
            categoriaUpdateBase: "{{ url('categorias') }}",
            categoriaDestroyBase: "{{ url('categorias') }}",
        },
        categorias: @json($categoriasCompra->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'es_sistema' => $c->es_sistema])),
    };
    {{-- Ver/Editar de producto desde el detalle (spec 052): mismos modales/config que Productos. --}}
    window.ProductosConfig = {
        rutas: {
            store: "{{ route('productos.store') }}",
            show: "{{ url('productos') }}",
            listas: "{{ url('listas-precio') }}",
            tipos: "{{ url('tipos-producto') }}",
        },
        listasPrecio: @json($listasPrecioProductos->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre])),
        tiposProducto: @json($tiposProducto->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre])),
        proveedores: @json($proveedores->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])),
    };
</script>
@vite(['resources/js/producto-modales.js', 'resources/js/compras.js'])
@endsection
