/**
 * Módulo Auditoría (spec 054) — pantalla de solo lectura: DataTable server-side +
 * filtros (Id, Operación, Usuario, rango de fecha) + exportación. Sin altas/ediciones.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[auditoria] jQuery no está disponible.');
        return;
    }

    const cfg = window.AuditoriaConfig || {};
    const rutas = cfg.rutas || {};

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true, progressBar: true, positionClass: 'toast-top-right',
            preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
        };
    }
    function toast(tipo, mensaje, titulo) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje, titulo || '');
        } else {
            console.log('[auditoria][' + tipo + ']', mensaje);
        }
    }

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
    }
    function money(v) {
        if (v === null || v === undefined || v === '') { return ''; }
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    $(function () {
        const $tabla = $('#tabla-auditoria');
        if (!$tabla.length) { return; }

        initSelect2($('#filtro-operacion'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-usuario'), { placeholder: 'Todos', allowClear: true });

        let fechaDesde = cfg.hoy;
        let fechaHasta = cfg.hoy;

        function opcionesRango() {
            const hoy = moment();

            return {
                autoUpdateInput: false,
                opens: 'left',
                startDate: hoy.clone(),
                endDate: hoy.clone(),
                locale: {
                    format: 'DD/MM/YYYY', applyLabel: 'Aplicar', cancelLabel: 'Borrar filtro',
                    fromLabel: 'Desde', toLabel: 'Hasta', customRangeLabel: 'Desde - Hasta',
                    daysOfWeek: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa'],
                    monthNames: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
                },
                ranges: {
                    Hoy: [hoy.clone(), hoy.clone()],
                    Ayer: [hoy.clone().subtract(1, 'day'), hoy.clone().subtract(1, 'day')],
                    'Última Semana': [hoy.clone().subtract(6, 'days'), hoy.clone()],
                    'Mes actual': [hoy.clone().startOf('month'), hoy.clone().endOf('month')],
                    'Mes anterior': [hoy.clone().subtract(1, 'month').startOf('month'), hoy.clone().subtract(1, 'month').endOf('month')],
                    'Últimos 30 días': [hoy.clone().subtract(29, 'days'), hoy.clone()],
                },
            };
        }

        if ($.fn.daterangepicker) {
            $('#filtro-rango-fecha').daterangepicker(opcionesRango());
            $('#filtro-rango-fecha').val('Hoy');
            $('#filtro-rango-fecha').on('apply.daterangepicker', function (e, picker) {
                fechaDesde = picker.startDate.format('YYYY-MM-DD');
                fechaHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
                tabla.ajax.reload();
            });
            $('#filtro-rango-fecha').on('cancel.daterangepicker', function () {
                fechaDesde = cfg.hoy; fechaHasta = cfg.hoy;
                $(this).val('Hoy');
                tabla.ajax.reload();
            });
        }

        function filtrosActuales() {
            return {
                id: $('#filtro-id').val(),
                operacion: $('#filtro-operacion').val(),
                usuario_id: $('#filtro-usuario').val(),
                fecha_desde: fechaDesde,
                fecha_hasta: fechaHasta,
            };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true,
            order: [[0, 'desc']],
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ operaciones', infoEmpty: 'Sin operaciones',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron operaciones',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data, data: (d) => $.extend(d, filtrosActuales()) },
            columns: [
                { data: 'id', name: 'id' },
                { data: 'fecha_hora', name: 'created_at', orderable: false },
                { data: 'usuario_nombre', name: 'usuario_nombre' },
                { data: 'tipo_accion_label', name: 'tipo_accion', orderable: false },
                { data: 'tipo_operacion_label', name: 'tipo_operacion', orderable: false },
                { data: 'detalle', name: 'detalle', orderable: false },
                { data: 'total', name: 'total', render: money },
                {
                    data: 'id', name: 'ver', orderable: false, searchable: false,
                    render: (id) => '<button type="button" class="btn btn-sm btn-outline-primary js-ver-detalle" data-id="' + id + '"><i class="fas fa-eye"></i> Ver</button>',
                },
            ],
            stateSave: true,
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                },
            ],
        });

        $tabla.one('init.dt', function () {
            tabla.buttons().container().appendTo('#dt-buttons-auditoria');
        });

        const $modal = $('#modal-detalle-auditoria');
        const $modalBody = $('#modal-detalle-auditoria-body');

        function filaStock(datos) {
            const signo = (datos.tipo || '').toLowerCase().includes('salida') || Number(datos.cantidad) < 0 ? '-' : '+';
            return '' +
                '<dl class="row mb-0">' +
                '<dt class="col-sm-4">Producto</dt><dd class="col-sm-8">' + datos.producto + '</dd>' +
                '<dt class="col-sm-4">Depósito</dt><dd class="col-sm-8">' + datos.deposito + '</dd>' +
                '<dt class="col-sm-4">Tipo</dt><dd class="col-sm-8">' + datos.tipo + '</dd>' +
                '<dt class="col-sm-4">Cantidad</dt><dd class="col-sm-8">' + signo + Math.abs(Number(datos.cantidad)) + '</dd>' +
                (datos.descripcion ? '<dt class="col-sm-4">Descripción</dt><dd class="col-sm-8">' + datos.descripcion + '</dd>' : '') +
                '<dt class="col-sm-4">Fecha</dt><dd class="col-sm-8">' + (datos.fecha || '') + '</dd>' +
                '</dl>';
        }

        function filaTesoreria(datos) {
            let html = '<dl class="row mb-0">';
            if (datos.es_transferencia && datos.transferencia) {
                html += '<dt class="col-sm-4">Movimiento</dt><dd class="col-sm-8">Transferencia entre cajas</dd>';
                html += '<dt class="col-sm-4">De</dt><dd class="col-sm-8">' + datos.transferencia.caja_origen + '</dd>';
                html += '<dt class="col-sm-4">A</dt><dd class="col-sm-8">' + datos.transferencia.caja_destino + '</dd>';
                html += '<dt class="col-sm-4">Monto</dt><dd class="col-sm-8">' + money(datos.transferencia.monto) + '</dd>';
            } else {
                html += '<dt class="col-sm-4">Caja</dt><dd class="col-sm-8">' + datos.cuenta + '</dd>';
                html += '<dt class="col-sm-4">Tipo</dt><dd class="col-sm-8">' + datos.tipo + '</dd>';
                html += '<dt class="col-sm-4">Monto</dt><dd class="col-sm-8">' + (Number(datos.monto) < 0 ? '- ' : '+ ') + money(Math.abs(Number(datos.monto))) + '</dd>';
            }
            if (datos.concepto) { html += '<dt class="col-sm-4">Concepto</dt><dd class="col-sm-8">' + datos.concepto + '</dd>'; }
            if (datos.observacion) { html += '<dt class="col-sm-4">Observación</dt><dd class="col-sm-8">' + datos.observacion + '</dd>'; }
            html += '<dt class="col-sm-4">Fecha</dt><dd class="col-sm-8">' + (datos.fecha || '') + '</dd>';
            html += '</dl>';
            return html;
        }

        $tabla.on('click', '.js-ver-detalle', function () {
            const id = $(this).data('id');
            $modalBody.html('<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></div>');
            $modal.modal('show');

            $.get(rutas.detalle.replace('__ID__', id)).done(function (resp) {
                const log = resp.log;
                let html = '' +
                    '<dl class="row mb-3">' +
                    '<dt class="col-sm-4">Fecha y Hora</dt><dd class="col-sm-8">' + log.fecha_hora + '</dd>' +
                    '<dt class="col-sm-4">Usuario</dt><dd class="col-sm-8">' + log.usuario + '</dd>' +
                    '<dt class="col-sm-4">Acción</dt><dd class="col-sm-8">' + log.accion + '</dd>' +
                    '<dt class="col-sm-4">Operación</dt><dd class="col-sm-8">' + log.operacion + '</dd>' +
                    '</dl><hr>';

                if (resp.tipo === 'stock' && resp.datos) {
                    html += filaStock(resp.datos);
                } else if (resp.tipo === 'tesoreria' && resp.datos) {
                    html += filaTesoreria(resp.datos);
                } else {
                    html += '<p class="mb-0 text-muted">' + (log.detalle || 'Sin detalle adicional para esta operación.') + '</p>';
                }

                $modalBody.html(html);
            }).fail(function () {
                $modalBody.html('<p class="text-danger mb-0">No se pudo cargar el detalle.</p>');
            });
        });

        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => {
            $('#filtro-id').val('');
            $('#filtro-operacion').val('').trigger('change.select2');
            $('#filtro-usuario').val('').trigger('change.select2');
            fechaDesde = cfg.hoy; fechaHasta = cfg.hoy;
            $('#filtro-rango-fecha').val('Hoy');
            tabla.ajax.reload();
        });

        $('#btn-exportar-auditoria').on('click', function () {
            $.ajax({
                url: rutas.exportar,
                method: 'GET',
                data: filtrosActuales(),
                xhrFields: { responseType: 'blob' },
            }).done(function (blob, status, xhr) {
                const contentType = xhr.getResponseHeader('Content-Type') || '';
                if (contentType.indexOf('json') !== -1) {
                    const reader = new FileReader();
                    reader.onload = function () {
                        let mensaje = 'No hay datos para exportar con el filtro aplicado.';
                        try { mensaje = JSON.parse(reader.result).mensaje || mensaje; } catch (e) { /* usa default */ }
                        toast('error', mensaje);
                    };
                    reader.readAsText(blob);
                    return;
                }
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'auditoria_' + moment().format('YYYY-MM-DD_HHmmss') + '.csv';
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(url);
            }).fail(function (xhr) {
                let mensaje = 'No se pudo exportar.';
                if (xhr.status === 422) {
                    try { mensaje = JSON.parse(xhr.responseText).mensaje || mensaje; } catch (e) { /* usa default */ }
                }
                toast('error', mensaje);
            });
        });
    });
})();
