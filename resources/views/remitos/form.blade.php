@extends('layouts.default')

@php
    $esVenta = (bool) $venta;
    $comprobante = $venta ?? $compra;
    $tercero = $venta ? $venta->cliente : $compra?->proveedor;
    $editando = (bool) $remito;
    $rutaVolver = $volverA;
    $rutaStore = $venta ? route('ventas.remitos.store', $venta) : route('compras.remitos.store', $compra);
    $rutaUpdate = $editando
        ? ($venta ? route('ventas.remitos.update', [$venta, $remito]) : route('compras.remitos.update', [$compra, $remito]))
        : null;
    $rutaDestroy = $editando
        ? ($venta ? route('ventas.remitos.destroy', [$venta, $remito]) : route('compras.remitos.destroy', [$compra, $remito]))
        : null;

    $domicilioPrecarga = $editando
        ? $remito->domicilio_entrega
        : ($esVenta ? $tercero?->domicilio : $compra->deposito?->nombre);

    if ($editando) {
        $lineas = $remito->items->map(fn ($item) => [
            'producto_id' => $item->producto_id,
            'codigo' => $item->codigo,
            'descripcion' => $item->descripcion,
            'observacion' => $item->observacion,
            'cantidad' => (float) $item->cantidad,
            'cantidad_origen' => (float) $item->cantidad,
        ])->values();
    } else {
        $lineas = collect($lineasPrecarga)->map(fn ($linea) => array_merge($linea, [
            'cantidad_origen' => $linea['cantidad'],
        ]));
    }
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-8 mb-2">
                <h4 class="mb-0 text-primary fw-bold">
                    {{ $editando ? 'Editar Remito' : 'Nuevo Remito' }} {{ $esVenta ? 'Venta' : 'Compra' }} ID {{ $comprobante->id }}
                </h4>
            </div>
            <div class="col-sm-4 mb-2 text-sm-end">
                @if ($editando)
                    <button type="button" class="btn btn-outline-danger me-auto" id="btn-remito-eliminar">
                        <i class="fas fa-trash-alt me-1"></i> Eliminar
                    </button>
                @endif
                <a href="{{ $rutaVolver }}" class="btn btn-light" id="btn-remito-cancelar">Cancelar</a>
                <button type="button" class="btn btn-outline-primary" id="btn-remito-guardar">
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
                        <label class="form-label">Domicilio de Entrega</label>
                        <input type="text" id="f-domicilio-entrega" class="form-control" value="{{ $domicilioPrecarga }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Emisión</label>
                        <input type="date" id="f-fecha" class="form-control" value="{{ $editando ? $remito->fecha->format('Y-m-d') : now()->toDateString() }}">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Tipo</label>
                        <select id="f-tipo" class="form-select">
                            <option value="X" {{ (! $editando || $remito->tipo === 'X') ? 'selected' : '' }}>X</option>
                            <option value="R" {{ ($editando && $remito->tipo === 'R') ? 'selected' : '' }}>R</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">N° de Comprobante</label>
                        <input type="text" class="form-control" value="{{ $editando ? $remito->nro_remito : 'Se asigna al guardar' }}" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Transportista</label>
                        <div class="d-flex align-items-center gap-2">
                            <select id="f-transportista" class="form-select" style="width:100%"></select>
                            <a href="#" id="btn-nuevo-transportista" title="Crear Transportista"><i class="fas fa-plus-circle"></i></a>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Nota para el Cliente</label>
                        <textarea id="f-nota" class="form-control" rows="1">{{ $editando ? $remito->nota : '' }}</textarea>
                    </div>
                </div>

                <hr>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle" id="tabla-lineas-remito">
                        <thead>
                            <tr>
                                <th>Producto</th><th>Observaciones</th><th style="width:140px">Cantidad</th><th></th>
                            </tr>
                        </thead>
                        <tbody id="lineas-remito-body"></tbody>
                    </table>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6"></div>
                    <div class="col-lg-6">
                        <div class="bg-light rounded p-3">
                            <div class="row g-3 align-items-end">
                                <div class="col-6">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="f-monto-asegurado-toggle" {{ ($editando && $remito->monto_asegurado !== null) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="f-monto-asegurado-toggle">Monto Asegurado</label>
                                    </div>
                                    <input type="number" step="0.01" min="0" id="f-monto-asegurado" class="form-control"
                                        value="{{ $editando ? $remito->monto_asegurado : '' }}"
                                        {{ ($editando && $remito->monto_asegurado !== null) ? '' : 'disabled' }}>
                                </div>
                                <div class="col-6 text-end">
                                    <div class="text-muted small">Total Bultos</div>
                                    <div class="fs-4 fw-bold" id="total-bultos">0</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@include('remitos._modal_transportista')

@if ($editando)
<div class="modal fade" id="modal-eliminar-remito" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar Remito</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Seguro que querés eliminar este remito? Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar-remito">Eliminar</button>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@section('local-js')
<script>
    window.RemitoFormData = {
        esVenta: @json($esVenta),
        remito: @json($editando ? [
            'id' => $remito->id,
            'transportista_id' => $remito->transportista_id,
        ] : null),
        lineas: @json($lineas),
        transportista: @json($editando && $remito->transportista ? ['id' => $remito->transportista->id, 'nombre' => $remito->transportista->nombre] : null),
        totalOperacion: {{ (float) $comprobante->total }},
    };
    window.RemitoFormConfig = {
        rutas: {
            store: "{{ $rutaStore }}",
            update: @json($rutaUpdate),
            destroy: @json($rutaDestroy),
            volver: "{{ $rutaVolver }}",
            transportistaOpciones: "{{ route('transportistas.opciones') }}",
            transportistaStore: "{{ route('transportistas.store') }}",
        },
    };
</script>
@vite(['resources/js/remitos.js'])
@endsection
