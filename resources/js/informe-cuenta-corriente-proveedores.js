/**
 * Informe de Cuenta Corriente Proveedores (spec 067, US3) — espejo del de Clientes:
 * dos DataTables server-side (Saldos / Movimientos) sobre un único shell con tabs.
 *
 * Pantalla de **sólo lectura**: el clic en el nombre abre una ficha sin campos editables, y no
 * hay ninguna llamada de escritura en este archivo (FR-037).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[informe-cuenta-corriente-proveedores] jQuery no está disponible.');
        return;
    }

    const cfg = window.InformeCuentaCorrienteProveedoresConfig || {};
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

    $(function () {
        const $tablaSaldos = $('#tabla-saldos-proveedores');
        if (!$tablaSaldos.length) { return; }

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }

        const money = (v) => new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v || 0);
        const moneyOpcional = (v) => (v === null || v === undefined ? '' : money(v));
        const fecha = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '');

        function proveedorPicker(placeholder) {
            return {
                placeholder: placeholder || 'Todos',
                allowClear: true,
                minimumInputLength: 0,
                ajax: {
                    url: rutas.proveedoresOpciones, dataType: 'json', delay: 250,
                    data: (params) => ({ q: params.term || '' }),
                    processResults: (data) => ({
                        results: (data.data || []).map((p) => ({ id: p.id, text: p.nombre })),
                    }),
                },
            };
        }

        // ---------------------------------------------------------------- Saldos Proveedores
        initSelect2($('#filtro-saldos-proveedor'), proveedorPicker());

        const tablaSaldos = $tablaSaldos.DataTable({
            processing: true,
            serverSide: true,
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ proveedores',
                infoEmpty: 'Sin proveedores con saldo pendiente',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron proveedores con saldo pendiente',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: {
                url: rutas.saldosData,
                data: function (d) { d.proveedor_id = $('#filtro-saldos-proveedor').val(); },
                error: function () { avisar('No se pudieron cargar los saldos.'); },
            },
            columns: [
                {
                    data: 'proveedor_nombre', name: 'proveedor_nombre',
                    render: function (val, type, row) {
                        if (type !== 'display') { return val; }
                        return '<a href="#" class="link-ficha-proveedor" data-proveedor-id="' + row.proveedor_id + '">' + $('<div/>').text(val || '').html() + '</a>';
                    },
                },
                { data: 'a_vencer', name: 'a_vencer', className: 'text-end', render: money },
                { data: 'vencido_0_30', name: 'vencido_0_30', className: 'text-end', render: money },
                { data: 'vencido_31_60', name: 'vencido_31_60', className: 'text-end', render: money },
                { data: 'vencido_61_90', name: 'vencido_61_90', className: 'text-end', render: money },
                { data: 'vencido_mas_90', name: 'vencido_mas_90', className: 'text-end', render: money },
                {
                    data: 'total', name: 'total', className: 'text-end fw-bold',
                    render: function (val, type, row) {
                        if (type !== 'display') { return val; }
                        return '<a href="#" class="link-saldo-total text-reset" data-proveedor-id="' + row.proveedor_id + '">' + money(val) + '</a>';
                    },
                },
            ],
            order: [[6, 'desc']],
            stateSave: true,
            buttons: [
                { extend: 'colvis', text: '<i class="fas fa-table-columns"></i>', className: 'btn btn-outline-secondary' },
            ],
        });

        $tablaSaldos.one('init.dt', function () {
            tablaSaldos.buttons().container().appendTo('#dt-buttons-saldos-proveedores');
        });

        $('#filtro-saldos-proveedor').on('select2:select select2:clear change', function () {
            tablaSaldos.ajax.reload();
        });

        // ---------------------------------------------------------------------- Movimientos
        const $tablaMovimientos = $('#tabla-movimientos');
        initSelect2($('#filtro-movimientos-proveedor'), proveedorPicker());
        initSelect2($('#filtro-movimientos-operacion'));

        // Rango inicial = Mes actual (FR-004b), igual que los otros dos informes de la tanda.
        const inicial = window.RangoEmision.mesActual();
        let fechaDesde = inicial.desde;
        let fechaHasta = inicial.hasta;

        const $rango = $('#filtro-movimientos-rango-fechas');
        $rango.val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));

        if ($.fn.daterangepicker) {
            $rango.daterangepicker(window.RangoEmision.opciones({
                startDate: window.moment(fechaDesde), endDate: window.moment(fechaHasta),
            }));
            $rango.on('apply.daterangepicker', function (e, picker) {
                fechaDesde = picker.startDate.format('YYYY-MM-DD');
                fechaHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));
                tablaMovimientos.ajax.reload();
            });
            $rango.on('cancel.daterangepicker', function () {
                fechaDesde = ''; fechaHasta = '';
                $(this).val('');
                tablaMovimientos.ajax.reload();
            });
        }

        const ETIQUETAS_OPERACION = {
            compra: 'Compra',
            pago: 'Pago',
            nota_credito: 'Nota de Crédito',
            nota_debito: 'Nota de Débito',
            saldo_inicial: 'Saldo Inicial',
        };

        const tablaMovimientos = $tablaMovimientos.DataTable({
            processing: true,
            serverSide: true,
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ movimientos',
                infoEmpty: 'Sin movimientos',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron movimientos',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: {
                url: rutas.movimientosData,
                data: function (d) {
                    d.proveedor_id = $('#filtro-movimientos-proveedor').val();
                    d.operacion = $('#filtro-movimientos-operacion').val();
                    d.fecha_desde = fechaDesde;
                    d.fecha_hasta = fechaHasta;
                },
                error: function () { avisar('No se pudieron cargar los movimientos.'); },
            },
            columns: [
                { data: 'id', name: 'mov.id' },
                { data: 'fecha_emision', name: 'mov.fecha_emision', render: fecha },
                // Columna técnica: alimenta el deep-link, no se muestra ni se ofrece en colvis.
                { data: 'proveedor_id', name: 'mov.proveedor_id', visible: false },
                { data: 'operacion', name: 'mov.operacion', render: (v) => ETIQUETAS_OPERACION[v] || v },
                { data: 'categoria', name: 'mov.categoria', defaultContent: '' },
                { data: 'total_compra', name: 'mov.total_compra', className: 'text-end', render: moneyOpcional },
                { data: 'pagado', name: 'mov.pagado', className: 'text-end', render: moneyOpcional },
                { data: 'a_pagar', name: 'mov.a_pagar', className: 'text-end', render: moneyOpcional },
                { data: 'nro_comprobante', name: 'mov.nro_comprobante', defaultContent: '' },
                { data: 'medio_pago', name: 'mov.medio_pago', defaultContent: '' },
                { data: 'descripcion', name: 'mov.descripcion', defaultContent: '' },
            ],
            order: [[1, 'desc']],
            stateSave: true,
            buttons: [
                {
                    extend: 'colvis', text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                    columns: function (idx) { return idx !== 2; },
                },
            ],
        });

        $tablaMovimientos.one('init.dt', function () {
            tablaMovimientos.buttons().container().appendTo('#dt-buttons-movimientos');
        });

        $('#filtro-movimientos-proveedor, #filtro-movimientos-operacion').on('select2:select select2:clear change', function () {
            tablaMovimientos.ajax.reload();
        });

        // Clic en el Total: pasar al tab Movimientos de ese proveedor. Se cambia de tab por JS
        // en vez de navegar, para no perder el estado de la pantalla (FR-008).
        $tablaSaldos.on('click', '.link-saldo-total', function (e) {
            e.preventDefault();
            const id = $(this).data('proveedor-id');
            if (!id) { return; }

            const $opcion = $('#filtro-movimientos-proveedor');
            if (!$opcion.find('option[value="' + id + '"]').length) {
                const nombre = $(this).closest('tr').find('.link-ficha-proveedor').text();
                $opcion.append(new Option(nombre, id, true, true));
            }
            $opcion.val(id).trigger('change');
            $('#tab-movimientos-btn').tab('show');
        });

        // -------------------------------------------------------------- Ficha (sólo lectura)
        $tablaSaldos.on('click', '.link-ficha-proveedor', function (e) {
            e.preventDefault();
            const id = $(this).data('proveedor-id');
            if (!id) { return; }

            $.getJSON(rutas.fichaProveedor.replace('__ID__', id))
                .done(function (ficha) {
                    $('#ficha-titulo').text(ficha.proveedor || 'Proveedor');
                    $('#modal-ficha-proveedor [data-ficha]').each(function () {
                        const valor = ficha[$(this).data('ficha')];
                        $(this).text(valor === null || valor === undefined || valor === '' ? '—' : valor);
                    });
                    window.bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ficha-proveedor')).show();
                })
                .fail(function (xhr) {
                    avisar(xhr.status === 404 ? 'El proveedor no existe.' : 'No se pudo cargar la ficha del proveedor.');
                });
        });

        // Las tablas de un tab oculto calculan anchos contra un contenedor de ancho 0.
        $('#tab-saldos-proveedores-btn').on('shown.bs.tab', function () { tablaSaldos.columns.adjust(); });
        $('#tab-movimientos-btn').on('shown.bs.tab', function () { tablaMovimientos.columns.adjust(); });

        // Deep-link `?proveedor_id=`: el <option selected> ya viene renderizado del servidor,
        // sólo hace falta que Select2 lo refleje.
        if (cfg.proveedorId) {
            $('#filtro-movimientos-proveedor').trigger('change.select2');
        }

        // ------------------------------------------------------------------------ Exportar
        function filtros() {
            return {
                proveedor_id: $('#filtro-movimientos-proveedor').val() || $('#filtro-saldos-proveedor').val(),
                operacion: $('#filtro-movimientos-operacion').val(),
                fecha_desde: fechaDesde,
                fecha_hasta: fechaHasta,
            };
        }

        $('#btn-exportar').on('click', function () {
            window.location.assign(rutas.exportar + '?' + $.param(filtros(), true));
        });

        $('#btn-exportar-pdf').on('click', function () {
            const url = rutas.pdf + '?' + $.param(filtros(), true);
            if (window.AppPdf) {
                window.AppPdf.abrir(url, 'Cuenta Corriente Proveedores');
            } else {
                window.open(url, '_blank');
            }
        });

        $('#btn-exportar-movimientos').on('click', function () {
            window.location.assign(rutas.exportarMovimientos + '?' + $.param(filtros(), true));
        });

        $('#btn-exportar-movimientos-pdf').on('click', function () {
            const url = rutas.pdfMovimientos + '?' + $.param(filtros(), true);
            if (window.AppPdf) {
                window.AppPdf.abrir(url, 'Movimientos de Proveedores');
            } else {
                window.open(url, '_blank');
            }
        });
    });
})();
