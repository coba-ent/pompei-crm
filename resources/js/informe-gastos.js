/**
 * Informe de Gastos (spec 067, US2) — árbol Categoría → Subcategoría desplegable.
 *
 * La pantalla calca la de Contagram: tarjetas por categoría, subcategorías colapsadas que
 * muestran sólo su total y se abren para ver el detalle.
 *
 * Los subtotales **no** se calculan sobre lo que hay dibujado: vienen del endpoint `stats`,
 * que agrupa sobre todo el conjunto filtrado (FR-023). Por eso el árbol puede dibujarse
 * completo y con totales correctos antes de haber traído una sola fila de detalle: el detalle
 * de cada subcategoría se pide a `grupo` recién cuando el usuario la despliega.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[informe-gastos] jQuery no está disponible.');
        return;
    }

    const cfg = window.InformeGastosConfig || {};
    const rutas = cfg.rutas || {};

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true, progressBar: true, positionClass: 'toast-top-right',
            preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
        };
    }

    function avisar(mensaje, tipo) {
        if (window.toastr) { window.toastr[tipo || 'error'](mensaje); } else { console.error(mensaje); }
    }

    function mensajeDe(xhr, porDefecto) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || porDefecto;
    }

    $(function () {
        const $arbol = $('#gastos-arbol');
        if (!$arbol.length) { return; }

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }

        const money = (v) => (v === null || v === undefined || v === '')
            ? ''
            : new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
        const pesos = (v) => '$ ' + money(v);
        // Las fechas extremas son el rango abierto del backend (X / "Borrar filtro"): RangoFechas.php.
        const fecha = (v) => (!v ? '' : (['1900-01-01', '2999-12-31'].includes(String(v).slice(0, 10)) ? 'Sin límite' : String(v).slice(0, 10).split('-').reverse().join('/')));
        const esc = (v) => $('<div/>').text(v === null || v === undefined ? '' : v).html();

        initSelect2($('#filtro-categoria'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-subcategoria'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-cuenta'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-usuario'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-estado-pago'), { placeholder: 'Todos', allowClear: true });

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
                recargar();
            });
            $rango.on('cancel.daterangepicker', function () {
                // La X / "Borrar filtro" vacía el rango (antes volvía a "Mes actual" y parecía
                // que no borraba). Las fechas viajan vacías y el backend lo toma como sin
                // límite (RangoFechas.php); "Limpiar" sigue restableciendo el mes actual.
                fechaDesde = ''; fechaHasta = '';
                $(this).val('');
                recargar();
            });
            $('#btn-limpiar-rango-emision').on('click', function () {
                $rango.trigger('cancel.daterangepicker');
            });
        }

        function filtros() {
            return {
                fecha_desde: fechaDesde,
                fecha_hasta: fechaHasta,
                categoria_id: $('#filtro-categoria').val(),
                subcategoria_id: $('#filtro-subcategoria').val(),
                cuenta_tesoreria_id: $('#filtro-cuenta').val(),
                usuario_id: $('#filtro-usuario').val(),
                estado_pago: $('#filtro-estado-pago').val(),
            };
        }

        /* --------------------------------------------------------------- Render */

        function htmlSubcategoria(categoria, sub) {
            return '' +
                '<div class="gasto-sub" data-categoria="' + esc(categoria) + '" data-subcategoria="' + esc(sub.subcategoria) + '">' +
                    '<button type="button" class="gasto-sub__head" aria-expanded="false">' +
                        '<i class="fas fa-caret-right gasto-sub__chevron"></i>' +
                        '<span class="gasto-sub__nombre">' + esc(sub.subcategoria) + '</span>' +
                        '<span class="gasto-sub__rotulo">Total:</span>' +
                        '<span class="gasto-sub__monto">' + pesos(sub.subtotal) + '</span>' +
                    '</button>' +
                    '<div class="gasto-sub__body" hidden></div>' +
                '</div>';
        }

        function htmlCategoria(grupo) {
            return '' +
                '<section class="gasto-cat">' +
                    '<header class="gasto-cat__head">' + esc(grupo.categoria) + '</header>' +
                    '<div class="gasto-cat__subs">' +
                        (grupo.subcategorias || []).map((s) => htmlSubcategoria(grupo.categoria, s)).join('') +
                    '</div>' +
                    '<footer class="gasto-cat__foot">' +
                        '<span class="gasto-cat__foot-rotulo">Total ' + esc(grupo.categoria) + ':</span>' +
                        '<span class="gasto-cat__foot-monto">' + pesos(grupo.subtotal) + '</span>' +
                    '</footer>' +
                '</section>';
        }

        function htmlFilas(filas) {
            if (!filas.length) {
                return '<p class="gasto-sub__vacio">Sin movimientos.</p>';
            }

            const cuerpo = filas.map((f) => '' +
                '<tr>' +
                    '<td class="gasto-detalle__id">' + esc(f.id) + '</td>' +
                    '<td>' + fecha(f.fecha) + '</td>' +
                    '<td>' + esc(f.descripcion) + '</td>' +
                    '<td>' + esc(f.medio_pago) + '</td>' +
                    '<td class="text-end">' +
                        (Number(f.pendiente) ? '<span class="badge bg-warning text-dark me-2">Pendiente</span>' : '') +
                        pesos(f.total) +
                    '</td>' +
                '</tr>').join('');

            return '' +
                '<div class="table-responsive">' +
                    '<table class="table table-sm table-hover gasto-detalle mb-0">' +
                        '<thead><tr>' +
                            '<th>Id</th><th>Fecha</th><th>Descripción</th><th>Medio de Pago</th><th class="text-end">Total</th>' +
                        '</tr></thead>' +
                        '<tbody>' + cuerpo + '</tbody>' +
                    '</table>' +
                '</div>';
        }

        /* ----------------------------------------------------------- Despliegue */

        /**
         * Abre o cierra una subcategoría. La primera apertura trae el detalle por AJAX y lo
         * deja cacheado en el DOM: reabrirla no vuelve a pegarle al servidor.
         */
        function alternar($sub, abrir) {
            const $head = $sub.children('.gasto-sub__head');
            const $body = $sub.children('.gasto-sub__body');
            const abierto = $sub.hasClass('gasto-sub--abierta');
            const destino = abrir === undefined ? !abierto : !!abrir;

            if (destino === abierto) { return; }

            $sub.toggleClass('gasto-sub--abierta', destino);
            $head.attr('aria-expanded', destino ? 'true' : 'false');
            $body.prop('hidden', !destino);

            if (!destino || $sub.data('cargado')) { return; }

            $body.html('<p class="gasto-sub__cargando"><i class="fas fa-circle-notch fa-spin me-1"></i> Cargando...</p>');

            $.getJSON(rutas.grupo, $.extend(filtros(), {
                categoria: $sub.data('categoria'),
                subcategoria: $sub.data('subcategoria'),
            }))
                .done(function (r) {
                    $sub.data('cargado', true);
                    $body.html(htmlFilas(r.filas || []));
                })
                .fail(function (xhr) {
                    $body.html('<p class="gasto-sub__vacio text-danger">No se pudo cargar el detalle.</p>');
                    avisar(mensajeDe(xhr, 'No se pudo cargar el detalle de la subcategoría.'));
                });
        }

        $arbol.on('click', '.gasto-sub__head', function () {
            alternar($(this).closest('.gasto-sub'));
        });

        $('#btn-expandir-todo').on('click', function () {
            $arbol.find('.gasto-sub').each(function () { alternar($(this), true); });
        });

        $('#btn-colapsar-todo').on('click', function () {
            $arbol.find('.gasto-sub').each(function () { alternar($(this), false); });
        });

        /* ---------------------------------------------------------------- Carga */

        function pintar(s) {
            $('#resumen-desde').text(fecha(s.fecha_desde));
            $('#resumen-hasta').text(fecha(s.fecha_hasta));
            $('#resumen-total').text(pesos(s.gasto_total));

            const grupos = s.grupos || [];

            $arbol.html(grupos.length
                ? grupos.map(htmlCategoria).join('')
                : '<div class="card"><div class="card-body text-center text-muted py-5">' +
                  'No hay gastos en el período seleccionado.</div></div>');
        }

        function recargar() {
            $arbol.html('<div class="card"><div class="card-body text-center text-muted py-5">' +
                '<i class="fas fa-circle-notch fa-spin me-1"></i> Cargando...</div></div>');

            $.getJSON(rutas.stats, filtros())
                .done(pintar)
                .fail(function (xhr) {
                    $arbol.html('');
                    avisar(mensajeDe(xhr, 'No se pudo cargar el informe.'));
                });
        }

        recargar();

        $('#btn-aplicar-filtros').on('click', recargar);
        $('#btn-limpiar-filtros').on('click', function () {
            $('#filtro-categoria, #filtro-subcategoria, #filtro-cuenta, #filtro-usuario, #filtro-estado-pago')
                .val(null).trigger('change.select2');
            recargar();
        });

        const query = () => $.param(filtros(), true);

        $('#btn-exportar').on('click', function () {
            window.location.assign(rutas.exportar + '?' + query());
        });

        $('#btn-exportar-pdf').on('click', function () {
            const url = rutas.pdf + '?' + query();
            if (window.AppPdf) {
                window.AppPdf.abrir(url, 'Informe de Gastos');
            } else {
                window.open(url, '_blank');
            }
        });
    });
})();
