/**
 * Configuración & Ajustes → Funciones Avanzadas (spec 011).
 * Toggle por AJAX de cada una de las 10 tarjetas, sin recarga de página (SC-009).
 * Ante un 409 de confirmación requerida (desactivar Mercado Libre con cuenta
 * conectada, FR-005a), abre un modal y reenvía con `confirmado: true` si el
 * usuario acepta.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[funciones-avanzadas] jQuery no está disponible.');
        return;
    }

    const cfg = window.FuncionesAvanzadasConfig || {};
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
            console.log('[funciones-avanzadas][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    $(function () {
        const $lista = $('#lista-funciones');
        if (!$lista.length) {
            return;
        }

        const $modalConfirmar = $('#modal-confirmar-desactivar-ml');
        const modalConfirmar = window.bootstrap ? new window.bootstrap.Modal($modalConfirmar[0]) : null;
        let pendienteConfirmacion = null;

        function actualizarLabel($checkbox, activa) {
            $checkbox.closest('.form-check').find('.js-funcion-label').text(activa ? 'Sí' : 'No');
        }

        function enviarToggle($checkbox, confirmado) {
            const $card = $checkbox.closest('[data-funcion-id]');
            const id = $card.data('funcion-id');
            const activa = $checkbox.is(':checked');
            // Laravel valida `boolean` que acepta 1/0/"1"/"0"/true/false, pero NO el string
            // "true"/"false" que jQuery genera al serializar un booleano de JS en un body
            // application/x-www-form-urlencoded. Mandar 1/0 explícito evita el 422.
            const datos = { activa: activa ? 1 : 0 };
            if (confirmado) {
                datos.confirmado = true;
            }

            $checkbox.prop('disabled', true);

            $.ajax({
                url: rutas.base + '/' + id + '/estado',
                method: 'PATCH',
                dataType: 'json',
                data: datos,
            }).done(function (resp) {
                actualizarLabel($checkbox, resp.funcion.activa);
                toast('success', resp.mensaje);
            }).fail(function (xhr) {
                const resp = xhr.responseJSON || {};

                if (xhr.status === 409 && resp.requiere_confirmacion) {
                    pendienteConfirmacion = $checkbox;
                    $('#modal-confirmar-desactivar-ml-mensaje').text(resp.mensaje);
                    modalConfirmar ? modalConfirmar.show() : $modalConfirmar.show();
                    return;
                }

                $checkbox.prop('checked', !activa);
                toast('error', resp.mensaje || 'No se pudo actualizar la función.');
            }).always(function () {
                $checkbox.prop('disabled', false);
            });
        }

        $lista.on('change', '.js-funcion-toggle', function () {
            enviarToggle($(this), false);
        });

        $('#btn-confirmar-desactivar-ml').on('click', function () {
            modalConfirmar ? modalConfirmar.hide() : $modalConfirmar.hide();
            if (pendienteConfirmacion) {
                enviarToggle(pendienteConfirmacion, true);
                pendienteConfirmacion = null;
            }
        });

        $modalConfirmar.on('hidden.bs.modal', function () {
            if (pendienteConfirmacion) {
                pendienteConfirmacion.prop('checked', true);
                pendienteConfirmacion = null;
            }
        });
    });
})();
