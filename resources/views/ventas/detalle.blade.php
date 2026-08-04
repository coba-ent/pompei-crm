@extends('layouts.default')

@php
    $cobrado = $venta->cobrado();
    $totalNc = $venta->totalNotasCredito();
    $totalNd = $venta->totalNotasDebito();
    $aCobrar = $venta->aCobrar();
    $ivaTotal = $venta->items->sum(fn ($i) => (float) $i->subtotal_con_iva - (float) $i->subtotal);
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">
        <div class="row align-items-center mb-3">
            <div class="col-sm-6">
                <a href="{{ route('ventas.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Volver
                </a>
                @if ($venta->origen === 'mercadolibre' && $venta->mlOrden && \Illuminate\Support\Facades\Route::has('ingresos.mercadolibre.index'))
                    <span class="badge bg-warning text-dark ms-2">
                        <i class="fas fa-store me-1"></i> Origen: Mercado Libre — orden {{ $venta->mlOrden->ml_order_id }}
                    </span>
                @endif
            </div>
            <div class="col-sm-6 text-sm-end">
                <button type="button" class="btn btn-warning" id="btn-crear-remito">
                    <i class="fas fa-truck me-1"></i> Crear Remito
                </button>
            </div>
        </div>

        {{-- Barra de ecuación (informe §3.8) --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="row text-center align-items-center">
                    <div class="col">
                        <div class="text-muted">Total Venta</div>
                        <div class="h5">$ {{ number_format((float) $venta->total, 2, ',', '.') }}</div>
                    </div>
                    <div class="col-auto">+</div>
                    <div class="col">
                        <div class="text-muted">ND</div>
                        <div class="h5">$ {{ number_format($totalNd, 2, ',', '.') }}</div>
                    </div>
                    <div class="col-auto">−</div>
                    <div class="col">
                        <div class="text-muted">NC</div>
                        <div class="h5">$ {{ number_format($totalNc, 2, ',', '.') }}</div>
                    </div>
                    <div class="col-auto">−</div>
                    <div class="col">
                        <div class="text-muted">Cobrado</div>
                        <div class="h5" id="detalle-cobrado">$ {{ number_format($cobrado, 2, ',', '.') }}</div>
                    </div>
                    <div class="col-auto">=</div>
                    <div class="col">
                        <div class="text-muted">A Cobrar</div>
                        <div class="h5 fw-bold" id="detalle-a-cobrar">$ {{ number_format($aCobrar, 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Cobranzas --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Cobranzas</h5>
                    <button type="button" class="btn btn-sm btn-success" id="btn-agregar-cobranza">
                        <i class="fas fa-plus me-1"></i> Agregar Cobranza
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm" id="tabla-cobranzas">
                        <thead>
                            <tr><th>Id</th><th>Fecha</th><th>Medio de cobro</th><th>Nota</th><th>Total</th><th>Comprobante</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($venta->cobros as $cobro)
                                <tr data-cobro-id="{{ $cobro->id }}">
                                    <td>{{ $cobro->id }}</td>
                                    <td>{{ $cobro->fecha->format('d/m/Y') }}</td>
                                    <td>{{ optional($cobro->cuentaTesoreria)->nombre }}</td>
                                    <td>{{ $cobro->nota }}</td>
                                    <td>$ {{ number_format((float) $cobro->monto, 2, ',', '.') }}</td>
                                    <td>{{ $venta->nro_comprobante }}
                                        <a href="#" class="js-ver-recibo-cobranza ms-2" data-url="{{ route('ventas.cobranzas.recibo', [$venta, $cobro]) }}">Ver Recibo</a>
                                        <a href="#" class="js-eliminar-cobro text-danger ms-2" data-id="{{ $cobro->id }}"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">Sin cobranzas</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Documento imprimible con watermark --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-3">
                    <h5 class="mb-0">Comprobante {{ $venta->tipo_comprobante }} N° {{ $venta->nro_comprobante }}</h5>
                    <div class="text-end">
                        <div>Fecha de Emisión: {{ optional($venta->fecha_emision)->format('d/m/Y') }}</div>
                        @if ($venta->fecha_vto_cobro)
                            <div>Vto. del Cobro: {{ $venta->fecha_vto_cobro->format('d/m/Y') }}</div>
                        @endif
                    </div>
                </div>

                <div class="border rounded p-3 mb-3 bg-white">
                    <div class="row">
                        <div class="col-md-6">
                            <div><strong>Cliente:</strong> {{ optional($venta->cliente)->nombre }}</div>
                            <div><strong>Nombre:</strong> {{ optional($venta->cliente)->nombre_pila ?: '-' }}</div>
                            <div><strong>Apellido:</strong> {{ optional($venta->cliente)->apellido ?: '-' }}</div>
                            <div><strong>Teléfono:</strong> {{ optional($venta->cliente)->telefono ?: '-' }}</div>
                            <div><strong>Domicilio:</strong> {{ optional($venta->cliente)->domicilio ?: '-' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>CUIT:</strong> {{ optional($venta->cliente)->cuit ?: '-' }}</div>
                            <div><strong>Condición IVA:</strong> {{ optional(optional($venta->cliente)->condicionIva)->nombre ?: '-' }}</div>
                            <div><strong>Categoría:</strong> {{ optional($venta->categoria)->nombre ?: '-' }}</div>
                            <div><strong>Vendedor:</strong> {{ optional($venta->vendedor)->nombre ?: '-' }}</div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Código</th><th>Descripción</th><th>Cant.</th><th>Precio Unitario</th>
                                <th>Bonif.</th><th>Subtotal</th><th>Alícuota IVA</th><th>Subtotal c/IVA</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($venta->items as $item)
                                <tr>
                                    <td>{{ optional($item->producto)->codigo }}</td>
                                    <td>{{ $item->descripcion }}</td>
                                    <td>{{ (float) $item->cantidad }}</td>
                                    <td>$ {{ number_format((float) $item->precio_unitario, 2, ',', '.') }}</td>
                                    <td>{{ $item->descuento_pct ? $item->descuento_pct.'%' : '-' }}</td>
                                    <td>$ {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                                    <td>{{ \App\Models\Producto::etiquetaIva($item->iva_pct) }}</td>
                                    <td>$ {{ number_format((float) $item->subtotal_con_iva, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="row justify-content-end mb-3">
                    <div class="col-md-4">
                        <table class="table table-sm">
                            <tr><td>Importe Neto Gravado</td><td class="text-end">$ {{ number_format((float) $venta->subtotal_con_descuento, 2, ',', '.') }}</td></tr>
                            <tr><td>IVA</td><td class="text-end">$ {{ number_format($ivaTotal, 2, ',', '.') }}</td></tr>
                            <tr class="fw-bold"><td>Total</td><td class="text-end">$ {{ number_format((float) $venta->total, 2, ',', '.') }}</td></tr>
                        </table>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6"><strong>Formas de Pago:</strong> {{ $venta->formas_pago ?: '-' }}</div>
                    <div class="col-md-6"><strong>Métodos de Envío:</strong> {{ $venta->metodos_envio ?: '-' }}</div>
                </div>
            </div>
            <div class="card-footer text-end" style="position:relative; z-index:2;">
                <button type="button" class="btn btn-outline-primary js-imprimir" data-id="{{ $venta->id }}">Imprimir Detalle</button>
                <button type="button" class="btn btn-outline-primary js-imprimir-ticket" data-id="{{ $venta->id }}">Imprimir Ticket</button>
            </div>
        </div>

        {{-- Notas de Crédito y Débito --}}
        <div class="card mb-3" id="notas">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Notas de Crédito y Débito</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-agregar-nota">
                        <i class="fas fa-plus me-1"></i> Agregar
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Id</th><th>Tipo</th><th>Fecha</th><th>Afecta Stock</th><th>Mes de Imputación</th><th>Monto</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($venta->notasCreditoDebito as $nota)
                                <tr>
                                    <td>{{ $nota->id }}</td>
                                    <td>{{ $nota->tipo === 'credito' ? 'Nota de Crédito' : 'Nota de Débito' }}</td>
                                    <td>{{ $nota->fecha_emision->format('d/m/Y') }}</td>
                                    <td>{{ $nota->afecta_stock ? 'Sí' : 'No' }}</td>
                                    <td>{{ $nota->mes_imputacion->format('m/Y') }}</td>
                                    <td>$ {{ number_format((float) $nota->monto, 2, ',', '.') }}</td>
                                    <td>
                                        <a href="#" class="js-ver-detalle-nota" data-url="{{ route('ventas.notas.pdf', $nota) }}">Ver Detalle</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">Sin notas</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@include('ventas._modal_cobranza')
@includeIf('ventas._modal_ncnd')
@endsection

@php
    $datosCuentas = $cuentas->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre]);
    $datosDepositos = $depositos->map(fn ($d) => ['id' => $d->id, 'nombre' => $d->nombre]);
    $datosItemsVenta = $venta->items->map(fn ($i) => ['producto_id' => $i->producto_id, 'descripcion' => $i->descripcion])->filter(fn ($i) => $i['producto_id'])->values();
@endphp
@section('local-js')
<script>
    window.VentaDetalleData = {
        ventaId: {{ $venta->id }},
        total: {{ (float) $venta->total }},
        aCobrar: {{ $aCobrar }},
        nroComprobante: @json($venta->nro_comprobante),
        cuentas: @json($datosCuentas),
        depositos: @json($datosDepositos),
        items: @json($datosItemsVenta),
        autoAbrirCobranza: {{ request()->boolean('cobrar') ? 'true' : 'false' }},
    };
    window.VentasConfig = window.VentasConfig || {};
    window.VentasConfig.rutas = Object.assign(window.VentasConfig.rutas || {}, {
        cobranzaStore: "{{ route('ventas.cobranzas.store', $venta) }}",
        cobranzaDestroyBase: "{{ url('ventas/'.$venta->id.'/cobranzas') }}",
        remitoStore: "{{ route('ventas.remitos.store', $venta) }}",
        notasStore: "{{ route('ventas.notas.store', $venta) }}",
        notasItemsDisponibles: "{{ route('ventas.notas.itemsDisponibles', $venta) }}",
        pdf: "{{ route('ventas.pdf', $venta) }}",
        ticket: "{{ route('ventas.ticket', $venta) }}",
    });
</script>
@vite(['resources/js/ventas.js'])
@endsection
