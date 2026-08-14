@extends('layouts.default')

@php
    $cliente = $preview['cliente'];
    $datosFiscales = $preview['datos_fiscales'];
    $lineas = $preview['lineas'];
@endphp

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Crear Venta desde Mercado Libre</h4>
                <p class="text-muted mb-0">Orden {{ $orden->ml_order_id }} — revisá los datos y guardá.</p>
            </div>
        </div>

        {{-- spec 066 (FR-009): la orden está frenada a propósito. El aviso dice QUÉ pasa y qué
             implica seguir, para que confirmar sea una decisión y no un clic reflejo. --}}
        @if ($requiere_confirmacion)
            <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
                <i class="fas fa-triangle-exclamation mt-1"></i>
                <div>
                    <strong>Esta orden requiere tu decisión: {{ $motivo_etiqueta }}.</strong>
                    <div class="mt-1">
                        El sistema no la convierte solo. Si la convertís igual, se va a crear la Venta con
                        su cobro y su descuento de stock, y va a quedar registrado que lo decidiste vos.
                        El comprobante fiscal <strong>no</strong> se emite automáticamente: eso queda como
                        un paso aparte.
                    </div>
                </div>
            </div>
        @endif

        <form id="form-convertir-orden">
            <input type="hidden" id="submit_token" value="{{ $submitToken }}">

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Cliente</h6></div>
                <div class="card-body">
                    @if ($preview['cliente_ambiguo'])
                        <div class="alert alert-warning">
                            Hay más de un Cliente con el apodo <strong>{{ $orden->comprador_apodo }}</strong>.
                            Elegí a cuál corresponde esta orden.
                        </div>
                        <select class="form-select" id="cliente_id" style="width:100%">
                            <option value="">Elegí un Cliente…</option>
                            @foreach (\App\Models\Cliente::where('apodo_ml', $orden->comprador_apodo)->get() as $candidato)
                                <option value="{{ $candidato->id }}">{{ $candidato->nombre }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="hidden" id="cliente_id" value="{{ optional($cliente)->id }}">
                        <div class="fw-bold">{{ optional($cliente)->nombre ?? '—' }}</div>
                        <div class="text-muted small">Apodo Mercado Libre: {{ $orden->comprador_apodo ?? '—' }}</div>
                    @endif
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Comprobante</h6></div>
                <div class="card-body">
                    <label class="form-label">Tipo de comprobante</label>
                    <select class="form-select" id="tipo_comprobante" style="max-width:200px">
                        @foreach (['A', 'B', 'C', 'E'] as $tipo)
                            <option value="{{ $tipo }}" @selected($tipo === $datosFiscales['tipo_comprobante'])>{{ $tipo }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Derivado de la condición de IVA informada por Mercado Libre
                        ({{ $datosFiscales['condicion_iva'] }}@if($datosFiscales['aproximado']) — aproximado por tipo de documento @endif).
                        Podés corregirlo si hace falta (FR-043).
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0 fw-bold">Productos</h6></div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr><th>Publicación</th><th>Producto</th><th>Cantidad</th><th>Precio</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($orden->items as $index => $item)
                                @php $vinculo = \App\Models\Integraciones\MercadoLibrePublicacionProducto::where('ml_item_id', $item->ml_item_id)->first(); @endphp
                                <tr>
                                    <td>{{ $item->titulo }} <span class="text-muted small">({{ $item->ml_item_id }})</span></td>
                                    <td>
                                        @if ($vinculo)
                                            {{ optional($vinculo->producto)->nombre }}
                                        @else
                                            <select class="form-select select2-vinculacion-inline" style="width:100%" data-ml-item-id="{{ $item->ml_item_id }}"></select>
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
                <a href="{{ route('ingresos.mercadolibre.index') }}" class="btn btn-light">Cancelar</a>
                <button type="submit" class="btn btn-primary">Guardar Venta</button>
            </div>
        </form>

    </div>
</div>

@if ($requiere_confirmacion)
    <div class="modal fade" id="modal-confirmar-forzada" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar conversión</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        <strong>{{ $motivo_etiqueta }}.</strong>
                    </p>
                    <p class="mb-0">
                        Vas a crear la Venta de la orden <strong>{{ $orden->ml_order_id }}</strong> por
                        <strong>$ {{ number_format((float) $orden->total, 2, ',', '.') }}</strong> igual, asumiendo
                        esa situación. Se va a registrar tu nombre y la fecha. ¿Seguimos?
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">No, volver</button>
                    <button type="button" class="btn btn-warning" id="btn-confirmar-forzada">Sí, crear la Venta</button>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection

@section('local-js')
<script>
    window.MercadoLibreConvertirConfig = {
        rutas: {
            guardar: @json(route('ingresos.mercadolibre.convertirGuardar', $orden)),
            productosOpciones: @json(route('productos.opciones')),
        },
    };
</script>
@vite(['resources/js/mercadolibre-ventas.js'])
<script>
    (function () {
        var $ = window.jQuery;
        if (!$ || !$.fn.select2) { return; }

        $('.select2-vinculacion-inline').each(function () {
            $(this).select2({
                width: '100%', placeholder: 'Elegí un producto…',
                ajax: {
                    url: window.MercadoLibreConvertirConfig.rutas.productosOpciones,
                    data: (params) => ({ q: params.term }),
                    processResults: (data) => ({ results: data.data.map((p) => ({ id: p.id, text: p.nombre })) }),
                },
            });
        });

        if ($('#cliente_id').is('select')) {
            $('#cliente_id').select2({ width: '100%' });
        }

        // spec 066: la orden está en estado excepcional. El modal es comodidad — la barrera
        // real está en el servidor, que rechaza con 409 cualquier POST sin la confirmación.
        const requiereConfirmacion = @json($requiere_confirmacion);
        let confirmada = false;

        $('#form-convertir-orden').on('submit', function (e) {
            e.preventDefault();

            if (requiereConfirmacion && !confirmada) {
                const modalEl = document.getElementById('modal-confirmar-forzada');
                if (modalEl && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    return;
                }
            }

            const vinculaciones = [];
            $('.select2-vinculacion-inline').each(function () {
                const productoId = $(this).val();
                if (productoId) {
                    vinculaciones.push({ ml_item_id: $(this).data('ml-item-id'), producto_id: productoId });
                }
            });

            const CSRF = $('meta[name="csrf-token"]').attr('content');

            $.ajax({
                url: window.MercadoLibreConvertirConfig.rutas.guardar,
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF },
                data: {
                    submit_token: $('#submit_token').val(),
                    cliente_id: $('#cliente_id').val(),
                    tipo_comprobante: $('#tipo_comprobante').val(),
                    vinculaciones_inline: vinculaciones,
                    // 1/0, no true/false: el patrón hidden+checkbox manda "true" como string
                    // y la validación `boolean` de Laravel lo rechaza con 422.
                    forzar_conversion: (requiereConfirmacion && confirmada) ? 1 : 0,
                },
            }).done((resp) => {
                if (window.toastr) { window.toastr.success(resp.mensaje || 'Venta creada.'); }
                window.location.href = resp.redirect;
            }).fail((xhr) => {
                const resp = xhr.responseJSON || {};
                // El servidor puede pedir la confirmación aunque la pantalla no la esperara:
                // la orden pudo entrar en un estado excepcional mientras el formulario estaba
                // abierto (FR-015). Se reabre la decisión en vez de reenviar a ciegas.
                confirmada = false;
                if (window.toastr) { window.toastr.error(resp.mensaje || resp.message || 'No se pudo crear la Venta.'); }
            });
        });

        $('#btn-confirmar-forzada').on('click', function () {
            confirmada = true;

            const modalEl = document.getElementById('modal-confirmar-forzada');
            if (modalEl && window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }

            $('#form-convertir-orden').trigger('submit');
        });
    })();
</script>
@endsection
