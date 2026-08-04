/**
 * Configuración & Ajustes → Ventas (spec 043, US3): guarda los defaults globales de "Crear
 * Venta" (Categoría/Vendedor/Lista de Precios/Tipo de Comprobante/Días de Vto. de Cobro) por
 * AJAX, sin recargar la página.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[configuracion-ventas] jQuery no está disponible.');
        return;
    }

    const cfg = window.ConfiguracionVentasConfig || {};
    const rutas = cfg.rutas || {};

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    function toast(tipo, mensaje) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje);
        }
    }

    $(function () {
        const $form = $('#form-configuracion-ventas');
        if (!$form.length) {
            return;
        }

        initSelect2($('#cv-categoria-id'));
        initSelect2($('#cv-vendedor-id'));
        initSelect2($('#cv-lista-precio-id'));
        initSelect2($('#cv-categoria-compra-id'));

        function initSelect2($el) {
            if (!$.fn.select2 || !$el.length) {
                return;
            }
            $el.select2({ width: '100%', dropdownParent: $el.closest('.tab-pane') });
        }

        $form.on('submit', function (e) {
            e.preventDefault();

            const datos = {
                categoria_id: $('#cv-categoria-id').val() || null,
                vendedor_id: $('#cv-vendedor-id').val() || null,
                lista_precio_id: $('#cv-lista-precio-id').val() || null,
                tipo_comprobante: $('#cv-tipo-comprobante').val() || null,
                dias_vto_cobro: $('#cv-dias-vto-cobro').val() !== '' ? $('#cv-dias-vto-cobro').val() : null,
                dias_validez_presupuesto: $('#cv-dias-validez-presupuesto').val() !== '' ? $('#cv-dias-validez-presupuesto').val() : null,
                categoria_compra_id: $('#cv-categoria-compra-id').val() || null,
                tipo_comprobante_compra: $('#cv-tipo-comprobante-compra').val() || null,
                dias_vto_pago_compra: $('#cv-dias-vto-pago-compra').val() !== '' ? $('#cv-dias-vto-pago-compra').val() : null,
            };

            const $btn = $('#btn-guardar-configuracion-ventas');
            $btn.prop('disabled', true);

            $.ajax({ url: rutas.guardar, method: 'PUT', dataType: 'json', data: datos })
                .done(function (resp) {
                    toast('success', resp.mensaje);
                })
                .fail(function (jqXHR) {
                    const resp = jqXHR.responseJSON || {};
                    if (jqXHR.status === 422) {
                        toast('error', 'Revisá los datos ingresados.');
                    } else {
                        toast('error', resp.mensaje || 'No se pudo guardar la configuración de Ventas.');
                    }
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    });
})();
