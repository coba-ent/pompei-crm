/**
 * Helper compartido del selector de rango "Emisión" (spec 067, T002).
 *
 * Las 9 opciones del relevamiento de Contagram —Hoy, Ayer, Última Semana, Mes actual,
 * Mes anterior, Últimos 30 días, Año actual, "Desde - Hasta" (rango personalizado con los
 * dos calendarios contiguos) y "Borrar filtro" (el botón de cancelar del picker)— estaban
 * copiadas y pegadas en `compras.js`, `auditoria.js` e `informe-cuenta-corriente.js`. Con
 * tres pantallas nuevas más serían seis copias de la misma lista, así que vive acá.
 *
 * Se expone en `window.RangoEmision` (y no como import ESM) porque los bundles de las
 * pantallas se cargan sueltos por Vite y `moment` / `daterangepicker` los provee el
 * template NexaDash globalmente por pagelevel, no el bundle.
 */
(function () {
    'use strict';

    const MESES = [
        'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];

    /**
     * Opciones de bootstrap-daterangepicker con los 7 presets nombrados.
     *
     * `customRangeLabel: 'Desde - Hasta'` es la octava opción (rango tipeable con dos
     * calendarios) y `cancelLabel: 'Borrar filtro'` la novena: el picker las renderiza
     * como parte de su UI, no como entradas de `ranges`.
     *
     * @param {object} [extra] overrides puntuales (p. ej. `startDate`/`endDate` iniciales).
     */
    function opciones(extra) {
        const moment = window.moment;
        const hoy = moment();

        const base = {
            autoUpdateInput: false,
            opens: 'left',
            locale: {
                format: 'DD/MM/YYYY',
                applyLabel: 'Aplicar',
                cancelLabel: 'Borrar filtro',
                fromLabel: 'Desde',
                toLabel: 'Hasta',
                customRangeLabel: 'Desde - Hasta',
                daysOfWeek: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa'],
                monthNames: MESES.slice(),
            },
            ranges: {
                Hoy: [hoy.clone(), hoy.clone()],
                Ayer: [hoy.clone().subtract(1, 'day'), hoy.clone().subtract(1, 'day')],
                'Última Semana': [hoy.clone().subtract(6, 'days'), hoy.clone()],
                'Mes actual': [hoy.clone().startOf('month'), hoy.clone().endOf('month')],
                'Mes anterior': [hoy.clone().subtract(1, 'month').startOf('month'), hoy.clone().subtract(1, 'month').endOf('month')],
                'Últimos 30 días': [hoy.clone().subtract(29, 'days'), hoy.clone()],
                'Año actual': [hoy.clone().startOf('year'), hoy.clone().endOf('year')],
            },
        };

        return Object.assign(base, extra || {});
    }

    /** Rango inicial por defecto de los informes: Mes actual (FR-004b). */
    function mesActual() {
        const hoy = window.moment();

        return {
            desde: hoy.clone().startOf('month').format('YYYY-MM-DD'),
            hasta: hoy.clone().endOf('month').format('YYYY-MM-DD'),
        };
    }

    /** "01/08/2026 - 31/08/2026" a partir de dos fechas ISO. */
    function etiqueta(desde, hasta) {
        const fmt = (d) => String(d).slice(0, 10).split('-').reverse().join('/');

        return desde && hasta ? fmt(desde) + ' - ' + fmt(hasta) : '';
    }

    /**
     * Apaga el autocompletado del navegador en los inputs de rango.
     *
     * Son `<input type="text">` con `name`/`id` estables, así que Chrome les guarda el historial
     * de lo tipeado y al enfocarlos dibuja su lista de sugerencias **encima** del desplegable del
     * picker, tapando los presets (Hoy, Mes actual, Desde - Hasta…). Es UI del navegador: no se
     * puede cerrar ni bajar de z-index desde la página, sólo evitar que aparezca.
     *
     * `autocomplete="off"` solo no alcanza — Chrome lo ignora en campos que reconoce como
     * conocidos —, así que se acompaña con un `autocomplete` de valor no estándar (el navegador
     * no encuentra heurística que aplicar) y se apagan corrector y capitalización, que en estos
     * campos tampoco tienen sentido.
     */
    function sinAutocompletado(el) {
        const input = el instanceof Element ? el : document.querySelector(el);

        if (!input) {
            return;
        }

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('autocorrect', 'off');
        input.setAttribute('autocapitalize', 'off');
        input.setAttribute('spellcheck', 'false');
    }

    // Todo input que monte un daterangepicker queda cubierto sin tocar cada pantalla: el plugin
    // dispara `show.daterangepicker` sobre el input antes de abrir el desplegable.
    if (window.jQuery) {
        window.jQuery(document).on('show.daterangepicker', function (e) {
            sinAutocompletado(e.target);
        });
    }

    window.RangoEmision = { opciones, mesActual, etiqueta, sinAutocompletado, MESES };
})();
