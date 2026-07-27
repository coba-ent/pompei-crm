/**
 * Módulo Inicio (Dashboard) — KPIs/totales del mes actual (US1), gráfico mensual
 * de 12 meses (US2), selector de período + donas por categoría (US5) y
 * rankings de Clientes/Productos (US6). Tesorería y Cuentas a Cobrar/Pagar se
 * hidratan server-side y no se re-piden por AJAX (research.md §3).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[dashboard] jQuery no está disponible.');
        return;
    }

    const cfg = window.DashboardConfig || {};
    const rutas = cfg.rutas || {};
    let periodoActual = cfg.periodoInicial || 'mes_actual';

    function fmtMoney(n) {
        return '$ ' + new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
    }

    function fmtNumero(n) {
        return new Intl.NumberFormat('es-AR').format(n || 0);
    }

    function renderVariacion($el, variacionPct) {
        if (variacionPct === null || variacionPct === undefined) {
            $el.removeClass('text-success text-danger').addClass('text-muted').text('sin datos previos');
            return;
        }
        const positivo = variacionPct >= 0;
        $el.removeClass('text-muted text-success text-danger')
            .addClass(positivo ? 'text-success' : 'text-danger')
            .html((positivo ? '▲ ' : '▼ ') + Math.abs(variacionPct).toFixed(1) + '%');
    }

    // ============================================================
    // KPIs (US1)
    // ============================================================
    function cargarKpis(periodo) {
        if (!rutas.kpis) { return; }
        $.getJSON(rutas.kpis, { periodo: periodo }).done(function (data) {
            ['ventas_creadas', 'venta_promedio', 'cantidad_ventas', 'resultado'].forEach(function (clave) {
                const item = data[clave] || { valor: 0, variacion_pct: null };
                const $valor = $('[data-kpi-valor="' + clave + '"]');
                $valor.text(clave === 'cantidad_ventas' ? fmtNumero(item.valor) : fmtMoney(item.valor));
                renderVariacion($('[data-kpi-variacion="' + clave + '"]'), item.variacion_pct);
            });
        });
    }

    // ============================================================
    // Totales (US1)
    // ============================================================
    function cargarTotales(periodo) {
        if (!rutas.totales) { return; }
        $.getJSON(rutas.totales, { periodo: periodo }).done(function (data) {
            const total = (data.ventas || 0) + (data.otros_ingresos || 0) + (data.compras || 0) + (data.gastos || 0);
            ['ventas', 'otros_ingresos', 'compras', 'gastos'].forEach(function (clave) {
                const monto = data[clave] || 0;
                $('[data-total-monto="' + clave + '"]').text(fmtMoney(monto));
                const pct = total > 0 ? (monto / total) * 100 : 0;
                $('[data-total-barra="' + clave + '"]').css('width', pct + '%');
            });
        });
    }

    // ============================================================
    // Gráfico mensual (US2) — fijo, no depende del período
    // ============================================================
    let graficoMensual = null;
    function cargarGraficoMensual() {
        if (!rutas.graficoMensual || !$('#grafico-mensual').length || typeof ApexCharts === 'undefined') { return; }
        $.getJSON(rutas.graficoMensual).done(function (data) {
            const opciones = {
                chart: { type: 'bar', height: 320, stacked: true, toolbar: { show: false } },
                series: [
                    { name: 'Ventas', data: data.series.ventas },
                    { name: 'Otros Ingresos', data: data.series.otros_ingresos },
                    { name: 'Compras', data: data.series.compras },
                    { name: 'Gastos', data: data.series.gastos },
                ],
                xaxis: { categories: data.labels },
                legend: { position: 'top' },
            };
            if (graficoMensual) {
                graficoMensual.updateOptions(opciones);
            } else {
                graficoMensual = new ApexCharts(document.querySelector('#grafico-mensual'), opciones);
                graficoMensual.render();
            }
        });
    }

    // ============================================================
    // Donas por categoría (US5)
    // ============================================================
    const donas = {};
    function renderDona(idDiv, key, filas) {
        if (!$('#' + idDiv).length || typeof ApexCharts === 'undefined') { return; }
        const opciones = {
            chart: { type: 'donut', height: 260 },
            series: filas.map(function (f) { return f.monto; }),
            labels: filas.map(function (f) { return f.categoria; }),
            legend: { position: 'bottom' },
        };
        if (donas[key]) {
            donas[key].updateOptions(opciones);
        } else {
            donas[key] = new ApexCharts(document.querySelector('#' + idDiv), opciones);
            donas[key].render();
        }
    }

    function cargarDonas(periodo) {
        if (!rutas.donas) { return; }
        $.getJSON(rutas.donas, { periodo: periodo }).done(function (data) {
            renderDona('dona-ventas', 'ventas', data.ventas || []);
            renderDona('dona-compras', 'compras', data.compras || []);
            renderDona('dona-gastos', 'gastos', data.gastos || []);
        });
    }

    // ============================================================
    // Rankings de Clientes / Productos (US6)
    // ============================================================
    function cargarRankings(periodo) {
        if (!rutas.rankings) { return; }
        $.getJSON(rutas.rankings, { periodo: periodo }).done(function (data) {
            const $clientes = $('#ranking-clientes').empty();
            (data.clientes || []).forEach(function (c) {
                $clientes.append($('<tr>').append($('<td>').text(c.nombre), $('<td>').addClass('text-end').text(fmtMoney(c.monto))));
            });
            if (!(data.clientes || []).length) {
                $clientes.append('<tr><td class="text-center text-muted">Sin datos.</td></tr>');
            }

            const $productos = $('#ranking-productos').empty();
            (data.productos || []).forEach(function (p) {
                $productos.append($('<tr>').append($('<td>').text(p.nombre), $('<td>').addClass('text-end').text(fmtNumero(p.cantidad))));
            });
            if (!(data.productos || []).length) {
                $productos.append('<tr><td class="text-center text-muted">Sin datos.</td></tr>');
            }
        });
    }

    // ============================================================
    // Selector de período (US5, FR-008): recalcula todo salvo
    // Tesorería y Cuentas a Cobrar/Pagar (hidratadas server-side).
    // ============================================================
    function cargarPorPeriodo(periodo) {
        cargarKpis(periodo);
        cargarTotales(periodo);
        cargarDonas(periodo);
        cargarRankings(periodo);
    }

    $(function () {
        cargarPorPeriodo(periodoActual);
        cargarGraficoMensual();

        $('#dashboard-periodo').on('click', 'button[data-periodo]', function () {
            const periodo = $(this).data('periodo');
            if (periodo === periodoActual) { return; }
            periodoActual = periodo;
            $('#dashboard-periodo button').removeClass('active');
            $(this).addClass('active');
            cargarPorPeriodo(periodoActual);
        });
    });
})();
