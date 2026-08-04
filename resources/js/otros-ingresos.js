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
    function refreshSelect2($el) {
        if (hasSelect2 && $el && $el.length && $el.hasClass('select2-hidden-accessible')) {
            $el.trigger('change.select2');
        }
    }
    function money(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    $(function () {
        const $tabla = $('#tabla-otros-ingresos');
        if (!$tabla.length) { return; }

        // ---- Categoría de Ingreso (catálogo con Select2 + crear/renombrar/eliminar) ----
        let categorias = (cfg.categorias || []).slice();
        const $categoriaSel = $('#ingreso-categoria');
        let categoriaPrevia = '';

        function actualizarBotonesCategoria() {
            const val = $categoriaSel.val();
            const cat = categorias.find((c) => String(c.id) === String(val));
            const real = !!val && val !== '__nuevo__' && !(cat && cat.es_sistema);
            $('#btn-renombrar-categoria-ingreso, #btn-eliminar-categoria-ingreso').toggleClass('d-none', !real);
        }

        function llenarCategorias(seleccion) {
            const sel = seleccion ? String(seleccion) : '';
            $categoriaSel.empty();
            $categoriaSel.append(new Option('', '', false, !sel));
            $categoriaSel.append(new Option('＋ Crear Categoría de Ingreso', '__nuevo__', false, false));
            categorias.forEach((c) => $categoriaSel.append(new Option(c.nombre, c.id, false, String(c.id) === sel)));
            refreshSelect2($categoriaSel);
            categoriaPrevia = sel;
            actualizarBotonesCategoria();
        }
        function llenarCuentas(seleccion) {
            const $sel = $('#ingreso-cuenta').empty();
            $sel.append(new Option('', ''));
            (cfg.cuentas || []).forEach((c) => $sel.append(new Option(c.nombre, c.id, false, c.id === seleccion)));
        }

        initSelect2($categoriaSel, { placeholder: 'Seleccioná una Categoría' });
        initSelect2($('#ingreso-cuenta'), { placeholder: 'Seleccioná un Medio de Cobro', allowClear: true });
        llenarCategorias(null);
        llenarCuentas(null);

        $categoriaSel.on('change', function () {
            const val = $(this).val();
            if (val === '__nuevo__') {
                $(this).val(categoriaPrevia).trigger('change.select2');
                abrirModalCategoriaIngreso('crear', '', '');
            } else {
                categoriaPrevia = val || '';
                actualizarBotonesCategoria();
            }
        });

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
                { data: 'estado_badge', name: 'estado_badge', orderable: false, searchable: false },
                { data: 'id', name: 'id' },
                { data: 'fecha', name: 'fecha' },
                { data: 'categoria', name: 'categoria.nombre' },
                { data: 'descripcion', name: 'descripcion' },
                { data: 'medio_de_cobro', name: 'medio_de_cobro' },
                { data: 'monto', name: 'monto', render: money },
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
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

        // Modal crear/renombrar Categoría de Ingreso.
        const $modalCategoriaNombre = $('#modal-nueva-categoria-ingreso');
        let modoCategoriaIngreso = 'crear';
        let idCategoriaIngresoEditar = null;

        function abrirModalCategoriaIngreso(modo, id, nombreActual) {
            modoCategoriaIngreso = modo;
            idCategoriaIngresoEditar = id || null;
            $('#nueva-categoria-ingreso-nombre').val(nombreActual || '').removeClass('is-invalid');
            $('#nueva-categoria-ingreso-error').text('');
            $('#modal-nueva-categoria-ingreso-titulo').text(modo === 'renombrar' ? 'Renombrar Categoría de Ingreso' : 'Crear Categoría de Ingreso');
            $('#btn-crear-categoria-ingreso').text(modo === 'renombrar' ? 'Guardar' : 'Crear');
            bootstrap.Modal.getOrCreateInstance($modalCategoriaNombre[0]).show();
            setTimeout(() => $('#nueva-categoria-ingreso-nombre').trigger('focus'), 300);
        }

        $('#btn-renombrar-categoria-ingreso').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categorias.find((x) => String(x.id) === String(id));
            abrirModalCategoriaIngreso('renombrar', id, c ? c.nombre : '');
        });

        $('#btn-crear-categoria-ingreso').on('click', function () {
            const nombre = $('#nueva-categoria-ingreso-nombre').val().trim();
            $('#nueva-categoria-ingreso-nombre').removeClass('is-invalid');
            $('#nueva-categoria-ingreso-error').text('');
            if (!nombre) {
                $('#nueva-categoria-ingreso-nombre').addClass('is-invalid');
                $('#nueva-categoria-ingreso-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = modoCategoriaIngreso === 'renombrar';
            const url = esRenombrar ? rutas.categoriaUpdateBase + '/' + idCategoriaIngresoEditar : rutas.categoriaIngresoStore;
            const datos = esRenombrar ? { _method: 'PATCH', nombre } : { nombre };

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const c = categorias.find((x) => String(x.id) === String(idCategoriaIngresoEditar));
                        if (c) { c.nombre = resp.categoria.nombre; }
                        categorias.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        llenarCategorias(idCategoriaIngresoEditar);
                    } else {
                        categorias.push({ id: resp.categoria.id, nombre: resp.categoria.nombre, es_sistema: false });
                        categorias.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        llenarCategorias(resp.categoria.id);
                    }
                    bootstrap.Modal.getInstance($modalCategoriaNombre[0])?.hide();
                    toast('success', resp.mensaje || 'Categoría guardada.');
                })
                .fail((xhr) => {
                    const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo guardar la categoría.';
                    $('#nueva-categoria-ingreso-nombre').addClass('is-invalid');
                    $('#nueva-categoria-ingreso-error').text(msg);
                });
        });

        // Eliminar Categoría de Ingreso (modal de confirmación).
        const $modalCategoriaEliminar = $('#modal-categoria-ingreso-eliminar');
        let idCategoriaIngresoAEliminar = null;

        $('#btn-eliminar-categoria-ingreso').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categorias.find((x) => String(x.id) === String(id));
            idCategoriaIngresoAEliminar = id;
            $('#categoria-ingreso-eliminar-nombre').text(c ? c.nombre : '');
            bootstrap.Modal.getOrCreateInstance($modalCategoriaEliminar[0]).show();
        });

        $('#btn-confirmar-eliminar-categoria-ingreso').on('click', function () {
            if (!idCategoriaIngresoAEliminar) { return; }
            const id = idCategoriaIngresoAEliminar;
            $.post(rutas.categoriaDestroyBase + '/' + id, { _method: 'DELETE' })
                .done((resp) => {
                    categorias = categorias.filter((x) => String(x.id) !== String(id));
                    llenarCategorias('');
                    toast('success', resp.mensaje || 'Categoría eliminada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar la categoría.'))
                .always(() => {
                    bootstrap.Modal.getInstance($modalCategoriaEliminar[0])?.hide();
                    idCategoriaIngresoAEliminar = null;
                });
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

            window.AppBtn.loading('#btn-guardar-ingreso', true);
            $.ajax({ url, method, data: payload })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Ingreso guardado.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-ingreso'))?.hide();
                    tabla.ajax.reload(null, false);
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors)[0][0] : 'No se pudo guardar el ingreso.'))
                .always(() => window.AppBtn.loading('#btn-guardar-ingreso', false));
        });

        let idAEliminar = null;
        $(document).on('click', '.js-eliminar', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-ingreso')).show();
        });
        $('#btn-confirmar-eliminar').on('click', function () {
            if (!idAEliminar) { return; }
            window.AppBtn.loading('#btn-confirmar-eliminar', true);
            $.ajax({ url: rutas.updateBase + '/' + idAEliminar, method: 'DELETE' })
                .done(() => {
                    toast('success', 'Ingreso eliminado.');
                    tabla.ajax.reload(null, false);
                    bootstrap.Modal.getInstance(document.getElementById('modal-eliminar-ingreso'))?.hide();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar.'))
                .always(() => window.AppBtn.loading('#btn-confirmar-eliminar', false));
        });
    });
})();
