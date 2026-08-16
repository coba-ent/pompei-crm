@extends('layouts.default')

@php
    $esVenta = (bool) $venta;
    $comprobante = $venta ?? $compra;
    $tercero = $venta ? $venta->cliente : $compra?->proveedor;
    $rutaVolver = $venta ? route('ventas.show', $venta) : route('compras.show', $compra);
    $rutaStore = $venta ? route('ventas.notas.store', $venta) : route('compras.notas.store', $compra);
    $rutaUpdate = $notaCreditoDebito
        ? ($venta ? route('ventas.notas.update', [$venta, $notaCreditoDebito]) : route('compras.notas.update', [$compra, $notaCreditoDebito]))
        : null;
    $rutaDestroy = $notaCreditoDebito
        ? ($venta ? route('ventas.notas.destroy', [$venta, $notaCreditoDebito]) : route('compras.notas.destroy', [$compra, $notaCreditoDebito]))
        : null;
    $rutaItemsDisponibles = $venta ? route('ventas.notas.itemsDisponibles', $venta) : route('compras.notas.itemsDisponibles', $compra);
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-8 mb-2">
                <h4 class="mb-0 text-primary fw-bold">
                    {{ $notaCreditoDebito ? 'Editar Nota #'.$notaCreditoDebito->id : 'Nueva Nota de Crédito/Débito' }}
                </h4>
            </div>
            <div class="col-sm-4 mb-2 text-sm-end">
                @if ($notaCreditoDebito)
                    <button type="button" class="btn btn-outline-danger me-auto" id="btn-nota-eliminar">
                        <i class="fas fa-trash-alt me-1"></i> Eliminar
                    </button>
                @endif
                <a href="{{ $rutaVolver }}" class="btn btn-light" id="btn-nota-cancelar">Cancelar</a>
                <button type="button" class="btn btn-outline-primary" id="btn-nota-guardar">
                    <i class="fas fa-save me-1"></i> Guardar
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">{{ $esVenta ? 'Cliente' : 'Proveedor' }}</label>
                        <input type="text" class="form-control" value="{{ $tercero?->nombre }}" disabled>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tipo</label>
                        <select id="f-tipo" class="form-select" style="width:100%">
                            <option value="credito">Nota de Crédito</option>
                            <option value="debito">Nota de Débito</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Documento que Ajusta</label>
                        <select id="f-documento-ajusta" class="form-select" style="width:100%" disabled>
                            <option value="{{ $comprobante->id }}" selected>{{ $comprobante->nro_comprobante ?? 'Sin comprobante' }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">¿Afecta Stock?</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="f-afecta-stock" id="f-afecta-si" value="1">
                                <label class="form-check-label" for="f-afecta-si">Sí</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="f-afecta-stock" id="f-afecta-no" value="0" checked>
                                <label class="form-check-label" for="f-afecta-no">No</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Emisión</label>
                        <input type="text" id="f-fecha-emision" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Vto. del Pago/Servicio Desde</label>
                        <input type="text" id="f-vto-desde" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Servicio Hasta</label>
                        <input type="text" id="f-vto-hasta" class="form-control" data-fecha-ar>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mes de Imputación</label>
                        <input type="month" id="f-mes-imputacion" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tipo Comprobante</label>
                        <input type="text" id="f-tipo-comprobante" class="form-control" maxlength="1" placeholder="A/B/C">
                    </div>
                    <div class="col-md-4" id="f-deposito-wrapper">
                        <label class="form-label">Depósito</label>
                        <select id="f-deposito" class="form-select" style="width:100%">
                            {{-- Opción vacía a propósito: sin esto el navegador elige la primera
                                 del listado —que va ordenado por nombre, y "Full" cae antes que
                                 "Local"—, así que una NC hecha sin tocar el campo devolvía la
                                 mercadería a Full aunque la Venta hubiera descontado de Local. --}}
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

                {{-- Sin selector manual de producto: cuando Stock=Sí, la tabla de abajo se
                     precarga automáticamente con todos los ítems pendientes de la Venta/Compra
                     (JS `cargarItemsDisponibles`). El usuario ajusta cantidades o borra la fila
                     del producto que no quiere tocar. --}}
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle" id="tabla-items">
                        <thead>
                            <tr>
                                <th id="th-producto">Producto</th><th>Cant.</th><th>Precio</th><th>Desc. %</th>
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
                                <tr><td>Subtotal sin Descuento</td><td class="text-end" id="tot-subtotal-sin-descuento">$ 0,00</td></tr>
                                <tr><td>Descuento</td><td class="text-end" id="tot-descuento">$ 0,00</td></tr>
                                <tr><td>Subtotal con Descuento</td><td class="text-end" id="tot-subtotal-con-descuento">$ 0,00</td></tr>
                                <tr class="fw-bold"><td>Total</td><td class="text-end" id="tot-total">$ 0,00</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if ($notaCreditoDebito)
<div class="modal fade" id="modal-eliminar-nota-pagina" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar Nota</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Seguro que querés eliminar esta Nota de Crédito/Débito? Si afecta stock, se revierte
                el movimiento. Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-nota-pagina">Eliminar</button>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@php
    $qs = request();
    $datosNota = $notaCreditoDebito ? $notaCreditoDebito->only([
        'id', 'tipo', 'afecta_stock', 'mes_imputacion', 'fecha_emision', 'monto',
        'tipo_comprobante', 'nro_comprobante', 'descripcion', 'nota_interna',
        'descuento_general_tipo', 'descuento_general_pct', 'descuento_general_monto',
    ]) : null;
    if ($datosNota) {
        $datosNota['mes_imputacion'] = optional($notaCreditoDebito->mes_imputacion)->format('Y-m');
        $datosNota['fecha_emision'] = optional($notaCreditoDebito->fecha_emision)->format('Y-m-d');
    }
    $datosItems = $notaCreditoDebito
        ? $notaCreditoDebito->items->map(fn ($i) => [
            'producto_id' => $i->producto_id,
            'descripcion' => optional($i->producto)->nombre,
            'cantidad' => (float) $i->cantidad,
            'precio' => (float) $i->precio,
            'descuento_pct' => (float) ($i->descuento_pct ?? 0),
            'iva_pct' => $i->iva_pct !== null ? (float) $i->iva_pct : null,
        ])->values()
        : collect();
@endphp
@section('local-js')
<script>
    window.NotaFormData = {
        esVenta: @json($esVenta),
        notaCreditoDebito: @json($datosNota),
        items: @json($datosItems),
        conceptos: @json($notaCreditoDebito->impuestos ?? []),
        descripcionLibre: @json($notaCreditoDebito?->descripcion),
        queryString: {
            tipo: @json($qs->query('tipo')),
            documentoAjusta: @json($qs->query('documento_ajusta')),
            afectaStock: @json($qs->query('afecta_stock')),
            depositoId: @json($qs->query('deposito_id')),
            mesImputacion: @json($qs->query('mes_imputacion')),
        },
        comprobanteOrigen: {
            id: @json($comprobante->id),
            nroComprobante: @json($comprobante->nro_comprobante),
            // Una NC repone donde el comprobante original descontó: el depósito viaja
            // hasta el form para preseleccionarlo, en vez de dejarlo librado al orden
            // del listado. Puede venir null en comprobantes migrados o anteriores al
            // fix de depósito de las integraciones — ahí el selector queda en blanco.
            depositoId: @json($comprobante->deposito_id),
        },
    };
    window.NotaFormConfig = {
        rutas: {
            store: "{{ $rutaStore }}",
            update: @json($rutaUpdate),
            destroy: @json($rutaDestroy),
            volver: "{{ $rutaVolver }}",
            itemsDisponibles: "{{ $rutaItemsDisponibles }}",
        },
    };
</script>
@vite(['resources/js/notas-credito-debito.js'])
@endsection
