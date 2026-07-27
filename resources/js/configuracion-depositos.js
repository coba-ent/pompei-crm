/**
 * Configuración & Ajustes → Depósitos (spec 005).
 * Modal "Configuración de Depósitos": alta, renombrado, toggle de activo y
 * eliminación, cada uno con su propia llamada AJAX inmediata y su propio toast
 * (sin mecanismo de "guardar en lote" nuevo — research.md §1).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[configuracion-depositos] jQuery no está disponible.');
        return;
    }

    const cfg = window.DepositosConfig || {};
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
            console.log('[configuracion-depositos][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    function esc(texto) {
        return $('<div>').text(texto == null ? '' : texto).html();
    }

    function inicializarTooltips() {
        if (window.bootstrap && window.bootstrap.Tooltip) {
            document.querySelectorAll('#depositos-lista [data-bs-toggle="tooltip"]').forEach(function (el) {
                const instancia = window.bootstrap.Tooltip.getInstance(el);
                if (instancia) {
                    instancia.dispose();
                }
                new window.bootstrap.Tooltip(el);
            });
        }
    }

    $(function () {
        const $btnConfigurar = $('#btn-configurar-depositos');
        if (!$btnConfigurar.length) {
            return;
        }

        const $modalDepositos = $('#modal-depositos');
        const modalDepositos = window.bootstrap ? new window.bootstrap.Modal($modalDepositos[0]) : null;
        const $lista = $('#depositos-lista');

        function renderFila(deposito) {
            const activoChecked = deposito.activo ? 'checked' : '';
            const $fila = $(
                '<div class="d-flex align-items-center justify-content-between border-bottom py-2 js-deposito-item" data-id="' + deposito.id + '">' +
                    '<span class="js-deposito-nombre flex-grow-1">' + esc(deposito.nombre) + '</span>' +
                    '<div class="d-flex align-items-center gap-3">' +
                        '<div class="form-check form-switch mb-0">' +
                            '<input class="form-check-input js-deposito-activo" type="checkbox" ' + activoChecked + ' ' +
                                'data-bs-toggle="tooltip" title="Al activar/desactivar el check, el depósito se habilitará/ocultará en el listado de depósitos y reportes.">' +
                        '</div>' +
                        '<a href="#" class="js-deposito-editar text-primary" title="Editar"><i class="fas fa-pencil-alt"></i></a>' +
                        '<a href="#" class="js-deposito-eliminar text-danger" title="Eliminar"><i class="fas fa-trash-alt"></i></a>' +
                    '</div>' +
                '</div>'
            );
            return $fila;
        }

        function cargarLista() {
            $.getJSON(rutas.data).done(function (resp) {
                $lista.empty();
                (resp.data || []).forEach(function (deposito) {
                    $lista.append(renderFila(deposito));
                });
                inicializarTooltips();
            }).fail(function () {
                toast('error', 'No se pudo cargar la lista de depósitos.');
            });
        }

        $btnConfigurar.on('click', function () {
            cargarLista();
            modalDepositos ? modalDepositos.show() : $modalDepositos.show();
        });

        // --- Crear / renombrar (modal de nombre) ---
        const $modalNombre = $('#modal-deposito-nombre');
        const modalNombre = window.bootstrap ? new window.bootstrap.Modal($modalNombre[0]) : null;
        const $formNombre = $('#form-deposito-nombre');

        function abrirModalNombre(modo, id, nombreActual) {
            $('#deposito-nombre-id').val(id || '');
            $('#deposito-nombre-input').val(nombreActual || '').removeClass('is-invalid');
            $('#deposito-nombre-error').text('');
            $('#modal-deposito-nombre-titulo').text(modo === 'renombrar' ? 'Renombrar Depósito' : 'Nuevo Depósito');
            modalNombre ? modalNombre.show() : $modalNombre.show();
            setTimeout(function () { $('#deposito-nombre-input').trigger('focus'); }, 300);
        }

        $('#btn-agregar-deposito').on('click', function () {
            abrirModalNombre('crear', '', '');
        });

        $lista.on('click', '.js-deposito-editar', function (e) {
            e.preventDefault();
            const $item = $(this).closest('.js-deposito-item');
            abrirModalNombre('renombrar', $item.data('id'), $item.find('.js-deposito-nombre').text());
        });

        $formNombre.on('submit', function (e) {
            e.preventDefault();
            const id = $('#deposito-nombre-id').val();
            const nombre = $('#deposito-nombre-input').val().trim();
            $('#deposito-nombre-input').removeClass('is-invalid');
            $('#deposito-nombre-error').text('');
            if (!nombre) {
                $('#deposito-nombre-input').addClass('is-invalid');
                $('#deposito-nombre-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = !!id;
            const url = esRenombrar ? rutas.base + '/' + id : rutas.store;
            const datos = esRenombrar ? { _method: 'PATCH', nombre: nombre } : { nombre: nombre };

            $('#btn-guardar-deposito-nombre').prop('disabled', true);
            $.ajax({ url: url, method: 'POST', dataType: 'json', data: datos })
                .done(function (resp) {
                    if (esRenombrar) {
                        $lista.find('.js-deposito-item[data-id="' + id + '"] .js-deposito-nombre').text(resp.deposito.nombre);
                    } else {
                        $lista.append(renderFila(resp.deposito));
                        inicializarTooltips();
                    }
                    modalNombre ? modalNombre.hide() : $modalNombre.hide();
                    toast('success', resp.mensaje);
                })
                .fail(function (xhr) {
                    const msg = (xhr.responseJSON && (xhr.responseJSON.message ||
                        (xhr.responseJSON.errors && xhr.responseJSON.errors.nombre && xhr.responseJSON.errors.nombre[0]))) ||
                        'No se pudo guardar el depósito.';
                    $('#deposito-nombre-input').addClass('is-invalid');
                    $('#deposito-nombre-error').text(msg);
                })
                .always(function () { $('#btn-guardar-deposito-nombre').prop('disabled', false); });
        });

        // --- Toggle activo/inactivo ---
        $lista.on('change', '.js-deposito-activo', function () {
            const $checkbox = $(this);
            const $item = $checkbox.closest('.js-deposito-item');
            const id = $item.data('id');

            $.ajax({ url: rutas.base + '/' + id + '/estado', method: 'PATCH', dataType: 'json' })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                })
                .fail(function () {
                    $checkbox.prop('checked', !$checkbox.is(':checked'));
                    toast('error', 'No se pudo actualizar el estado del depósito.');
                });
        });

        // --- Eliminar (modal de confirmación) ---
        const $modalEliminar = $('#modal-deposito-eliminar');
        const modalEliminar = window.bootstrap ? new window.bootstrap.Modal($modalEliminar[0]) : null;
        let idAEliminar = null;

        $lista.on('click', '.js-deposito-eliminar', function (e) {
            e.preventDefault();
            const $item = $(this).closest('.js-deposito-item');
            idAEliminar = $item.data('id');
            $('#deposito-eliminar-nombre').text($item.find('.js-deposito-nombre').text());
            modalEliminar ? modalEliminar.show() : $modalEliminar.show();
        });

        $('#btn-confirmar-eliminar-deposito').on('click', function () {
            if (!idAEliminar) {
                return;
            }
            const id = idAEliminar;
            $.ajax({
                url: rutas.base + '/' + id, method: 'POST', dataType: 'json',
                data: { _method: 'DELETE' },
            }).done(function (resp) {
                $lista.find('.js-deposito-item[data-id="' + id + '"]').remove();
                toast('success', resp.mensaje);
            }).fail(function (xhr) {
                const resp = xhr.responseJSON || {};
                toast('error', resp.mensaje || 'No se pudo eliminar el depósito.');
            }).always(function () {
                modalEliminar ? modalEliminar.hide() : $modalEliminar.hide();
                idAEliminar = null;
            });
        });
    });
})();
