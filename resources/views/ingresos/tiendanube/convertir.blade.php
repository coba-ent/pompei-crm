@extends('layouts.default')

@php
    $cliente = $preview['cliente'];
    $lineas = $preview['lineas'];
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Crear Venta desde Tiendanube</h4>
                <p class="text-muted mb-0">Orden {{ $orden->tn_order_id }} — revisá los datos y guardá.</p>
            </div>
        </div>

        <form id="form-convertir-orden">
            <input type="hidden" id="submit_token" value="{{ $submitToken }}">

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Cliente</h6></div>
                <div class="card-body">
                    @if ($preview['cliente_ambiguo'])
                        <div class="alert alert-warning">
                            Hay más de un Cliente con el email <strong>{{ $orden->comprador_email }}</strong>.
                            Elegí a cuál corresponde esta orden.
                        </div>
                        <select class="form-select" id="cliente_id" style="width:100%">
                            <option value="">Elegí un Cliente…</option>
                            @foreach (\App\Models\Cliente::where('email', $orden->comprador_email)->get() as $candidato)
                                <option value="{{ $candidato->id }}">{{ $candidato->nombre }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="hidden" id="cliente_id" value="{{ optional($cliente)->id }}">
                        <div class="fw-bold">{{ optional($cliente)->nombre ?? '—' }}</div>
                        <div class="text-muted small">Email: {{ $orden->comprador_email ?? '—' }}</div>
                    @endif
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Comprobante</h6></div>
                <div class="card-body">
                    <label class="form-label">Tipo de comprobante</label>
                    <select class="form-select" id="tipo_comprobante" style="max-width:200px">
                        @foreach (['A', 'B', 'C', 'E'] as $tipo)
                            <option value="{{ $tipo }}" @selected($tipo === $preview['tipo_comprobante'])>{{ $tipo }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        @if ($preview['aproximado'])
                            Aproximado por longitud de documento (Tiendanube no informa condición de IVA). Podés
                            corregirlo si hace falta (FR-043).
                        @else
                            Derivado de la condición de IVA ya cargada en el Cliente.
                        @endif
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Productos</h6></div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr><th>Variante</th><th>Producto</th><th>Cantidad</th><th>Precio</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($orden->items as $index => $item)
                                @php $vinculo = \App\Models\Integraciones\TiendanubeVarianteProducto::where('variant_id', $item->variant_id)->first(); @endphp
                                <tr>
                                    <td>{{ $item->nombre_producto }}@if($item->nombre_variante) <span class="text-muted small">({{ $item->nombre_variante }})</span>@endif</td>
                                    <td>
                                        @if ($vinculo)
                                            {{ optional($vinculo->producto)->nombre }}
                                        @else
                                            <select class="form-select select2-vinculacion-inline" style="width:100%" data-variant-id="{{ $item->variant_id }}"></select>
                                            <div class="text-danger small">Sin vincular — elegí un producto para poder guardar.</div>
                                        @endif
                                    </td>
                                    <td>{{ $item->cantidad }}</td>
                                    <td>$ {{ number_format((float) $item->precio_unitario, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="text-end fw-bold fs-5">Total: $ {{ number_format((float) $orden->total, 2, ',', '.') }}</div>
                </div>
            </div>

            <div class="text-end">
                <a href="{{ route('ingresos.tiendanube.index') }}" class="btn btn-light">Cancelar</a>
                <button type="submit" class="btn btn-primary">Guardar Venta</button>
            </div>
        </form>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.TiendanubeConvertirConfig = {
        rutas: {
            guardar: @json(route('ingresos.tiendanube.convertirGuardar', $orden)),
            productosOpciones: @json(route('productos.opciones')),
        },
    };
</script>
@vite(['resources/js/tiendanube-ventas.js'])
<script>
    (function () {
        var $ = window.jQuery;
        if (!$ || !$.fn.select2) { return; }

        $('.select2-vinculacion-inline').each(function () {
            $(this).select2({
                width: '100%', placeholder: 'Elegí un producto…',
                ajax: {
                    url: window.TiendanubeConvertirConfig.rutas.productosOpciones,
                    data: (params) => ({ q: params.term }),
                    processResults: (data) => ({ results: data.data.map((p) => ({ id: p.id, text: p.nombre })) }),
                },
            });
        });

        if ($('#cliente_id').is('select')) {
            $('#cliente_id').select2({ width: '100%' });
        }

        $('#form-convertir-orden').on('submit', function (e) {
            e.preventDefault();

            const vinculaciones = [];
            $('.select2-vinculacion-inline').each(function () {
                const productoId = $(this).val();
                if (productoId) {
                    vinculaciones.push({ variant_id: $(this).data('variant-id'), producto_id: productoId });
                }
            });

            const CSRF = $('meta[name="csrf-token"]').attr('content');

            $.ajax({
                url: window.TiendanubeConvertirConfig.rutas.guardar,
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF },
                data: {
                    submit_token: $('#submit_token').val(),
                    cliente_id: $('#cliente_id').val(),
                    tipo_comprobante: $('#tipo_comprobante').val(),
                    vinculaciones_inline: vinculaciones,
                },
            }).done((resp) => {
                if (window.toastr) { window.toastr.success(resp.mensaje || 'Venta creada.'); }
                window.location.href = resp.redirect;
            }).fail((xhr) => {
                const resp = xhr.responseJSON || {};
                if (window.toastr) { window.toastr.error(resp.mensaje || resp.message || 'No se pudo crear la Venta.'); }
            });
        });
    })();
</script>
@endsection
