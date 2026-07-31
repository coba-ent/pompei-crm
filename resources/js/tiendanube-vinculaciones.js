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
        inicializarImportar();
    });

    const MOTIVOS_TN = {
        producto_no_encontrado: 'El SKU no corresponde a ningún producto',
        tiendanube_no_encontrado: 'El "Identificador de URL" no existe en el catálogo en vivo de Tiendanube',
        ya_vinculado: 'Ya está vinculado',
    };

    function inicializarImportar() {
        const modalEl = document.getElementById('modal-importar-vinculaciones');
        const $btnAbrir = $('#btn-importar-vinculaciones');
        if (!modalEl || !$btnAbrir.length) { return; }
        const modal = new bootstrap.Modal(modalEl);

        $btnAbrir.on('click', () => {
            $('#form-importar-vinculaciones')[0].reset();
            $('#importar-archivo').removeClass('is-invalid');
            $('#error-importar-archivo').text('');
            $('#resultado-importar-vinculaciones').empty();
            modal.show();
        });

        $('#form-importar-vinculaciones').on('submit', function (e) {
            e.preventDefault();

            const archivo = $('#importar-archivo')[0].files[0];
            $('#importar-archivo').removeClass('is-invalid');
            $('#error-importar-archivo').text('');

            if (!archivo) {
                $('#importar-archivo').addClass('is-invalid');
                $('#error-importar-archivo').text('Elegí un archivo.');
                return;
            }

            const formData = new FormData();
            formData.append('archivo', archivo);

            const $btn = $('#btn-confirmar-importar-vinculaciones');
            $btn.prop('disabled', true);

            $.ajax({
                url: rutas.importar, method: 'POST', data: formData,
                contentType: false, processData: false,
            })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Importación ejecutada.');
                    $('#resultado-importar-vinculaciones').html(renderResultadoImportar(resp));
                    if (tabla && resp.vinculadas > 0) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    if (xhr.status === 422 && resp.errors && resp.errors.archivo) {
                        $('#importar-archivo').addClass('is-invalid');
                        $('#error-importar-archivo').text(resp.errors.archivo[0]);
                    }
                    toast('error', resp.message || resp.mensaje || 'No se pudo importar el archivo.');
                })
                .always(() => {
                    $btn.prop('disabled', false);
                });
        });
    }

    function renderResultadoImportar(resp) {
        const filas = (resp.detalle_fallidas || []).map((f) => (
            '<tr><td>' + $('<div>').text(f.referencia).html() + '</td><td>'
            + $('<div>').text(MOTIVOS_TN[f.motivo] || f.motivo).html() + '</td></tr>'
        )).join('');

        return '<hr><p>' + resp.vinculadas + ' vinculada(s), ' + resp.fallidas + ' sin vincular de ' + resp.total + ' filas.</p>'
            + '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>SKU</th><th>Motivo</th></tr></thead><tbody>'
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
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
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
            ],
        });
    }

    function limpiarErrores() {
        $('#form-vinculacion .is-invalid').removeClass('is-invalid');
        $('#form-vinculacion .invalid-feedback').text('');
    }

    function mostrarSelectVariante(seleccionado, deshabilitado) {
        const $select = $('#vinculacion-variant-id');
        $select.empty();

        if (seleccionado && seleccionado.id) {
            const opcion = new Option(seleccionado.text, seleccionado.id, true, true);
            $select.append(opcion);
        }

        $select.prop('disabled', !!deshabilitado);

        initSelect2($select, {
            dropdownParent: $('#modal-vinculacion'),
            placeholder: 'Buscar variante…',
            allowClear: true,
            ajax: {
                url: rutas.pendientes,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({
                    results: data.data.map((v) => ({ id: v.id, text: v.text, tn_product_id: v.tn_product_id, nombre: v.nombre })),
                }),
            },
        });

        if (seleccionado && seleccionado.id) {
            $select.trigger('change.select2');
        }

        $select.off('select2:select').on('select2:select', function (e) {
            const data = e.params.data;
            $('#vinculacion-tn-product-id').val(data.tn_product_id || '');
            $('#vinculacion-nombre-variante-tn').val(data.nombre || '');
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

        $('#btn-nueva-vinculacion').on('click', () => {
            idEnEdicion = null;
            limpiarErrores();
            $('#modal-vinculacion-titulo').text('Nueva vinculación');
            $('#vinculacion-id').val('');
            $('#vinculacion-tn-product-id').val('');
            $('#vinculacion-nombre-variante-tn').val('');
            mostrarSelectVariante(null, false);
            mostrarSelectProducto(null);
            modal.show();
        });

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
            // producto del CRM y el nombre.
            mostrarSelectVariante({
                id: $btn.data('variant-id'),
                text: $btn.data('nombre-variante') || ('Variante ' + $btn.data('variant-id')),
            }, true);
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

            const esEdicion = !!idEnEdicion;
            const url = esEdicion ? rutas.base + '/' + idEnEdicion : rutas.store;
            const metodo = esEdicion ? 'PATCH' : 'POST';

            $.ajax({ url, method: metodo, data: payload })
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
