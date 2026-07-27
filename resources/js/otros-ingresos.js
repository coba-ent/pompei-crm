/**
 * Módulo Otros Ingresos (US3) — el más simple de los tres: listado (7
 * columnas) + modal AJAX de alta/edición. Mismo patrón que productos.js.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[otros-ingresos] jQuery no está disponible.');
        return;
    }

    const cfg = window.OtrosIngresosConfig || {};
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
            console.log('[otros-ingresos][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default', dropdownParent: $('#modal-ingreso') }, opts || {}));
    }
    function money(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    $(function () {
        const $tabla = $('#tabla-otros-ingresos');
        if (!$tabla.length) { return; }

        function llenarCategorias(seleccion) {
            const $sel = $('#ingreso-categoria').empty();
            $sel.append(new Option('', ''));
            (cfg.categorias || []).forEach((c) => $sel.append(new Option(c.nombre, c.id, false, c.id === seleccion)));
        }
        function llenarCuentas(seleccion) {
            const $sel = $('#ingreso-cuenta').empty();
            $sel.append(new Option('', ''));
            (cfg.cuentas || []).forEach((c) => $sel.append(new Option(c.nombre, c.id, false, c.id === seleccion)));
        }

        initSelect2($('#ingreso-categoria'), { placeholder: 'Seleccioná una Categoría' });
        initSelect2($('#ingreso-cuenta'), { placeholder: 'Seleccioná un Medio de Cobro', allowClear: true });
        llenarCategorias(null);
        llenarCuentas(null);

        function filtrosActuales() {
            return { buscar: $('#filtro-buscar').val() };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true, responsive: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ ingresos', infoEmpty: 'Sin ingresos',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron ingresos',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data, data: (d) => $.extend(d, filtrosActuales()) },
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
                { data: 'id', name: 'id' },
                { data: 'fecha', name: 'fecha' },
                { data: 'categoria', name: 'categoria.nombre' },
                { data: 'descripcion', name: 'descripcion' },
                { data: 'medio_de_cobro', name: 'medio_de_cobro' },
                { data: 'monto', name: 'monto', render: money },
            ],
        });

        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => { $('#filtro-buscar').val(''); tabla.ajax.reload(); });

        function togglePendiente() {
            const pendiente = $('#ingreso-pendiente').is(':checked');
            $('#ingreso-cuenta-wrapper').toggle(!pendiente);
        }
        $('#ingreso-pendiente').on('change', togglePendiente);

        function abrirModal(row) {
            $('#ingreso-id').val(row ? row.id : '');
            $('#modal-ingreso-titulo').text(row ? 'Editar Ingreso' : 'Nuevo Ingreso');
            $('#btn-guardar-ingreso').text(row ? 'Guardar' : 'Crear');
            $('#ingreso-fecha').val(row ? row.fecha_raw : new Date().toISOString().slice(0, 10));
            $('#ingreso-monto').val(row ? row.monto : '');
            $('#ingreso-descripcion').val(row ? row.descripcion : '');
            $('#ingreso-pendiente').prop('checked', row ? !!row.pendiente : false);
            llenarCategorias(row ? row.categoria_id : null);
            llenarCuentas(row ? row.cuenta_tesoreria_id : null);
            togglePendiente();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ingreso')).show();
        }

        $('#btn-nuevo-ingreso').on('click', () => abrirModal(null));

        $(document).on('click', '.js-editar, .js-ver', function (e) {
            e.preventDefault();
            const tr = $(this).closest('tr');
            const row = tabla.row(tr).data();
            abrirModal(row);
        });

        $('#btn-nueva-categoria-ingreso').on('click', function (e) {
            e.preventDefault();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria-ingreso')).show();
        });
        $('#btn-crear-categoria-ingreso').on('click', function () {
            const nombre = $('#nueva-categoria-ingreso-nombre').val();
            if (!nombre) { return; }
            $.post(rutas.categoriaIngresoStore, { nombre })
                .done((resp) => {
                    cfg.categorias.push({ id: resp.categoria.id, nombre: resp.categoria.nombre });
                    llenarCategorias(resp.categoria.id);
                    $('#nueva-categoria-ingreso-nombre').val('');
                    bootstrap.Modal.getInstance(document.getElementById('modal-nueva-categoria-ingreso'))?.hide();
                    toast('success', 'Categoría creada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo crear la categoría.'));
        });

        $('#btn-guardar-ingreso').on('click', function () {
            const id = $('#ingreso-id').val();
            const payload = {
                fecha: $('#ingreso-fecha').val(),
                monto: $('#ingreso-monto').val(),
                categoria_id: $('#ingreso-categoria').val(),
                cuenta_tesoreria_id: $('#ingreso-pendiente').is(':checked') ? null : $('#ingreso-cuenta').val(),
                descripcion: $('#ingreso-descripcion').val(),
                pendiente: $('#ingreso-pendiente').is(':checked') ? 1 : 0,
            };

            const url = id ? rutas.updateBase + '/' + id : rutas.store;
            const method = id ? 'PUT' : 'POST';

            $.ajax({ url, method, data: payload })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Ingreso guardado.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-ingreso'))?.hide();
                    tabla.ajax.reload(null, false);
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors)[0][0] : 'No se pudo guardar el ingreso.'));
        });

        let idAEliminar = null;
        $(document).on('click', '.js-eliminar', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-ingreso')).show();
        });
        $('#btn-confirmar-eliminar').on('click', function () {
            if (!idAEliminar) { return; }
            $.ajax({ url: rutas.updateBase + '/' + idAEliminar, method: 'DELETE' })
                .done(() => {
                    toast('success', 'Ingreso eliminado.');
                    tabla.ajax.reload(null, false);
                    bootstrap.Modal.getInstance(document.getElementById('modal-eliminar-ingreso'))?.hide();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar.'));
        });
    });
})();
