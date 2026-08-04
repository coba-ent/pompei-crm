/**
 * Ingresos → Tiendanube → Vinculaciones (spec 017, US2): DataTable
 * server-side + modal de alta/edición con Select2 (regla obligatoria de
 * diseño #5) + baja con advertencia. Todo por AJAX, sin recarga de página.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[tiendanube-vinculaciones] jQuery no está disponible.');
        return;
    }

    const cfg = window.TiendanubeVinculacionesConfig || {};
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
            console.log('[tiendanube-vinculaciones][' + tipo + ']', mensaje);
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

    const ESTADOS_SYNC = {
        sincronizado: { etiqueta: 'Sincronizado', color: 'success' },
        pendiente: { etiqueta: 'Pendiente', color: 'warning' },
        error: { etiqueta: 'Error', color: 'danger' },
    };

    function renderEstadoSync(estado, row, prefijoFecha, prefijoError) {
        const info = ESTADOS_SYNC[estado] || ESTADOS_SYNC.pendiente;
        const fecha = row[prefijoFecha];
        const error = row[prefijoError];
        let titulo = '';
        if (estado === 'error' && error) {
            titulo = error + (row[prefijoError.replace('_error', '_error_en')] ? ' (' + row[prefijoError.replace('_error', '_error_en')] + ')' : '');
        } else if (estado === 'sincronizado' && fecha) {
            titulo = 'Sincronizado el ' + fecha;
        }
        return '<span class="badge bg-' + info.color + '"' + (titulo ? ' title="' + titulo.replace(/"/g, '&quot;') + '"' : '') + '>' + info.etiqueta + '</span>';
    }

    $(function () {
        inicializarListado();
        inicializarModalAlta();
        inicializarEliminar();
        inicializarVinculacionAutomatica();
        inicializarSincronizacionForzada();
        inicializarEliminarTodas();

        if (window.bootstrap && window.bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new window.bootstrap.Tooltip(el);
            });
        }
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

    const MOTIVOS_TN = {
        sin_sku: 'Sin SKU cargado',
        producto_no_encontrado: 'El SKU no corresponde a ningún producto',
        ya_vinculado: 'Ya está vinculado',
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
            + $('<div>').text(MOTIVOS_TN[f.motivo] || f.motivo).html() + '</td></tr>'
        )).join('');

        return '<p>' + resp.vinculadas + ' vinculada(s), ' + resp.fallidas + ' sin vincular de ' + resp.total + ' variantes.</p>'
            + '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Variante</th><th>Motivo</th></tr></thead><tbody>'
            + filas + '</tbody></table></div>';
    }

    function inicializarListado() {
        const $tabla = $('#tabla-tn-vinculaciones');
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
                { data: 'tn_product_id', name: 'tn_product_id' },
                { data: 'variant_id', name: 'variant_id' },
                { data: 'nombre_variante_tn', name: 'nombre_variante_tn' },
                { data: 'producto_nombre', name: 'producto.nombre' },
                { data: 'created_at', name: 'created_at' },
                {
                    data: 'stock_estado', name: 'stock_pendiente', orderable: false,
                    render: (data, type, row) => renderEstadoSync(data, row, 'stock_sincronizado_en', 'stock_error'),
                },
                {
                    data: 'precio_estado', name: 'precio_pendiente', orderable: false,
                    render: (data, type, row) => renderEstadoSync(data, row, 'precio_sincronizado_en', 'precio_error'),
                },
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
            ],
        });
    }

    function limpiarErrores() {
        $('#form-vinculacion .is-invalid').removeClass('is-invalid');
        $('#form-vinculacion .invalid-feedback').text('');
    }

    function mostrarSelectVariante(seleccionado) {
        const $select = $('#vinculacion-variant-id');
        $select.empty();

        if (seleccionado && seleccionado.id) {
            const opcion = new Option(seleccionado.text, seleccionado.id, true, true);
            $select.append(opcion);
        }

        initSelect2($select, { dropdownParent: $('#modal-vinculacion') });

        if (seleccionado && seleccionado.id) {
            $select.trigger('change.select2');
        }
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
            $('#vinculacion-tn-product-id').val($btn.data('tn-product-id'));
            $('#vinculacion-nombre-variante-tn').val($btn.data('nombre-variante'));
            // La variante vinculada no se puede cambiar en una edición — sólo el
            // producto del CRM y el nombre. El alta manual se reemplazó por la
            // vinculación automática (spec 024).
            mostrarSelectVariante({
                id: $btn.data('variant-id'),
                text: $btn.data('nombre-variante') || ('Variante ' + $btn.data('variant-id')),
            });
            mostrarSelectProducto({ id: $btn.data('producto-id'), nombre: $btn.data('producto-nombre') });
            modal.show();
        });

        $('#form-vinculacion').on('submit', function (e) {
            e.preventDefault();
            limpiarErrores();

            const payload = {
                tn_product_id: $('#vinculacion-tn-product-id').val(),
                variant_id: $('#vinculacion-variant-id').val(),
                nombre_variante_tn: $('#vinculacion-nombre-variante-tn').val(),
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
                            // tn_product_id no tiene campo propio en el modal (se completa
                            // solo al elegir la variante) — su error se muestra junto al select.
                            const key = campo === 'tn_product_id' ? 'variant-id' : campo.replace(/_/g, '-');
                            $('#vinculacion-' + key).addClass('is-invalid');
                            $('#error-' + key).text(resp.errors[campo][0]);
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
