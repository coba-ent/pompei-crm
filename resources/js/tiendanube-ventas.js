/**
 * Ingresos → Tiendanube (spec 017): listado de órdenes server-side, panel de
 * filtros, "Sincronizar ahora" y detalle en modal. Mismo patrón que
 * mercadolibre-ventas.js — todo por AJAX, sin recarga de página (regla de diseño).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[tiendanube-ventas] jQuery no está disponible.');
        return;
    }

    const cfg = window.TiendanubeVentasConfig || {};
    const rutas = cfg.rutas || {};

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true, progressBar: true, positionClass: 'toast-top-right',
            preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
        };
    }
    function toast(tipo, mensaje, titulo) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje, titulo || '');
        } else {
            console.log('[tiendanube-ventas][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    function money(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    $(function () {
        inicializarListado();
        inicializarSincronizar();
        inicializarSincronizarStock();
        inicializarDetalle();
    });

    function inicializarListado() {
        const $tabla = $('#tabla-tn-ordenes');
        if (!$tabla.length) { return; }

        function filtrosActuales() {
            return {
                estado_conversion: $('#filtro-estado-conversion').val(),
                desde: $('#filtro-desde').val(),
                hasta: $('#filtro-hasta').val(),
            };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true, responsive: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ órdenes', infoEmpty: 'Sin órdenes',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron órdenes',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            order: [[2, 'desc']],
            ajax: { url: rutas.datatable, data: (d) => $.extend(d, filtrosActuales()) },
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
                { data: 'tn_order_id', name: 'tn_order_id' },
                { data: 'fecha_cerrada', name: 'fecha_cerrada' },
                {
                    data: 'comprador', name: 'comprador',
                    render: (data, type, row) => (data || '—')
                        + (row.cliente_nuevo
                            ? ' <span class="badge bg-warning text-dark" title="Este comprador no existe como Cliente del CRM. Al convertir la orden se va a crear uno nuevo automáticamente.">Cliente nuevo</span>'
                            : ''),
                },
                { data: 'productos', name: 'productos', orderable: false, searchable: false },
                { data: 'total', name: 'total', render: money },
                { data: 'fulfillment_status', name: 'fulfillment_status' },
                {
                    data: 'estado_conversion_label', name: 'estado_conversion',
                    render: (data, type, row) => '<span class="badge bg-' + row.estado_conversion_color + '">' + data + '</span>',
                },
                { data: 'motivo_label', name: 'motivo' },
                {
                    data: 'venta_nro', name: 'venta_id',
                    render: (data, type, row) => data && rutas.ventaShow
                        ? '<a href="' + rutas.ventaShow + '/' + row.venta_id + '">' + data + '</a>' : (data || '—'),
                },
            ],
        });

        $('#btn-aplicar-filtros-tn').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros-tn').on('click', () => {
            $('#filtro-estado-conversion').val('');
            $('#filtro-desde, #filtro-hasta').val('');
            tabla.ajax.reload();
        });

        window._tnOrdenesTabla = tabla;
    }

    function inicializarSincronizar() {
        $('#btn-sincronizar-tn').on('click', function () {
            const $btn = $(this).prop('disabled', true);

            $.post(rutas.sincronizar)
                .done((resp) => {
                    toast('success', resp.mensaje || 'Sincronización completa.');
                    if (window._tnOrdenesTabla) { window._tnOrdenesTabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo sincronizar.');
                })
                .always(() => $btn.prop('disabled', false));
        });
    }

    function inicializarSincronizarStock() {
        $('#btn-sincronizar-stock-tn').on('click', function () {
            const $btn = $(this).prop('disabled', true);

            $.post(rutas.sincronizarStock)
                .done((resp) => {
                    toast('success', resp.mensaje || 'Sincronización de stock completa.');
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo sincronizar el stock.');
                })
                .always(() => $btn.prop('disabled', false));
        });
    }

    function inicializarDetalle() {
        $(document).on('click', '.js-ver-detalle', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const $body = $('#modal-detalle-orden-tn-body').html('Cargando…');
            const modal = new bootstrap.Modal(document.getElementById('modal-detalle-orden-tn'));
            modal.show();

            $.getJSON(rutas.show + '/' + id).done((resp) => {
                const o = resp.orden;
                let html = '<dl class="row mb-0">';
                html += '<dt class="col-sm-4">Orden</dt><dd class="col-sm-8">' + o.tn_order_id + '</dd>';
                html += '<dt class="col-sm-4">Comprador</dt><dd class="col-sm-8">' + (o.comprador_nombre || o.comprador_email || '—')
                    + (o.cliente_nuevo ? ' <span class="badge bg-warning text-dark">Cliente nuevo</span>' : '') + '</dd>';
                if (o.cliente_nuevo) {
                    html += '<dt class="col-sm-4"></dt><dd class="col-sm-8"><small class="text-muted">'
                        + 'No existe como Cliente del CRM: al convertir la orden se va a crear uno nuevo con los datos de Tiendanube.'
                        + '</small></dd>';
                }
                html += '<dt class="col-sm-4">Total</dt><dd class="col-sm-8">' + money(o.total) + '</dd>';
                html += '<dt class="col-sm-4">Estado</dt><dd class="col-sm-8">' + o.status + ' / ' + o.payment_status + '</dd>';
                html += '<dt class="col-sm-4">Envío</dt><dd class="col-sm-8">' + (o.fulfillment_status || '—') + '</dd>';
                html += '<dt class="col-sm-4">Productos</dt><dd class="col-sm-8"><ul class="mb-0">';
                (o.items || []).forEach((it) => {
                    html += '<li>' + it.nombre_producto + (it.nombre_variante ? ' (' + it.nombre_variante + ')' : '')
                        + ' — ' + it.cantidad + ' x ' + money(it.precio_unitario) + '</li>';
                });
                html += '</ul></dd>';
                if (o.motivo_detalle) {
                    html += '<dt class="col-sm-4">Motivo</dt><dd class="col-sm-8">' + o.motivo_detalle + '</dd>';
                }
                html += '</dl>';
                $body.html(html);
            }).fail(() => $body.html('<div class="text-danger">No se pudo cargar el detalle.</div>'));
        });
    }
})();
