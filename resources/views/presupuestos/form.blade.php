@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-8 mb-2">
                <h4 class="mb-0 text-primary fw-bold">{{ $presupuesto ? 'Editar Presupuesto '.$presupuesto->nro_presupuesto : 'Nuevo Presupuesto' }}</h4>
            </div>
            <div class="col-sm-4 mb-2 text-sm-end">
                <a href="{{ route('presupuestos.index') }}" class="btn btn-light">Cancelar</a>
                <button type="button" class="btn btn-primary" id="btn-guardar-presupuesto">
                    <i class="fas fa-save me-1"></i> Guardar
                </button>
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
                        <input type="date" id="f-fecha-emision" class="form-control" value="{{ old('fecha_emision', optional($presupuesto?->fecha_emision)->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Validez</label>
                        <input type="date" id="f-fecha-validez" class="form-control" value="{{ optional($presupuesto?->fecha_validez)->format('Y-m-d') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Formas de Pago</label>
                        <input type="text" id="f-formas-pago" class="form-control" value="{{ $presupuesto?->formas_pago }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Categoría</label>
                        <select id="f-categoria" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Servicio Desde</label>
                        <input type="date" id="f-servicio-desde" class="form-control" value="{{ optional($presupuesto?->servicio_desde)->format('Y-m-d') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Servicio Hasta</label>
                        <input type="date" id="f-servicio-hasta" class="form-control" value="{{ optional($presupuesto?->servicio_hasta)->format('Y-m-d') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Métodos de Envío</label>
                        <input type="text" id="f-metodos-envio" class="form-control" value="{{ $presupuesto?->metodos_envio }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Vendedor</label>
                        <select id="f-vendedor" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Lista de Precios</label>
                        <select id="f-lista-precio" class="form-select" style="width:100%">
                            <option value="">Principal</option>
                            @foreach ($listasPrecio as $lista)
                                <option value="{{ $lista->id }}">{{ $lista->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <hr>

                <div class="row g-3 align-items-end mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Seleccionar o Crear Producto/Servicio</label>
                        <select id="f-producto" class="form-select" style="width:100%"></select>
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
                                <th>Producto</th>
                                <th>Cant.</th>
                                <th>Precio</th>
                                <th>Desc. %</th>
                                <th>Subtotal</th>
                                <th>IVA</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="items-body"></tbody>
                    </table>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="mb-3">
                            <label class="form-label">Nota para el Cliente</label>
                            <textarea id="f-nota-cliente" class="form-control" rows="2">{{ $presupuesto?->nota_cliente }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nota interna</label>
                            <textarea id="f-nota-interna" class="form-control" rows="2">{{ $presupuesto?->nota_interna }}</textarea>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="bg-light rounded p-3">
                            <div class="mb-3">
                                <label class="form-label">Descuento General (%)</label>
                                <input type="number" id="f-descuento-general" class="form-control" min="0" max="100" step="0.01" value="{{ $presupuesto?->descuento_general_pct }}">
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
                                <tr class="fw-bold"><td>Total Presupuesto</td><td class="text-end" id="tot-total">$ 0,00</td></tr>
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
@endsection

@php
    $datosPresupuesto = $presupuesto ? $presupuesto->only(['id', 'cliente_id', 'categoria_id', 'lista_precio_id', 'vendedor_id', 'descuento_general_pct']) : null;
    $datosItems = $presupuesto ? $presupuesto->items->map(fn ($i) => $i->only(['producto_id', 'descripcion', 'cantidad', 'precio_unitario', 'descuento_pct', 'iva_pct']))->values() : [];
    $datosConceptos = $presupuesto ? $presupuesto->conceptos->map(fn ($c) => $c->only(['tipo', 'concepto', 'monto']))->values() : [];
    $datosEtiquetas = $presupuesto ? $presupuesto->etiquetas->pluck('nombre') : [];
    $datosCliente = $presupuesto?->cliente ? ['id' => $presupuesto->cliente->id, 'nombre' => $presupuesto->cliente->nombre] : null;
    $datosCategoria = $presupuesto?->categoria ? ['id' => $presupuesto->categoria->id, 'nombre' => $presupuesto->categoria->nombre] : null;
    $datosListaPrecio = $presupuesto?->listaPrecio ? ['id' => $presupuesto->listaPrecio->id, 'nombre' => $presupuesto->listaPrecio->nombre] : null;
@endphp
@section('local-js')
<script>
    window.PresupuestoFormData = {
        presupuesto: @json($datosPresupuesto),
        items: @json($datosItems),
        conceptos: @json($datosConceptos),
        etiquetas: @json($datosEtiquetas),
        cliente: @json($datosCliente),
        categoria: @json($datosCategoria),
        listaPrecio: @json($datosListaPrecio),
    };
    window.PresupuestosConfig = {
        submitToken: "{{ $submitToken ?? '' }}",
        rutas: {
            store: "{{ route('presupuestos.store') }}",
            update: @json($presupuesto ? route('presupuestos.update', $presupuesto) : null),
            index: "{{ route('presupuestos.index') }}",
            clientesOpciones: "{{ route('clientes.opciones') }}",
            clientesStore: "{{ route('clientes.store') }}",
            clientesUpdateBase: "{{ url('clientes') }}",
            clientesLocalidades: "{{ route('geo.localidades') }}",
            clientesVerificarDocumento: "{{ route('clientes.verificar-documento') }}",
            productosOpciones: "{{ route('productos.opciones') }}",
            categoriaVentaStore: "{{ route('categorias.venta.store') }}",
            categoriaUpdateBase: "{{ url('categorias') }}",
            vendedorStore: "{{ route('vendedores.store') }}",
            vendedorUpdateBase: "{{ url('vendedores') }}",
        },
        categorias: @json($categoriasVenta->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'es_sistema' => $c->es_sistema])),
        vendedores: @json($vendedores->map(fn ($v) => ['id' => $v->id, 'nombre' => $v->nombre])),
    };
</script>
@vite(['resources/js/cliente-modal.js', 'resources/js/presupuestos.js'])
@endsection
