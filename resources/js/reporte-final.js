/**
 * Reporte Final (spec 068, US3).
 *
 * Dos vistas del mismo período —devengado ("Ventas Vs. Compras") y caja ("Cobros Vs Pagos")—
 * sobre un árbol agregado que llega entero del servidor en una sola llamada.
 *
 * El **simulador "Activo"** recalcula subtotales, Total Ingresos, Total Egresos y Resultado
 * **en el cliente y sin ninguna petición de red** (FR-034, SC-005): tener el árbol completo en
 * memoria es exactamente la razón por la que esta pantalla no usa DataTables server-side.
 * Destildar una categoría no altera ningún dato: sólo cambia qué se suma.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[reporte-final] jQuery no está disponible.');
        return;
    }

    const cfg = window.ReporteFinalConfig || {};
    const rutas = cfg.rutas || {};

    const BANNERS = {
        devengado: 'Contempla: Ventas por Categoría (incluye Notas de Crédito y de Débito), Otros Ingresos por Categoría, '
            + 'Compras por Categoría (incluye Notas de Crédito y de Débito) y Gastos por Categoría y Subcategoría '
            + '(incluye los Pendientes).',
        caja: 'Contempla: Ventas Cobradas por Categoría, Otros Ingresos (cobros), Compras Pagadas por Categoría y '
            + 'Gastos pagados. Los Gastos Pendientes NO se contemplan, por no implicar una salida real de dinero.',
    };

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true, progressBar: true, positionClass: 'toast-top-right',
            preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
        };
    }

    function avisar(mensaje, tipo) {
        if (window.toastr) { window.toastr[tipo || 'error'](mensaje); } else { console.error(mensaje); }
    }

    const money = (v) => new Intl.NumberFormat('es-AR', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }).format(v || 0);
    const fecha = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '');
    const escapar = (t) => $('<div>').text(t === null || t === undefined ? '' : t).html();

    $(function () {
        const $cuerpo = $('#cuerpo-reporte-final');
        if (!$cuerpo.length) { return; }

        let vista = 'devengado';
        let arbol = null;
        // Claves de categoría destildadas. Es TODO el estado del simulador: vive sólo acá.
        const excluidas = new Set();

        // --- Rango de Emisión: las 9 opciones compartidas, arrancando en el mes actual (FR-002) ---
        const inicial = window.RangoEmision.mesActual();
        let fechaDesde = inicial.desde;
        let fechaHasta = inicial.hasta;

        const $rango = $('#filtro-rango-emision');
        $rango.val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));

        if ($.fn.daterangepicker) {
            $rango.daterangepicker(window.RangoEmision.opciones({
                startDate: window.moment(fechaDesde), endDate: window.moment(fechaHasta),
            }));
            $rango.on('apply.daterangepicker', function (e, picker) {
                fechaDesde = picker.startDate.format('YYYY-MM-DD');
                fechaHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));
                cargar();
            });
            $rango.on('cancel.daterangepicker', function () {
                const m = window.RangoEmision.mesActual();
                fechaDesde = m.desde; fechaHasta = m.hasta;
                $(this).val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));
                cargar();
            });
            $('#btn-limpiar-rango-emision').on('click', function () {
                $rango.trigger('cancel.daterangepicker');
            });
        }

        const parametros = () => ({ vista: vista, desde: fechaDesde, hasta: fechaHasta });

        function cargar() {
            $('#banner-texto').text(BANNERS[vista]);

            $.getJSON(rutas.data, parametros())
                .done(function (data) {
                    arbol = data;
                    // Cambiar de vista o de rango arranca un escenario nuevo: arrastrar las
                    // exclusiones de la vista anterior mostraría totales que no se corresponden
                    // con ninguna categoría visible.
                    excluidas.clear();
                    render();
                })
                .fail(function (xhr) {
                    arbol = null;
                    $cuerpo.html('<tr><td colspan="3" class="text-center text-muted">No se pudo cargar el reporte.</td></tr>');
                    avisar((xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo cargar el reporte.');
                });
        }

        /** Fila de nodo, con sangría creciente por nivel. */
        function fila(etiqueta, monto, nivel, clases) {
            return '<tr class="' + (clases || '') + '">'
                + '<td></td>'
                + '<td style="padding-left:' + (12 + nivel * 22) + 'px">' + escapar(etiqueta) + '</td>'
                + '<td class="text-end">$ ' + money(monto) + '</td>'
                + '</tr>';
        }

        function render() {
            if (!arbol) { return; }

            $('#cab-desde').text(fecha(arbol.desde));
            $('#cab-hasta').text(fecha(arbol.hasta));

            let html = '';
            let vacio = true;

            (arbol.bloques || []).forEach(function (bloque) {
                const categorias = bloque.categorias || [];
                if (!categorias.length) { return; }

                vacio = false;
                html += '<tr class="table-light fw-bold"><td></td><td>' + escapar(bloque.etiqueta)
                    + '</td><td class="text-end" data-subtotal="' + bloque.clave + '"></td></tr>';

                categorias.forEach(function (categoria) {
                    const marcada = !excluidas.has(categoria.clave);

                    html += '<tr>'
                        + '<td class="text-center"><input type="checkbox" class="form-check-input chk-activo" '
                        + 'data-clave="' + escapar(categoria.clave) + '"' + (marcada ? ' checked' : '') + '></td>'
                        + '<td style="padding-left:34px">' + escapar(categoria.etiqueta) + '</td>'
                        + '<td class="text-end' + (marcada ? '' : ' text-muted text-decoration-line-through')
                        + '">$ ' + money(categoria.monto) + '</td>'
                        + '</tr>';

                    (categoria.hijos || []).forEach(function (hijo) {
                        html += fila(hijo.etiqueta, hijo.monto, 2, marcada ? 'text-muted' : 'text-muted opacity-50');

                        (hijo.hijos || []).forEach(function (nieto) {
                            html += fila(nieto.etiqueta, nieto.monto, 3, marcada ? 'text-muted' : 'text-muted opacity-50');
                        });
                    });
                });

                html += '<tr class="fw-bold"><td></td><td class="text-end">Total ' + escapar(bloque.etiqueta)
                    + '</td><td class="text-end" data-subtotal-valor="' + bloque.clave + '"></td></tr>';
            });

            $cuerpo.html(vacio
                ? '<tr><td colspan="3" class="text-center text-muted">No hay movimientos en el período seleccionado.</td></tr>'
                : html);

            recalcular();
        }

        /**
         * El corazón del simulador: recorre el árbol que ya está en memoria y rehace subtotales,
         * totales y resultado. Cero red, cero espera (SC-005).
         */
        function recalcular() {
            if (!arbol) { return; }

            let ingresos = 0;
            let egresos = 0;

            (arbol.bloques || []).forEach(function (bloque) {
                let subtotal = 0;

                (bloque.categorias || []).forEach(function (categoria) {
                    if (excluidas.has(categoria.clave)) { return; }
                    subtotal += categoria.monto;
                });

                subtotal = Math.round(subtotal * 100) / 100;
                $('[data-subtotal-valor="' + bloque.clave + '"]').text('$ ' + money(subtotal));

                if (bloque.naturaleza === 'ingreso') { ingresos += subtotal; } else { egresos += subtotal; }
            });

            ingresos = Math.round(ingresos * 100) / 100;
            egresos = Math.round(egresos * 100) / 100;

            $('#cab-ingresos').text('$ ' + money(ingresos));
            // En pantalla los egresos van SIEMPRE en positivo y el resultado resta, en las dos
            // vistas (FR-035). El doble estándar de signos de Contagram vive sólo en el Excel.
            $('#cab-egresos').text('$ ' + money(egresos));
            $('#cab-resultado').text('$ ' + money(Math.round((ingresos - egresos) * 100) / 100));
        }

        $cuerpo.on('change', '.chk-activo', function () {
            const clave = $(this).data('clave');
            const $monto = $(this).closest('tr').find('td').last();

            if (this.checked) { excluidas.delete(clave); } else { excluidas.add(clave); }

            $monto.toggleClass('text-muted text-decoration-line-through', !this.checked);
            $(this).closest('tr').nextUntil('tr:has(.chk-activo)')
                .filter('.text-muted').toggleClass('opacity-50', !this.checked);

            recalcular();
        });

        $('#vistas-reporte-final button').on('click', function () {
            if ($(this).hasClass('active')) { return; }

            $('#vistas-reporte-final button').removeClass('active');
            $(this).addClass('active');
            vista = $(this).data('vista');
            // Cambiar de pestaña no recarga la página: sólo vuelve a pedir el árbol (FR-004).
            cargar();
        });

        // --- Exportación: siempre la vista activa y el escenario simulado (FR-006) ---
        function query() {
            const params = parametros();
            params.excluidas = Array.from(excluidas);

            return $.param(params, true);
        }

        $('#btn-exportar').on('click', function () {
            window.location.assign(rutas.exportar + '?' + query());
        });

        $('#btn-exportar-pdf').on('click', function () {
            const url = rutas.pdf + '?' + query();
            if (window.AppPdf) {
                window.AppPdf.abrir(url, 'Reporte Final');
            } else {
                window.open(url, '_blank');
            }
        });

        cargar();
    });
})();
