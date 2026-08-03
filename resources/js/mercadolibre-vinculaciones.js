/**
 * Ingresos → Mercado Libre → Vinculaciones (spec 012, US2): DataTable
 * server-side + modal de alta/edición con Select2 (regla obligatoria de
 * diseño #5) + baja con advertencia. Todo por AJAX, sin recarga de página.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[mercadolibre-vinculaciones] jQuery no está disponible.');
        return;
    }

    const cfg = window.MercadoLibreVinculacionesConfig || {};
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
            console.log('[mercadolibre-vinculaciones][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
    }

    let tabla = null;
    let idEnEdicion = null;

    $(function () {
        inicializarListado();
        inicializarModalAlta();
        inicializarEliminar();
        inicializarVinculacionAutomatica();
        inicializarSincronizacionForzada();
        inicializarEliminarTodas();
    });

    /** "Sincronización forzada" (spec 035): recorre TODOS los vínculos, no sólo pendientes. */
    function inicializarSincronizacionForzada() {
        const $btn = $('#btn-sincronizacion-forzada');
        if (!$btn.length) { return; }

        $btn.on('click', function () {
            window.AppBtn.loading($btn, true);

            $.ajax({ url: rutas.sincronizacionForzada, method: 'POST' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Sincronización forzada ejecutada.');
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudo ejecutar la sincronización forzada.');
                })
                .always(() => {
                    window.AppBtn.loading($btn, false);
                });
        });
    }

    /** "Eliminar todas las vinculaciones" (spec 035): borrado masivo, sólo lado CRM. */
    function inicializarEliminarTodas() {
        const $btn = $('#btn-eliminar-todas-vinculaciones');
        const modalEl = document.getElementById('modal-eliminar-todas-vinculaciones');
        if (!$btn.length || !modalEl) { return; }

        const modal = new bootstrap.Modal(modalEl);

        $btn.on('click', () => modal.show());

        $('#btn-confirmar-eliminar-todas-vinculaciones').on('click', function () {
            const $confirmar = $(this);
            window.AppBtn.loading($confirmar, true);

            $.ajax({ url: rutas.eliminarTodas, method: 'DELETE' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculaciones eliminadas.');
                    modal.hide();
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudieron eliminar las vinculaciones.');
                })
                .always(() => {
                    window.AppBtn.loading($confirmar, false);
                });
        });
    }

    const MOTIVOS_ML = {
        sin_sku: 'Sin SKU de vendedor cargado',
        producto_no_encontrado: 'El SKU no corresponde a ningún producto',
        ya_vinculado: 'El producto ya está vinculado a otra publicación',
    };

    function inicializarVinculacionAutomatica() {
        const $btn = $('#btn-vincular-automaticamente');
        if (!$btn.length) { return; }

        const modalEl = document.getElementById('modal-resultado-vinculacion-automatica');
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

        $btn.on('click', function () {
            window.AppBtn.loading($btn, true);

            $.ajax({ url: rutas.vincularAutomaticamente, method: 'POST' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculación automática ejecutada.');

                    if (resp.fallidas > 0 && modal) {
                        $('#resultado-vinculacion-automatica-body').html(renderResultadoVinculacionAutomatica(resp));
                        modal.show();
                    }

                    if (tabla && resp.vinculadas > 0) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudo ejecutar la vinculación automática.');
                })
                .always(() => {
                    window.AppBtn.loading($btn, false);
                });
        });
    }

    function renderResultadoVinculacionAutomatica(resp) {
        const filas = (resp.detalle_fallidas || []).map((f) => (
            '<tr><td>' + $('<div>').text(f.referencia).html() + '</td><td>'
            + $('<div>').text(MOTIVOS_ML[f.motivo] || f.motivo).html() + '</td></tr>'
        )).join('');

        return '<p>' + resp.vinculadas + ' vinculada(s), ' + resp.fallidas + ' sin vincular de ' + resp.total + ' publicaciones pendientes.</p>'
            + '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Publicación</th><th>Motivo</th></tr></thead><tbody>'
            + filas + '</tbody></table></div>';
    }

    function inicializarListado() {
        const $tabla = $('#tabla-ml-vinculaciones');
        if (!$tabla.length) { return; }

        tabla = $tabla.DataTable({
            processing: true, serverSide: true, responsive: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ vinculaciones', infoEmpty: 'Sin vinculaciones',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron vinculaciones',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.datatable },
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
                { data: 'ml_item_id', name: 'ml_item_id' },
                { data: 'titulo_ml', name: 'titulo_ml' },
                { data: 'producto_nombre', name: 'producto.nombre' },
                { data: 'created_at', name: 'created_at' },
                {
                    // Unidades del depósito que publica ML — es el número que tiene que
                    // coincidir con el de la publicación, no el stock total del producto.
                    data: 'stock_ml', name: 'stock_ml', orderable: false, searchable: false,
                    render: (data) => data === null || data === undefined
                        ? '—'
                        : '<span class="badge bg-' + (data > 0 ? 'light text-dark' : 'danger') + '">' + data + '</span>',
                },
                {
                    data: 'stock_estado', name: 'stock_estado', orderable: false, searchable: false,
                    render: (data, type, row) => renderEstadoStock(data, row),
                },
                {
                    data: 'precio_estado', name: 'precio_estado', orderable: false, searchable: false,
                    render: (data, type, row) => renderEstadoPrecio(data, row),
                },
            ],
        });
    }

    const ESTADOS_STOCK = {
        sincronizado: { color: 'success', etiqueta: 'Sincronizado' },
        pendiente: { color: 'warning', etiqueta: 'Pendiente' },
        error: { color: 'danger', etiqueta: 'Error' },
    };

    function renderEstadoStock(estado, row) {
        const info = ESTADOS_STOCK[estado] || ESTADOS_STOCK.pendiente;
        let html = '<span class="badge bg-' + info.color + '">' + info.etiqueta + '</span>';

        if (estado === 'sincronizado' && row.stock_sincronizado_en) {
            html += ' <span class="text-muted small">' + row.stock_sincronizado_en + '</span>';
        }

        if (estado === 'error' && row.stock_error) {
            html += ' <i class="fas fa-circle-info text-danger ms-1" title="'
                + $('<div>').text(row.stock_error + (row.stock_error_en ? ' (' + row.stock_error_en + ')' : '')).html()
                + '"></i>';
        }

        return html;
    }

    const ESTADOS_PRECIO = {
        sincronizado: { color: 'success', etiqueta: 'Sincronizado' },
        pendiente: { color: 'warning', etiqueta: 'Pendiente' },
        error: { color: 'danger', etiqueta: 'Error' },
    };

    function renderEstadoPrecio(estado, row) {
        const info = ESTADOS_PRECIO[estado] || ESTADOS_PRECIO.pendiente;
        let html = '<span class="badge bg-' + info.color + '">' + info.etiqueta + '</span>';

        if (estado === 'sincronizado' && row.precio_sincronizado_en) {
            html += ' <span class="text-muted small">' + row.precio_sincronizado_en + '</span>';
        }

        if (estado === 'error' && row.precio_error) {
            html += ' <i class="fas fa-circle-info text-danger ms-1" title="'
                + $('<div>').text(row.precio_error + (row.precio_error_en ? ' (' + row.precio_error_en + ')' : '')).html()
                + '"></i>';
        }

        return html;
    }

    function limpiarErrores() {
        $('#form-vinculacion .is-invalid').removeClass('is-invalid');
        $('#form-vinculacion .invalid-feedback').text('');
    }

    function mostrarSelectPublicacion(seleccionado, deshabilitado) {
        const $select = $('#vinculacion-ml-item-id');
        $select.empty();

        if (seleccionado && seleccionado.id) {
            const opcion = new Option(seleccionado.text, seleccionado.id, true, true);
            $select.append(opcion);
        }

        $select.prop('disabled', !!deshabilitado);

        initSelect2($select, {
            dropdownParent: $('#modal-vinculacion'),
            placeholder: 'Buscar publicación…',
            allowClear: true,
            ajax: {
                url: rutas.pendientes,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({
                    results: data.data.map((p) => ({ id: p.id, text: p.text, titulo: p.titulo })),
                }),
            },
        });

        if (seleccionado && seleccionado.id) {
            $select.trigger('change.select2');
        }

        $select.off('select2:select').on('select2:select', function (e) {
            const data = e.params.data;
            $('#vinculacion-titulo-ml').val(data.titulo || '');
        });
    }

    function mostrarSelectProducto(seleccionado) {
        const $select = $('#vinculacion-producto-id');
        $select.empty();

        if (seleccionado && seleccionado.id) {
            const opcion = new Option(seleccionado.nombre, seleccionado.id, true, true);
            $select.append(opcion);
        }

        initSelect2($select, {
            dropdownParent: $('#modal-vinculacion'),
            placeholder: 'Buscar producto…',
            allowClear: true,
            ajax: {
                url: rutas.productosOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({ results: data.data.map((p) => ({ id: p.id, text: p.nombre + (p.codigo ? ' (' + p.codigo + ')' : '') })) }),
            },
        });

        if (seleccionado && seleccionado.id) {
            $select.trigger('change.select2');
        }
    }

    function inicializarModalAlta() {
        const modalEl = document.getElementById('modal-vinculacion');
        if (!modalEl) { return; }
        const modal = new bootstrap.Modal(modalEl);

        $(document).on('click', '.js-editar-vinculacion', function (e) {
            e.preventDefault();
            const $btn = $(this);
            idEnEdicion = $btn.data('id');
            limpiarErrores();
            $('#modal-vinculacion-titulo').text('Editar vinculación');
            $('#vinculacion-id').val(idEnEdicion);
            $('#vinculacion-titulo-ml').val($btn.data('titulo-ml'));
            // La publicación vinculada no se puede cambiar en una edición — sólo
            // el producto del CRM y el título.
            const tituloMl = $btn.data('titulo-ml');
            mostrarSelectPublicacion({
                id: $btn.data('ml-item-id'),
                text: $btn.data('ml-item-id') + (tituloMl ? ' — ' + tituloMl : ''),
            }, true);
            mostrarSelectProducto({ id: $btn.data('producto-id'), nombre: $btn.data('producto-nombre') });
            modal.show();
        });

        $('#form-vinculacion').on('submit', function (e) {
            e.preventDefault();
            limpiarErrores();

            const payload = {
                ml_item_id: $('#vinculacion-ml-item-id').val(),
                titulo_ml: $('#vinculacion-titulo-ml').val(),
                producto_id: $('#vinculacion-producto-id').val(),
            };

            // Sólo edición: el alta manual se reemplazó por la vinculación automática.
            $.ajax({ url: rutas.base + '/' + idEnEdicion, method: 'PATCH', data: payload })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculación guardada.');
                    modal.hide();
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    if (xhr.status === 422 && resp.errors) {
                        Object.keys(resp.errors).forEach((campo) => {
                            $('#vinculacion-' + campo.replace(/_/g, '-')).addClass('is-invalid');
                            $('#error-' + campo.replace(/_/g, '-')).text(resp.errors[campo][0]);
                        });
                    }
                    toast('error', resp.message || resp.mensaje || 'No se pudo guardar la vinculación.');
                });
        });
    }

    function inicializarEliminar() {
        let idAEliminar = null;
        const modalEl = document.getElementById('modal-eliminar-vinculacion');
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

        $(document).on('click', '.js-eliminar-vinculacion', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            if (modal) { modal.show(); }
        });

        $('#btn-confirmar-eliminar-vinculacion').on('click', () => {
            if (!idAEliminar) { return; }

            $.ajax({ url: rutas.base + '/' + idAEliminar, method: 'DELETE' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculación eliminada.');
                    if (resp.advertencia) { toast('warning', resp.advertencia); }
                    if (modal) { modal.hide(); }
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo eliminar la vinculación.');
                });
        });
    }
})();
