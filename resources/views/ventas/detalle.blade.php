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
                <span class="badge bg-dark ms-2 align-middle">Venta #{{ $venta->id }}</span>
                @if ($venta->origen === 'mercadolibre' && $venta->mlOrden && \Illuminate\Support\Facades\Route::has('ingresos.mercadolibre.index'))
                    <span class="badge bg-warning text-dark ms-2">
                        <i class="fas fa-store me-1"></i> Origen: Mercado Libre — orden {{ $venta->mlOrden->ml_order_id }}
                    </span>
                @endif
            </div>
            <div class="col-sm-6 text-sm-end">
                @if ($venta->puedeEnviarseAArca())
                    <button type="button" class="btn btn-warning js-enviar-arca me-1"
                            data-id="{{ $venta->id }}" data-url="{{ route('ventas.enviarArca', $venta) }}">
                        <i class="fas fa-paper-plane me-1"></i> Enviar a ARCA
                    </button>
                @elseif ($venta->estaFacturada())
                    <button type="button" class="btn btn-secondary me-1" disabled
                            title="Ya enviada a ARCA: tiene CAE aprobado.">
                        <i class="fas fa-check me-1"></i> Enviada a ARCA
                    </button>
                @endif
                <a href="{{ route('ventas.remitos.create', $venta) }}" class="btn btn-warning">
                    <i class="fas fa-truck me-1"></i> Crear Remito
                </a>
            </div>
        </div>

        @php
            // spec 063 (FR-008/FR-009): aviso de cancelación/reembolso parcial/mediación posterior
            // a la conversión — el motivo a la vista al llegar desde el aviso o al listado.
            $avisoCancelacion = $venta->mlOrden
                && $venta->mlOrden->estado_conversion === \App\Enums\MercadoLibre\EstadoConversion::RequiereAtencion
                && in_array($venta->mlOrden->motivo, \App\Enums\MercadoLibre\MotivoRequiereAtencion::motivosDeCancelacionPosterior(), true)
                ? $venta->mlOrden
                : null;
        @endphp
        @if ($avisoCancelacion)
            <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                <i class="fas fa-triangle-exclamation mt-1"></i>
                <div>
                    <strong>{{ $avisoCancelacion->motivo->etiqueta() }}</strong>
                    <div class="small">{{ $avisoCancelacion->motivo_detalle }}</div>
                    <div class="small text-muted mt-1">
                        El aviso no bloquea nada: podés editar y cobrar esta Venta con normalidad. Resolvela
                        con lo que ya existe —una nota de crédito o eliminando la Venta— o descartá el aviso
                        desde la pantalla de Órdenes de Mercado Libre si no corresponde anular nada.
                    </div>
                </div>
            </div>
        @endif

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

        {{-- Cascada de precios de Mercado Libre: explica por qué el total de la Venta no coincide
             con el precio publicado (promoción compartida + cupón de ML). Sólo datos ya
             sincronizados, no se consulta la API. --}}
        @if ($descuentoMl)
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="mb-3">
                        <i class="fas fa-tags me-1 text-warning"></i> Precios de Mercado Libre
                    </h5>
                    <div class="row">
                        <div class="col-md-7">
                            <table class="table table-sm mb-0">
                                <tr>
                                    <td>Precio de lista de la publicación</td>
                                    <td class="text-end">$ {{ number_format($descuentoMl['precio_lista'], 2, ',', '.') }}</td>
                                </tr>
                                @if (abs($descuentoMl['aporte_vendedor']) >= 0.01)
                                    <tr class="text-danger">
                                        <td>− Descuento aportado por el vendedor</td>
                                        <td class="text-end">-$ {{ number_format($descuentoMl['aporte_vendedor'], 2, ',', '.') }}</td>
                                    </tr>
                                @endif
                                <tr class="fw-bold border-top">
                                    <td>= Precio registrado en esta Venta</td>
                                    <td class="text-end">$ {{ number_format($descuentoMl['precio_venta'], 2, ',', '.') }}</td>
                                </tr>
                                @if ($descuentoMl['aporte_ml'] >= 0.01)
                                    <tr class="text-muted">
                                        <td>− Descuento aportado por Mercado Libre (cupón)</td>
                                        <td class="text-end">-$ {{ number_format($descuentoMl['aporte_ml'], 2, ',', '.') }}</td>
                                    </tr>
                                    <tr class="text-muted">
                                        <td>= Precio que pagó el comprador</td>
                                        <td class="text-end">$ {{ number_format($descuentoMl['pago_comprador'], 2, ',', '.') }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                        <div class="col-md-5">
                            <table class="table table-sm mb-0">
                                <tr>
                                    <td>Precio registrado en esta Venta</td>
                                    <td class="text-end">$ {{ number_format($descuentoMl['precio_venta'], 2, ',', '.') }}</td>
                                </tr>
                                <tr class="text-danger">
                                    <td>− Comisión de Mercado Libre</td>
                                    <td class="text-end">-$ {{ number_format($descuentoMl['comision_ml'], 2, ',', '.') }}</td>
                                </tr>
                                <tr class="fw-bold border-top">
                                    <td>= Neto antes de envío</td>
                                    <td class="text-end">$ {{ number_format($descuentoMl['neto_vendedor'], 2, ',', '.') }}</td>
                                </tr>
                                @if ($descuentoMl['total_pagado_comprador'])
                                    <tr class="text-muted small">
                                        <td>
                                            Total financiado al comprador
                                            @if ($descuentoMl['cuotas']) ({{ $descuentoMl['cuotas'] }} cuotas) @endif
                                        </td>
                                        <td class="text-end">$ {{ number_format($descuentoMl['total_pagado_comprador'], 2, ',', '.') }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">
                        <i class="fas fa-circle-info me-1"></i>
                        El importe facturado es el <strong>precio registrado en esta Venta</strong>. El descuento que
                        aporta Mercado Libre no forma parte de tu ingreso ni de la base imponible, y el costo
                        financiero de las cuotas lo paga el comprador.
                    </div>
                </div>
            </div>
        @endif

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
                            <tr><th></th><th>Id</th><th>Fecha</th><th>Medio de cobro</th><th>Nota</th><th>Total</th><th>Comprobante</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($venta->cobros as $cobro)
                                <tr data-cobro-id="{{ $cobro->id }}"
                                    data-cobro-monto="{{ $cobro->monto }}"
                                    data-cobro-fecha="{{ $cobro->fecha->format('Y-m-d') }}"
                                    data-cobro-cuenta-id="{{ $cobro->cuenta_tesoreria_id }}"
                                    data-cobro-nota="{{ $cobro->nota }}">
                                    <td>@include('ventas._row_actions_cobranza', ['venta' => $venta, 'cobro' => $cobro])</td>
                                    <td>{{ $cobro->id }}</td>
                                    <td>{{ $cobro->fecha->format('d/m/Y') }}</td>
                                    <td>{{ optional($cobro->cuentaTesoreria)->nombre }}</td>
                                    <td>{{ $cobro->nota }}</td>
                                    <td>$ {{ number_format((float) $cobro->monto, 2, ',', '.') }}</td>
                                    <td>{{ $venta->nro_comprobante }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">Sin cobranzas</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Remitos (spec 064, FR-013, FR-026) --}}
        <div class="card mb-3" id="remitos">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Remitos</h5>
                    <a href="{{ route('ventas.remitos.create', $venta) }}" class="btn btn-sm btn-success">
                        <i class="fas fa-plus me-1"></i> Crear Remito
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Id</th><th>Fecha</th><th>Transportista</th><th>Nota</th><th>Total Bultos</th><th>Comprobante</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($venta->remitos as $remitoVenta)
                                <tr>
                                    <td>{{ $remitoVenta->id }}</td>
                                    <td>{{ optional($remitoVenta->fecha)->format('d/m/Y') }}</td>
                                    <td>{{ optional($remitoVenta->transportista)->nombre ?: '-' }}</td>
                                    <td>{{ $remitoVenta->nota ?: '-' }}</td>
                                    <td>{{ rtrim(rtrim(number_format($remitoVenta->totalBultos(), 3, ',', '.'), '0'), ',') }}</td>
                                    <td><a href="#" class="js-ver-remito" data-url="{{ route('remitos.pdf', $remitoVenta) }}"><i class="fas fa-eye me-1"></i>Ver Remito</a></td>
                                    <td><a href="{{ route('ventas.remitos.edit', [$venta, $remitoVenta]) }}" title="Editar"><i class="fas fa-pencil-alt"></i></a></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">Sin remitos</td></tr>
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
                            <div>
                                <strong>Depósito:</strong>
                                @if ($depositosDescontados->isNotEmpty())
                                    {{ $depositosDescontados->implode(', ') }}
                                @else
                                    <span class="text-muted">sin movimiento de stock</span>
                                @endif
                            </div>
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
                            @if ($venta->descuento > 0)
                                <tr><td>Subtotal sin Descuento</td><td class="text-end">$ {{ number_format((float) $venta->subtotal_sin_descuento, 2, ',', '.') }}</td></tr>
                                <tr><td>Descuento General ({{ rtrim(rtrim(number_format((float) $venta->descuento_general_pct, 2, ',', '.'), '0'), ',') }}%)</td><td class="text-end">-$ {{ number_format((float) $venta->descuento, 2, ',', '.') }}</td></tr>
                            @endif
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
                @if ($venta->nota_cliente)
                    <div class="row mt-2">
                        <div class="col-12">
                            <strong>Nota para el Cliente:</strong>
                            <p class="mb-0">{{ $venta->nota_cliente }}</p>
                        </div>
                    </div>
                @endif
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
                        <thead><tr><th>Id</th><th>Tipo</th><th>Fecha</th><th>Afecta Stock</th><th>Mes de Imputación</th><th>N° Comprobante</th><th>Documento que Ajusta</th><th>Monto</th><th>Nota Interna</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($venta->notasCreditoDebito as $nota)
                                <tr>
                                    <td>{{ $nota->id }}</td>
                                    <td>{{ $nota->tipo === 'credito' ? 'Nota de Crédito' : 'Nota de Débito' }}</td>
                                    <td>{{ $nota->fecha_emision->format('d/m/Y') }}</td>
                                    <td>{{ $nota->afecta_stock ? 'Sí' : 'No' }}</td>
                                    <td>{{ $nota->mes_imputacion->format('m/Y') }}</td>
                                    <td>{{ $nota->comprobanteFiscal?->numero ?? $nota->nro_comprobante ?? '-' }}</td>
                                    <td>{{ $nota->documentoQueAjusta($venta) ?? '-' }}</td>
                                    <td>$ {{ number_format((float) $nota->monto, 2, ',', '.') }}</td>
                                    <td>{{ $nota->nota_interna ?: '-' }}</td>
                                    <td class="dropdown">
                                        <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Estado</a>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item js-ver-detalle-nota" href="#" data-url="{{ route('ventas.notas.pdf', $nota) }}">Ver Detalle</a></li>
                                            <li><a class="dropdown-item js-editar-nota" href="#" data-id="{{ $nota->id }}">Editar</a></li>
                                            <li><a class="dropdown-item text-danger js-eliminar-nota" href="#" data-id="{{ $nota->id }}">Eliminar</a></li>
                                        </ul>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="text-center text-muted">Sin notas</td></tr>
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
@include('ventas._modales_arca')
@endsection

@php
    $datosCuentas = $cuentas->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre]);
    $datosDepositos = $depositos->map(fn ($d) => ['id' => $d->id, 'nombre' => $d->nombre]);
    $datosItemsVenta = $venta->items->map(fn ($i) => ['producto_id' => $i->producto_id, 'descripcion' => $i->descripcion])->filter(fn ($i) => $i['producto_id'])->values();
    $datosNotas = $venta->notasCreditoDebito->map(fn ($n) => [
        'id' => $n->id,
        'tipo' => $n->tipo,
        'afecta_stock' => $n->afecta_stock,
        'mes_imputacion' => optional($n->mes_imputacion)->format('Y-m'),
        'fecha_emision' => optional($n->fecha_emision)->format('Y-m-d'),
        'monto' => (float) $n->monto,
        'tipo_comprobante' => $n->tipo_comprobante,
        'nro_comprobante' => $n->nro_comprobante,
        'descripcion' => $n->descripcion,
        'nota_ajustada_id' => $n->nota_ajustada_id,
        'items' => $n->items->map(fn ($i) => [
            'producto_id' => $i->producto_id,
            'cantidad' => (float) $i->cantidad,
            'precio' => (float) $i->precio,
            'descuento_pct' => (float) ($i->descuento_pct ?? 0),
            'iva_pct' => $i->iva_pct !== null ? (float) $i->iva_pct : null,
        ]),
    ]);
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
        notas: @json($datosNotas),
        autoAbrirCobranza: {{ request()->boolean('cobrar') ? 'true' : 'false' }},
    };
    window.VentasConfig = window.VentasConfig || {};
    window.VentasConfig.rutas = Object.assign(window.VentasConfig.rutas || {}, {
        cobranzaStore: "{{ route('ventas.cobranzas.store', $venta) }}",
        cobranzaDestroyBase: "{{ url('ventas/'.$venta->id.'/cobranzas') }}",
        cobranzaUpdateBase: "{{ url('ventas/'.$venta->id.'/cobranzas') }}",
        notasStore: "{{ route('ventas.notas.store', $venta) }}",
        notasItemsDisponibles: "{{ route('ventas.notas.itemsDisponibles', $venta) }}",
        notasUpdateBase: "{{ url('ventas/'.$venta->id.'/notas') }}",
        notasDestroyBase: "{{ url('ventas/'.$venta->id.'/notas') }}",
        notasCreatePagina: "{{ route('ventas.notas.create', $venta) }}",
        notasEditPaginaBase: "{{ url('ventas/'.$venta->id.'/notas') }}",
        pdf: "{{ route('ventas.pdf', $venta) }}",
        ticket: "{{ route('ventas.ticket', $venta) }}",
    });
</script>
@vite(['resources/js/ventas.js'])
@endsection
