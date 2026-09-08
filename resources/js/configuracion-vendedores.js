/**
 * Configuración & Ajustes → Vendedores (spec 101).
 * Modal "Configuración de Vendedores": alta, renombrado y toggle de activo,
 * cada uno con su propia llamada AJAX inmediata y su propio toast
 * (calco de configuracion-depositos.js).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[configuracion-vendedores] jQuery no está disponible.');
        return;
    }

    const cfg = window.VendedoresConfig || {};
    const rutas = cfg.rutas || {};

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true,
            progressBar: true,
            positionClass: 'toast-top-right',
            preventDuplicates: true,
            newestOnTop: true,
            timeOut: 4000,
            extendedTimeOut: 1500,
        };
    }

    function toast(tipo, mensaje, titulo) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje, titulo || '');
        } else {
            console.log('[configuracion-vendedores][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    function esc(texto) {
        return $('<div>').text(texto == null ? '' : texto).html();
    }

    function inicializarTooltips() {
        if (window.bootstrap && window.bootstrap.Tooltip) {
            document.querySelectorAll('#vendedores-lista [data-bs-toggle="tooltip"]').forEach(function (el) {
                const instancia = window.bootstrap.Tooltip.getInstance(el);
                if (instancia) {
                    instancia.dispose();
                }
                new window.bootstrap.Tooltip(el);
            });
        }
    }

    $(function () {
        const $btnConfigurar = $('#btn-configurar-vendedores');
        if (!$btnConfigurar.length) {
            return;
        }

        const $modalVendedores = $('#modal-vendedores');
        const modalVendedores = window.bootstrap ? new window.bootstrap.Modal($modalVendedores[0]) : null;
        const $lista = $('#vendedores-lista');

        function renderFila(vendedor) {
            const activoChecked = vendedor.activo ? 'checked' : '';
            const $fila = $(
                '<div class="d-flex align-items-center justify-content-between border-bottom py-2 js-vendedor-item" data-id="' + vendedor.id + '">' +
                    '<span class="js-vendedor-nombre flex-grow-1">' + esc(vendedor.nombre) + '</span>' +
                    '<div class="d-flex align-items-center gap-3">' +
                        '<div class="form-check form-switch mb-0">' +
                            '<input class="form-check-input js-vendedor-activo" type="checkbox" ' + activoChecked + ' ' +
                                'data-bs-toggle="tooltip" title="Al activar/desactivar el check, el vendedor se habilitará/ocultará en los selectores de Venta y Presupuesto.">' +
                        '</div>' +
                        '<a href="#" class="js-vendedor-editar text-primary" title="Editar"><i class="fas fa-pencil-alt"></i></a>' +
                    '</div>' +
                '</div>'
            );
            return $fila;
        }

        function cargarLista() {
            $.getJSON(rutas.data).done(function (resp) {
                $lista.empty();
                (resp.data || []).forEach(function (vendedor) {
                    $lista.append(renderFila(vendedor));
                });
                inicializarTooltips();
            }).fail(function () {
                toast('error', 'No se pudo cargar la lista de vendedores.');
            });
        }

        $btnConfigurar.on('click', function () {
            cargarLista();
            modalVendedores ? modalVendedores.show() : $modalVendedores.show();
        });

        // --- Crear / renombrar (modal de nombre) ---
        const $modalNombre = $('#modal-vendedor-nombre');
        const modalNombre = window.bootstrap ? new window.bootstrap.Modal($modalNombre[0]) : null;
        const $formNombre = $('#form-vendedor-nombre');

        function abrirModalNombre(modo, id, nombreActual) {
            $('#vendedor-nombre-id').val(id || '');
            $('#vendedor-nombre-input').val(nombreActual || '').removeClass('is-invalid');
            $('#vendedor-nombre-error').text('');
            $('#modal-vendedor-nombre-titulo').text(modo === 'renombrar' ? 'Renombrar Vendedor' : 'Nuevo Vendedor');
            modalNombre ? modalNombre.show() : $modalNombre.show();
            setTimeout(function () { $('#vendedor-nombre-input').trigger('focus'); }, 300);
        }

        $('#btn-agregar-vendedor').on('click', function () {
            abrirModalNombre('crear', '', '');
        });

        $lista.on('click', '.js-vendedor-editar', function (e) {
            e.preventDefault();
            const $item = $(this).closest('.js-vendedor-item');
            abrirModalNombre('renombrar', $item.data('id'), $item.find('.js-vendedor-nombre').text());
        });

        $formNombre.on('submit', function (e) {
            e.preventDefault();
            const id = $('#vendedor-nombre-id').val();
            const nombre = $('#vendedor-nombre-input').val().trim();
            $('#vendedor-nombre-input').removeClass('is-invalid');
            $('#vendedor-nombre-error').text('');
            if (!nombre) {
                $('#vendedor-nombre-input').addClass('is-invalid');
                $('#vendedor-nombre-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = !!id;
            const url = esRenombrar ? rutas.base + '/' + id : rutas.store;
            const datos = esRenombrar ? { _method: 'PATCH', nombre: nombre } : { nombre: nombre };

            window.AppBtn.loading('#btn-guardar-vendedor-nombre', true);
            $.ajax({ url: url, method: 'POST', dataType: 'json', data: datos })
                .done(function (resp) {
                    if (esRenombrar) {
                        $lista.find('.js-vendedor-item[data-id="' + id + '"] .js-vendedor-nombre').text(resp.vendedor.nombre);
                    } else {
                        $lista.append(renderFila(resp.vendedor));
                        inicializarTooltips();
                    }
                    modalNombre ? modalNombre.hide() : $modalNombre.hide();
                    toast('success', resp.mensaje);
                })
                .fail(function (xhr) {
                    const msg = (xhr.responseJSON && (xhr.responseJSON.message ||
                        (xhr.responseJSON.errors && xhr.responseJSON.errors.nombre && xhr.responseJSON.errors.nombre[0]))) ||
                        'No se pudo guardar el vendedor.';
                    $('#vendedor-nombre-input').addClass('is-invalid');
                    $('#vendedor-nombre-error').text(msg);
                })
                .always(function () { window.AppBtn.loading('#btn-guardar-vendedor-nombre', false); });
        });

        // --- Toggle activo/inactivo ---
        $lista.on('change', '.js-vendedor-activo', function () {
            const $checkbox = $(this);
            const $item = $checkbox.closest('.js-vendedor-item');
            const id = $item.data('id');

            $.ajax({ url: rutas.base + '/' + id + '/estado', method: 'PATCH', dataType: 'json' })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                })
                .fail(function () {
                    $checkbox.prop('checked', !$checkbox.is(':checked'));
                    toast('error', 'No se pudo actualizar el estado del vendedor.');
                });
        });
    });
})();
