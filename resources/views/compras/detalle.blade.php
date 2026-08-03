@extends('layouts.default')

@php
    $pagado = $compra->pagado();
    $totalNc = $compra->totalNotasCredito();
    $totalNd = $compra->totalNotasDebito();
    $aPagar = $compra->aPagar();
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">
        <div class="row align-items-center mb-3">
            <div class="col-sm-6">
                <a href="{{ route('compras.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Volver
                </a>
            </div>
            <div class="col-sm-6 text-sm-end">
                <button type="button" class="btn btn-warning" id="btn-crear-remito">
                    <i class="fas fa-truck me-1"></i> Crear Remito
                </button>
            </div>
        </div>

        {{-- Barra de ecuación (informe §2.4) --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="row text-center align-items-center">
                    <div class="col">
                        <div class="text-muted">Total Compra</div>
                        <div class="h5">$ {{ number_format((float) $compra->total, 2, ',', '.') }}</div>
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
                        <div class="text-muted">Pagado</div>
                        <div class="h5" id="detalle-pagado">$ {{ number_format($pagado, 2, ',', '.') }}</div>
                    </div>
                    <div class="col-auto">=</div>
                    <div class="col">
                        <div class="text-muted">A Pagar</div>
                        <div class="h5 fw-bold" id="detalle-a-pagar">$ {{ number_format($aPagar, 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pagos --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Pagos</h5>
                    <button type="button" class="btn btn-sm btn-success" id="btn-agregar-pago">
                        <i class="fas fa-plus me-1"></i> Agregar Pago
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm" id="tabla-pagos">
                        <thead>
                            <tr><th>Id</th><th>Fecha</th><th>Medio de pago</th><th>Nota</th><th>Total</th><th>Comprobante</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($compra->pagos as $pago)
                                <tr data-pago-id="{{ $pago->id }}">
                                    <td>{{ $pago->id }}</td>
                                    <td>{{ $pago->fecha->format('d/m/Y') }}</td>
                                    <td>{{ optional($pago->cuentaTesoreria)->nombre }}</td>
                                    <td>{{ $pago->nota }}</td>
                                    <td>$ {{ number_format((float) $pago->monto, 2, ',', '.') }}</td>
                                    <td>{{ $compra->nro_comprobante }}
                                        <a href="#" class="js-ver-recibo-pago ms-2" data-url="{{ route('compras.pagos.recibo', [$compra, $pago]) }}">Ver Recibo</a>
                                        <a href="#" class="js-eliminar-pago text-danger ms-2" data-id="{{ $pago->id }}"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">Sin pagos</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Retenciones (US2) --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Retenciones</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-agregar-retencion">
                        <i class="fas fa-plus me-1"></i> Agregar Retención
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Id</th><th>Fecha</th><th>Tipo</th><th>N° Comprobante</th><th>Monto</th></tr></thead>
                        <tbody>
                            @forelse ($compra->pagos->flatMap->retenciones as $retencion)
                                <tr>
                                    <td>{{ $retencion->id }}</td>
                                    <td>{{ $retencion->fecha->format('d/m/Y') }}</td>
                                    <td>{{ $retencion->tipo_retencion }}</td>
                                    <td>{{ $retencion->nro_comprobante }}</td>
                                    <td>$ {{ number_format((float) $retencion->monto, 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">Sin retenciones</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Documento imprimible con watermark --}}
        <div class="card mb-3 position-relative overflow-hidden">
            <div class="position-absolute top-50 start-50 translate-middle text-danger opacity-25 fw-bold"
                 style="font-size:3rem; transform: translate(-50%, -50%) rotate(-20deg); pointer-events:none; z-index:1;">
                NO VÁLIDO COMO FACTURA
            </div>
            <div class="card-body" style="position:relative; z-index:2;">
                <div class="d-flex justify-content-between mb-3">
                    <h5 class="mb-0">Comprobante {{ $compra->tipo_comprobante }} N° {{ $compra->nro_comprobante }}</h5>
                    <div class="text-end">
                        <div>Fecha de Emisión: {{ optional($compra->fecha_emision)->format('d/m/Y') }}</div>
                        @if ($compra->fecha_vto_pago)
                            <div>Vto. del Pago: {{ $compra->fecha_vto_pago->format('d/m/Y') }}</div>
                        @endif
                    </div>
                </div>

                <div class="border rounded p-3 mb-3 bg-white">
                    <div class="row">
                        <div class="col-md-6">
                            <div><strong>Proveedor:</strong> {{ optional($compra->proveedor)->nombre }}</div>
                            <div><strong>Teléfono:</strong> {{ optional($compra->proveedor)->telefono ?: '-' }}</div>
                            <div><strong>Domicilio:</strong> {{ optional($compra->proveedor)->domicilio ?: '-' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div><strong>CUIT:</strong> {{ optional($compra->proveedor)->cuit ?: '-' }}</div>
                            <div><strong>Condición IVA:</strong> {{ optional(optional($compra->proveedor)->condicionIva)->nombre ?: '-' }}</div>
                            <div><strong>Categoría:</strong> {{ optional($compra->categoria)->nombre ?: '-' }}</div>
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
                            @foreach ($compra->items as $item)
                                <tr>
                                    <td>{{ optional($item->producto)->codigo }}</td>
                                    <td>{{ $item->descripcion }}</td>
                                    <td>{{ (float) $item->cantidad }}</td>
                                    <td>$ {{ number_format((float) $item->precio_unitario, 2, ',', '.') }}</td>
                                    <td>{{ $item->descuento_pct ? $item->descuento_pct.'%' : '-' }}</td>
                                    <td>$ {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                                    <td>{{ $item->iva_pct ? \App\Models\Producto::etiquetaIva($item->iva_pct) : 'Elegir' }}</td>
                                    <td>$ {{ number_format((float) ($item->subtotal_con_iva ?? $item->subtotal), 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="row justify-content-end mb-3">
                    <div class="col-md-4">
                        <table class="table table-sm">
                            <tr><td>{{ $compra->items->every(fn ($i) => ! $i->iva_pct) ? 'Importe Neto No Gravado' : 'Importe Neto Gravado' }}</td><td class="text-end">$ {{ number_format((float) $compra->subtotal_con_descuento, 2, ',', '.') }}</td></tr>
                            <tr class="fw-bold"><td>Total</td><td class="text-end">$ {{ number_format((float) $compra->total, 2, ',', '.') }}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end" style="position:relative; z-index:2;">
                <button type="button" class="btn btn-outline-primary js-imprimir" data-id="{{ $compra->id }}">Imprimir Detalle</button>
            </div>
        </div>

        {{-- Notas de Crédito y Débito (US4) --}}
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
                        <thead><tr><th>Id</th><th>Tipo</th><th>Fecha</th><th>Afecta Stock</th><th>Monto</th></tr></thead>
                        <tbody>
                            @forelse ($compra->notasCreditoDebito as $nota)
                                <tr>
                                    <td>{{ $nota->id }}</td>
                                    <td>{{ $nota->tipo === 'credito' ? 'Nota de Crédito' : 'Nota de Débito' }}</td>
                                    <td>{{ $nota->fecha_emision->format('d/m/Y') }}</td>
                                    <td>{{ $nota->afecta_stock ? 'Sí' : 'No' }}</td>
                                    <td>$ {{ number_format((float) $nota->monto, 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">Sin notas</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@include('compras._modal_pago')
@include('compras._modal_retencion')
@includeIf('compras._modal_ncnd')
@endsection

@php
    $datosCuentas = $cuentas->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre]);
@endphp
@section('local-js')
<script>
    window.CompraDetalleData = {
        compraId: {{ $compra->id }},
        total: {{ (float) $compra->total }},
        aPagar: {{ $aPagar }},
        nroComprobante: @json($compra->nro_comprobante),
        cuentas: @json($datosCuentas),
    };
    window.ComprasConfig = window.ComprasConfig || {};
    window.ComprasConfig.rutas = Object.assign(window.ComprasConfig.rutas || {}, {
        pagoStore: "{{ route('compras.pagos.store', $compra) }}",
        pagoDestroyBase: "{{ url('compras/'.$compra->id.'/pagos') }}",
        retencionStore: "{{ route('compras.retenciones.store', $compra) }}",
        remitoStore: "{{ route('compras.remitos.store', $compra) }}",
        notasStore: "{{ route('compras.notas.store', $compra) }}",
        pdf: "{{ route('compras.pdf', $compra) }}",
    });
</script>
@vite(['resources/js/compras.js'])
@endsection
