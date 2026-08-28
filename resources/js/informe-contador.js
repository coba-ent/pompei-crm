/**
 * "Información para tu Contador" — Libro IVA Ventas / Compras (spec 077).
 *
 * Dos pestañas con estado 100% independiente en el cliente (período, filtros, columnas visibles
 * — FR-030): todo lo que sigue está parametrizado por `pestana` ('ventas' | 'compras') y nunca
 * comparte una variable mutable entre las dos. Todo por AJAX, `data`/`stats` van por POST
 * (research §D9): nunca se arma la URL con los filtros como querystring.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[informe-contador] jQuery no está disponible.');
        return;
    }

    const cfg = window.InformeContadorConfig || {};
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

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    if (CSRF) { $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } }); }

    $(function () {
        const money = (v) => (v === null || v === undefined || v === '')
            ? '$ 0,00'
            : '$ ' + new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
        const fecha = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '');
        const moneyContable = (v) => {
            if (v === null || v === undefined || v === '') { return money(0); }
            const numero = Number(v);
            const formateado = money(Math.abs(numero));

            return numero < 0 ? '<span class="text-danger">(' + formateado + ')</span>' : formateado;
        };

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }

        const COLUMNAS = [
            { data: 'id', name: 'id' },
            { data: 'emision', name: 'emision', render: fecha },
            { data: 'tipo', name: 'tipo', defaultContent: '' },
            { data: 'nro_comprobante', name: 'nro_comprobante', defaultContent: '' },
            { data: 'contraparte', name: 'contraparte', defaultContent: '' },
            { data: 'cuit', name: 'cuit', defaultContent: '' },
            { data: 'condicion_iva', name: 'condicion_iva', defaultContent: '' },
            { data: 'neto_no_gravado', name: 'neto_no_gravado', className: 'text-end', render: moneyContable },
            { data: 'neto_exento', name: 'neto_exento', className: 'text-end', render: moneyContable },
            { data: 'neto_gravado', name: 'neto_gravado', className: 'text-end', render: moneyContable },
            { data: 'iva_2_5', name: 'iva_2_5', className: 'text-end', render: moneyContable },
            { data: 'iva_5', name: 'iva_5', className: 'text-end', render: moneyContable },
            { data: 'iva_10_5', name: 'iva_10_5', className: 'text-end', render: moneyContable },
            { data: 'iva_21', name: 'iva_21', className: 'text-end', render: moneyContable },
            { data: 'iva_27', name: 'iva_27', className: 'text-end', render: moneyContable },
            { data: 'perc_iva', name: 'perc_iva', className: 'text-end', render: moneyContable },
            { data: 'perc_iibb', name: 'perc_iibb', className: 'text-end', render: moneyContable },
            { data: 'imp_internos', name: 'imp_internos', className: 'text-end', render: moneyContable },
            { data: 'imp_municipales', name: 'imp_municipales', className: 'text-end', render: moneyContable },
        ];

        // Estado por pestaña — objetos independientes, nunca compartidos (FR-030).
        const estados = {};

        ['ventas', 'compras'].forEach(function (pestana) {
            const $root = $('#tab-' + pestana);
            const $tabla = $root.find('.js-tabla');
            const $mensajeVacio = $('#mensaje-vacio-' + pestana);
            const rutasPestana = rutas[pestana] || {};

            // Select2 en los filtros dinámicos (CLAUDE.md #5).
            initSelect2($root.find('.js-f-tipo-comprobante'), { placeholder: 'Todos', allowClear: true });
            initSelect2($root.find('.js-f-condicion-iva'), { placeholder: 'Todas', allowClear: true });
            initSelect2($root.find('.js-f-cuenta-tesoreria'), { placeholder: 'Todos', allowClear: true });
            initSelect2($root.find('.js-f-provincia'), { placeholder: 'Todas', allowClear: true });

            if (hasSelect2 && rutasPestana.contraparte) {
                $root.find('.js-f-contraparte').select2({
                    width: '100%', theme: 'default', placeholder: 'Todos', allowClear: true, multiple: true,
                    ajax: {
                        url: rutasPestana.contraparte, delay: 250,
                        data: (params) => ({ q: params.term }),
                        processResults: (data) => ({ results: (data.data || []).map((c) => ({ id: c.id, text: c.nombre })) }),
                    },
                });
            }

            const estado = { mes: '', anio: '', tabla: null, tieneperiodo: false };
            estados[pestana] = estado;

            function filtros() {
                return {
                    mes: estado.mes,
                    anio: estado.anio,
                    arca: pestana === 'ventas' ? $root.find('.js-arca').is(':checked') : undefined,
                    manuales: pestana === 'ventas' ? $root.find('.js-manuales').is(':checked') : undefined,
                    id: $root.find('.js-f-id').val(),
                    tipo_comprobante: $root.find('.js-f-tipo-comprobante').val(),
                    nro_comprobante: $root.find('.js-f-nro-comprobante').val(),
                    [pestana === 'ventas' ? 'cliente_id' : 'proveedor_id']: $root.find('.js-f-contraparte').val(),
                    cuit: $root.find('.js-f-cuit').val(),
                    condicion_iva_id: $root.find('.js-f-condicion-iva').val(),
                    cuenta_tesoreria_id: $root.find('.js-f-cuenta-tesoreria').val(),
                    provincia: $root.find('.js-f-provincia').val(),
                };
            }

            function tieneperiodo() {
                return !!(estado.mes && estado.anio);
            }

            function mostrarVacio() {
                $tabla.closest('.table-responsive').hide();
                $mensajeVacio.show();
                $root.find('.js-tot-no-gravados, .js-tot-gravados, .js-tot-iva, .js-tot-perc, .js-tot-facturado').text(money(0));
            }

            function mostrarTabla() {
                $tabla.closest('.table-responsive').show();
                $mensajeVacio.hide();
            }

            function inicializarTabla() {
                if (estado.tabla) { return estado.tabla; }

                estado.tabla = $tabla.DataTable({
                    processing: true,
                    serverSide: true,
                    language: {
                        lengthMenu: 'Mostrar _MENU_ registros',
                        info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                        infoEmpty: 'Sin comprobantes en el período',
                        infoFiltered: '',
                        zeroRecords: 'No hay comprobantes en el período seleccionado',
                        paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                        processing: 'Cargando...',
                    },
                    ajax: {
                        url: rutasPestana.data,
                        type: 'POST',
                        data: (d) => $.extend(d, filtros()),
                        error: function (xhr) {
                            avisar((xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo cargar el informe.');
                        },
                    },
                    columns: COLUMNAS,
                    order: [[1, 'asc']],
                    // stateSave: persiste qué columnas quedaron ocultas (colvis T052) en localStorage.
                    // El id explícito en la tabla (tabla-informe-contador-{pestana}) evita que la
                    // clave de storage dependa del orden en que se inicializa cada pestaña.
                    stateSave: true,
                });

                actualizarLeyenda();

                return estado.tabla;
            }

            function actualizarTotales() {
                $.ajax({
                    url: rutasPestana.stats, type: 'POST', data: filtros(),
                })
                    .done(function (t) {
                        $root.find('.js-tot-no-gravados').text(money(t.no_gravados_exentos));
                        $root.find('.js-tot-gravados').text(money(t.gravados));
                        $root.find('.js-tot-iva').text(money(t.iva_total));
                        $root.find('.js-tot-perc').text(money(t.perc_iva_iibb_total));
                        $root.find('.js-tot-facturado').text(money(t.total_facturado));
                    })
                    .fail(function (xhr) {
                        avisar((xhr.responseJSON && xhr.responseJSON.message) || 'No se pudieron calcular los totales.');
                    });
            }

            function actualizarLeyenda() {
                const ahora = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                $('#leyenda-actualizado').text(
                    'Actualizado el ' + pad(ahora.getDate()) + '/' + pad(ahora.getMonth() + 1) + '/' + ahora.getFullYear() +
                    ' a las ' + pad(ahora.getHours()) + ':' + pad(ahora.getMinutes())
                );
            }

            function recargar() {
                if (!tieneperiodo()) {
                    mostrarVacio();

                    return;
                }

                mostrarTabla();
                inicializarTabla().ajax.reload(null, false);
                actualizarTotales();
                actualizarLeyenda();
            }

            // FR-006/FR-007: sin período elegido, no se dispara ninguna llamada.
            mostrarVacio();

            $root.find('.js-mes').on('change', function () { estado.mes = $(this).val(); recargar(); actualizarBotonIvaDigital(); });
            $root.find('.js-anio').on('change', function () { estado.anio = $(this).val(); recargar(); actualizarBotonIvaDigital(); });
            $root.find('.js-arca, .js-manuales').on('change', recargar);
            $root.find('.js-aplicar-filtros').on('click', recargar);
            $root.find('.js-limpiar-filtros').on('click', function () {
                $root.find('.js-f-id, .js-f-nro-comprobante, .js-f-cuit').val('');
                $root.find('.js-f-tipo-comprobante, .js-f-contraparte, .js-f-condicion-iva, .js-f-cuenta-tesoreria, .js-f-provincia')
                    .val(null).trigger('change.select2');
                recargar();
            });

            // T052: selector de columnas visibles — un dropdown liviano con un checkbox por
            // columna, sin depender de la extensión Buttons/colVis. Nunca altera los totales
            // (FR-025): sólo llama a `column().visible()`, no toca el request de `data`/`stats`.
            const $colvisBtn = $root.find('.js-colvis');

            $colvisBtn.on('click', function () {
                const tabla = inicializarTabla();
                let $menu = $root.find('.js-colvis-menu');

                if ($menu.length) {
                    $menu.remove();

                    return;
                }

                $menu = $('<div class="dropdown-menu p-2 show js-colvis-menu" style="position:absolute; z-index:1050; max-height:300px; overflow:auto;"></div>');

                tabla.columns().every(function (idx) {
                    const titulo = $(tabla.column(idx).header()).text();
                    const checked = tabla.column(idx).visible() ? 'checked' : '';
                    const $item = $('<div class="form-check"><input class="form-check-input" type="checkbox" ' + checked + ' data-col="' + idx + '"><label class="form-check-label">' + titulo + '</label></div>');
                    $menu.append($item);
                });

                $menu.on('change', 'input[type="checkbox"]', function () {
                    const idx = parseInt($(this).data('col'), 10);
                    tabla.column(idx).visible($(this).is(':checked'));
                });

                $colvisBtn.after($menu);

                $(document).one('click.colvis-cerrar', function (e) {
                    if (!$(e.target).closest('.js-colvis-menu, .js-colvis').length) {
                        $root.find('.js-colvis-menu').remove();
                    }
                });
            });
        });

        function pestanaActiva() {
            return $('#tabs-contador .nav-link.active').data('pestana') || 'ventas';
        }

        // US3: el botón de IVA Digital se habilita sólo con mes elegido — en cualquiera de las dos
        // pestañas, porque el ZIP siempre incluye Ventas y Compras juntos (FR-001).
        const $btnIvaDigital = $('#btn-iva-digital');

        function actualizarBotonIvaDigital() {
            const estado = estados[pestanaActiva()];
            const habilitado = !!(estado && estado.mes && estado.anio);
            $btnIvaDigital.prop('disabled', !habilitado);
        }

        $('#tabs-contador button[data-bs-toggle="tab"]').on('shown.bs.tab', actualizarBotonIvaDigital);

        $btnIvaDigital.on('click', function () {
            const estado = estados[pestanaActiva()];

            if (!estado || !estado.mes || !estado.anio) {
                avisar('Elegí un mes y un año para generar el IVA Digital.');

                return;
            }

            const params = { mes: estado.mes, anio: estado.anio };
            window.location.href = cfg.ivaDigital + '?' + $.param(params);
        });

        $('#btn-exportar').on('click', function () {
            const pestana = pestanaActiva();
            const estado = estados[pestana];

            if (!estado || !estado.mes || !estado.anio) {
                avisar('Elegí un mes y un año para generar el informe.');

                return;
            }

            const $root = $('#tab-' + pestana);
            const params = {
                mes: estado.mes,
                anio: estado.anio,
                arca: pestana === 'ventas' ? $root.find('.js-arca').is(':checked') : undefined,
                manuales: pestana === 'ventas' ? $root.find('.js-manuales').is(':checked') : undefined,
                id: $root.find('.js-f-id').val(),
                tipo_comprobante: $root.find('.js-f-tipo-comprobante').val(),
                nro_comprobante: $root.find('.js-f-nro-comprobante').val(),
                cliente_id: pestana === 'ventas' ? $root.find('.js-f-contraparte').val() : undefined,
                proveedor_id: pestana === 'compras' ? $root.find('.js-f-contraparte').val() : undefined,
                cuit: $root.find('.js-f-cuit').val(),
                condicion_iva_id: $root.find('.js-f-condicion-iva').val(),
                cuenta_tesoreria_id: $root.find('.js-f-cuenta-tesoreria').val(),
                provincia: $root.find('.js-f-provincia').val(),
            };

            const url = (rutas[pestana] || {}).exportar + '?' + $.param(params, true);
            window.location.href = url;
        });
    });
})();
