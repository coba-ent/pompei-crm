/**
 * Ingresos → Mercado Libre (spec 012): listado de órdenes server-side,
 * panel de filtros, "Sincronizar ahora" y detalle en modal. Mismo patrón que
 * ventas.js — todo por AJAX, sin recarga de página (regla de diseño).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[mercadolibre-ventas] jQuery no está disponible.');
        return;
    }

    const cfg = window.MercadoLibreVentasConfig || {};
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
            console.log('[mercadolibre-ventas][' + tipo + ']', mensaje);
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
        inicializarTransformarTodasEnVenta();
    });

    function inicializarListado() {
        const $tabla = $('#tabla-ml-ordenes');
        if (!$tabla.length) { return; }

        function filtrosActuales() {
            return {
                estado_orden: $('#filtro-estado-orden').val(),
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
                { data: 'ml_order_id', name: 'ml_order_id' },
                {
                    data: null, name: 'etiquetas', orderable: false, searchable: false,
                    render: (data, type, row) => (row.es_prueba ? '<span class="badge bg-secondary">Prueba</span> ' : '')
                        + (row.tiene_alerta_fraude ? '<span class="badge bg-danger">Alerta de fraude</span>' : ''),
                },
                { data: 'fecha_cerrada', name: 'fecha_cerrada' },
                {
                    data: 'comprador', name: 'comprador',
                    // Aviso NO bloqueante (a diferencia de "Motivo", que sí bloquea la
                    // conversión): el comprador no existe como Cliente del CRM y se va a
                    // dar de alta uno nuevo al convertir la orden.
                    render: (data, type, row) => (data || '—')
                        + (row.cliente_nuevo
                            ? ' <span class="badge bg-warning text-dark" title="Este comprador no existe como Cliente del CRM. Al convertir la orden se va a crear uno nuevo automáticamente.">Cliente nuevo</span>'
                            : ''),
                },
                { data: 'productos', name: 'productos', orderable: false, searchable: false },
                { data: 'total', name: 'total', render: money },
                { data: 'estado_orden_label', name: 'estado_orden' },
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
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
            ],
        });

        $('#btn-aplicar-filtros-ml').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros-ml').on('click', () => {
            $('#filtro-estado-orden, #filtro-estado-conversion').val('');
            $('#filtro-desde, #filtro-hasta').val('');
            tabla.ajax.reload();
        });

        window._mlOrdenesTabla = tabla;
    }

    function inicializarSincronizar() {
        $('#btn-sincronizar-ml').on('click', function () {
            const $btn = window.AppBtn.loading($(this), true);

            $.post(rutas.sincronizar)
                .done((resp) => {
                    toast('success', resp.mensaje || 'Sincronización completa.');
                    if (window._mlOrdenesTabla) { window._mlOrdenesTabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo sincronizar.');
                })
                .always(() => window.AppBtn.loading($btn, false));
        });
    }

    function inicializarSincronizarStock() {
        $('#btn-sincronizar-stock-ml').on('click', function () {
            const $btn = window.AppBtn.loading($(this), true);

            $.post(rutas.sincronizarStock)
                .done((resp) => {
                    toast('success', resp.mensaje || 'Sincronización de stock completa.');
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo sincronizar el stock.');
                })
                .always(() => window.AppBtn.loading($btn, false));
        });
    }

    function inicializarTransformarTodasEnVenta() {
        const $btn = $('#btn-transformar-todas-en-venta-ml');
        if (!$btn.length) { return; }

        const modalEl = document.getElementById('modal-resultado-transformar-venta-ml');
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

        $btn.on('click', function () {
            window.AppBtn.loading($btn, true);

            $.ajax({ url: rutas.transformarTodasEnVenta, method: 'POST' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Transformación en Venta ejecutada.');

                    if (resp.fallidas > 0 && modal) {
                        $('#resultado-transformar-venta-ml-body').html(renderResultadoTransformarEnVenta(resp));
                        modal.show();
                    }

                    if (resp.convertidas > 0 && window._mlOrdenesTabla) { window._mlOrdenesTabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudo ejecutar la transformación en Venta.');
                })
                .always(() => {
                    window.AppBtn.loading($btn, false);
                });
        });
    }

    function renderResultadoTransformarEnVenta(resp) {
        const filas = (resp.detalle_fallidas || []).map((f) => (
            '<tr><td>' + $('<div>').text(f.orden).html() + '</td><td>'
            + $('<div>').text(f.motivo).html() + '</td><td>'
            + $('<div>').text(f.motivo_detalle || '').html() + '</td></tr>'
        )).join('');

        return '<p>' + resp.convertidas + ' de ' + resp.total + ' órdenes convertidas.</p>'
            + '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Orden</th><th>Motivo</th><th>Detalle</th></tr></thead><tbody>'
            + filas + '</tbody></table></div>';
    }

    function inicializarDetalle() {
        $(document).on('click', '.js-ver-detalle', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const $body = $('#modal-detalle-orden-ml-body').html('Cargando…');
            const modal = new bootstrap.Modal(document.getElementById('modal-detalle-orden-ml'));
            modal.show();

            $.getJSON(rutas.show + '/' + id).done((resp) => {
                const o = resp.orden;
                let html = '<dl class="row mb-0">';
                html += '<dt class="col-sm-4">Orden</dt><dd class="col-sm-8">' + o.ml_order_id + '</dd>';
                html += '<dt class="col-sm-4">Comprador</dt><dd class="col-sm-8">' + (o.comprador_nombre || o.comprador_apodo || '—')
                    + (o.cliente_nuevo ? ' <span class="badge bg-warning text-dark">Cliente nuevo</span>' : '') + '</dd>';
                if (o.cliente_nuevo) {
                    html += '<dt class="col-sm-4"></dt><dd class="col-sm-8"><small class="text-muted">'
                        + 'No existe como Cliente del CRM: al convertir la orden se va a crear uno nuevo con los datos de Mercado Libre.'
                        + '</small></dd>';
                }
                html += '<dt class="col-sm-4">Total</dt><dd class="col-sm-8">' + money(o.total) + '</dd>';
                html += '<dt class="col-sm-4">Estado en ML</dt><dd class="col-sm-8">' + o.estado_ml + '</dd>';
                html += '<dt class="col-sm-4">Productos</dt><dd class="col-sm-8"><ul class="mb-0">';
                (o.items || []).forEach((it) => {
                    html += '<li>' + it.titulo + ' — ' + it.cantidad + ' x ' + money(it.precio_unitario) + '</li>';
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
