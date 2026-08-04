/**
 * Configuración & Ajustes (spec 043, US2): muestra/oculta los tabs de Depósitos, Mercado Libre,
 * Tiendanube y Facturación Electrónica sin recargar la página, según el estado (activa/inactiva)
 * de su Función Avanzada asociada (FR-007a/FR-007b). El contenido de cada tab ya está en el DOM
 * (renderizado por el servidor); acá sólo se decide si el <li> del nav-tabs es visible.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[configuracion-tabs] jQuery no está disponible.');
        return;
    }

    const cfg = window.ConfiguracionTabsData || {};
    const claves = cfg.clavesFuncionConTab || [];

    $(function () {
        const $tabs = $('#configuracion-tabs');
        if (!$tabs.length) {
            return;
        }

        $(document).on('configuracion:funcion-actualizada', function (e, funcion) {
            if (!funcion || claves.indexOf(funcion.clave) === -1) {
                return;
            }

            const $li = $tabs.find('[data-tab-clave="' + funcion.clave + '"]');
            const $pane = $('[data-tab-pane-clave="' + funcion.clave + '"]');
            if (!$li.length) {
                return;
            }

            if (funcion.activa) {
                $li.removeClass('d-none');
            } else {
                const eraActivo = $pane.hasClass('active') || $pane.hasClass('show');
                $li.addClass('d-none');
                if (eraActivo) {
                    const tabFunciones = window.bootstrap
                        ? window.bootstrap.Tab.getOrCreateInstance(document.getElementById('tab-funciones-btn'))
                        : null;
                    if (tabFunciones) {
                        tabFunciones.show();
                    } else {
                        $('#tab-funciones-btn').tab('show');
                    }
                }
            }
        });
    });
})();
