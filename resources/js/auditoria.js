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
