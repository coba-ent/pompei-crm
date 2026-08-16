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

    window.RangoEmision = { opciones, mesActual, etiqueta, MESES };
})();
