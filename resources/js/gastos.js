/**
 * Módulo Gastos (US3) — el primer documento del proyecto 100% modal (sin
 * página propia ni ficha de detalle). Categoría jerárquica (Categoría→
 * Subcategoría) propia, independiente del árbol de Categorías de Compras.
 * Mismo patrón AJAX que otros-ingresos.js (spec 008).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[gastos] jQuery no está disponible.');
        return;
    }

    const cfg = window.GastosConfig || {};
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
            console.log('[gastos][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default', dropdownParent: $('#modal-gasto') }, opts || {}));
    }
    function money(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    $(function () {
        const $tabla = $('#tabla-gastos');
        if (!$tabla.length) { return; }

        function categoriasJerarquia() {
            const todas = cfg.categorias || [];
            const raices = todas.filter((c) => !c.categoria_padre_id);
            return raices.map((raiz) => ({
                raiz,
                hijos: todas.filter((c) => c.categoria_padre_id === raiz.id),
            }));
        }

        function actualizarBotonesCategoriaGasto() {
            const val = $('#gasto-categoria').val();
            const cat = (cfg.categorias || []).find((c) => String(c.id) === String(val));
            const real = !!val && !(cat && cat.es_sistema);
            $('#btn-renombrar-categoria-gasto, #btn-eliminar-categoria-gasto').toggleClass('d-none', !real);
        }

        function llenarCategorias(seleccion) {
            const sel = seleccion ? String(seleccion) : '';
            const $sel = $('#gasto-categoria').empty();
            $sel.append(new Option('', ''));
            categoriasJerarquia().forEach(({ raiz, hijos }) => {
                if (!hijos.length) {
                    $sel.append(new Option(raiz.nombre, raiz.id, false, String(raiz.id) === sel));
                    return;
                }
                const $grupo = $('<optgroup>').attr('label', raiz.nombre);
                hijos.forEach((h) => $grupo.append(new Option(h.nombre, h.id, false, String(h.id) === sel)));
                $sel.append($grupo);
            });
            $sel.trigger('change.select2');
            actualizarBotonesCategoriaGasto();
        }
        function llenarCuentas(seleccion) {
            const $sel = $('#gasto-cuenta').empty();
            $sel.append(new Option('', ''));
            (cfg.cuentas || []).forEach((c) => $sel.append(new Option(c.nombre, c.id, false, c.id === seleccion)));
            $sel.trigger('change.select2');
        }

        initSelect2($('#gasto-categoria'), { placeholder: 'Seleccionar Categoría' });
        initSelect2($('#gasto-cuenta'), { placeholder: 'Seleccioná un Medio de Pago', allowClear: true });
        llenarCategorias(null);
        llenarCuentas(null);
        $('#gasto-categoria').on('change', actualizarBotonesCategoriaGasto);

        function filtrosActuales() {
            return { buscar: $('#filtro-buscar').val() };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ gastos', infoEmpty: 'Sin gastos',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron gastos',
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
                { data: 'medio_de_pago', name: 'medio_de_pago' },
                { data: 'monto', name: 'monto', render: money },
            ],
            stateSave: true,
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                    // Columna 0 es "Estado" (acciones), no se puede ocultar.
                    columns: function (idx) { return idx !== 0; },
                },
            ],
        });

        $tabla.one('init.dt', function () {
            tabla.buttons().container().appendTo('#dt-buttons-gastos');
        });

        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => { $('#filtro-buscar').val(''); tabla.ajax.reload(); });

        function togglePendiente() {
            const pendiente = $('#gasto-pendiente').is(':checked');
            $('#gasto-cuenta-wrapper').toggle(!pendiente);
        }
        $('#gasto-pendiente').on('change', togglePendiente);

        function abrirModal(row) {
            $('#gasto-id').val(row ? row.id : '');
            $('#modal-gasto-titulo').text(row ? 'Editar Gasto' : 'Nuevo Gasto');
            $('#btn-guardar-gasto').text(row ? 'Guardar' : 'Crear');
            $('#gasto-fecha').val(row ? row.fecha_raw : new Date().toISOString().slice(0, 10));
            $('#gasto-monto').val(row ? row.monto : '');
            $('#gasto-descripcion').val(row ? row.descripcion : '');
            $('#gasto-pendiente').prop('checked', row ? !!row.pendiente : false);
            llenarCategorias(row ? row.categoria_id : null);
            llenarCuentas(row ? row.cuenta_tesoreria_id : null);
            togglePendiente();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-gasto')).show();
        }

        $('#btn-nuevo-gasto').on('click', () => abrirModal(null));

        $(document).on('click', '.js-editar, .js-ver', function (e) {
            e.preventDefault();
            const tr = $(this).closest('tr');
            const row = tabla.row(tr).data();
            abrirModal(row);
        });

        let subcategoriaDe = null;
        let modoCategoriaGasto = 'crear';
        let idCategoriaGastoEditar = null;

        $('#btn-nueva-categoria-gasto').on('click', function (e) {
            e.preventDefault();
            subcategoriaDe = null;
            modoCategoriaGasto = 'crear';
            $('#modal-nueva-categoria-gasto-titulo').text('Crear Categoría de Gasto');
            $('#btn-crear-categoria-gasto').text('Crear');
            $('#nueva-categoria-gasto-nombre').val('').removeClass('is-invalid');
            $('#nueva-categoria-gasto-error').text('');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria-gasto')).show();
        });
        $('#btn-nueva-subcategoria-gasto').on('click', function (e) {
            e.preventDefault();
            subcategoriaDe = $('#gasto-categoria').val();
            if (!subcategoriaDe) { toast('error', 'Elegí primero una Categoría para agregarle una Subcategoría.'); return; }
            modoCategoriaGasto = 'crear';
            $('#modal-nueva-categoria-gasto-titulo').text('Crear Subcategoría');
            $('#btn-crear-categoria-gasto').text('Crear');
            $('#nueva-categoria-gasto-nombre').val('').removeClass('is-invalid');
            $('#nueva-categoria-gasto-error').text('');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria-gasto')).show();
        });
        $('#btn-renombrar-categoria-gasto').on('click', function (e) {
            e.preventDefault();
            const id = $('#gasto-categoria').val();
            if (!id) { return; }
            const c = (cfg.categorias || []).find((x) => String(x.id) === String(id));
            if (!c || c.es_sistema) { return; }
            modoCategoriaGasto = 'renombrar';
            idCategoriaGastoEditar = id;
            $('#modal-nueva-categoria-gasto-titulo').text('Renombrar Categoría');
            $('#btn-crear-categoria-gasto').text('Guardar');
            $('#nueva-categoria-gasto-nombre').val(c.nombre).removeClass('is-invalid');
            $('#nueva-categoria-gasto-error').text('');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria-gasto')).show();
        });
        $('#btn-crear-categoria-gasto').on('click', function () {
            const nombre = $('#nueva-categoria-gasto-nombre').val().trim();
            $('#nueva-categoria-gasto-nombre').removeClass('is-invalid');
            $('#nueva-categoria-gasto-error').text('');
            if (!nombre) {
                $('#nueva-categoria-gasto-nombre').addClass('is-invalid');
                $('#nueva-categoria-gasto-error').text('Ingresá un nombre.');
                return;
            }

            if (modoCategoriaGasto === 'renombrar') {
                $.post(rutas.categoriaUpdateBase + '/' + idCategoriaGastoEditar, { _method: 'PATCH', nombre })
                    .done((resp) => {
                        const c = (cfg.categorias || []).find((x) => String(x.id) === String(idCategoriaGastoEditar));
                        if (c) { c.nombre = resp.categoria.nombre; }
                        llenarCategorias(idCategoriaGastoEditar);
                        bootstrap.Modal.getInstance(document.getElementById('modal-nueva-categoria-gasto'))?.hide();
                        toast('success', resp.mensaje || 'Categoría renombrada.');
                    })
                    .fail((xhr) => {
                        const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo renombrar la categoría.';
                        $('#nueva-categoria-gasto-nombre').addClass('is-invalid');
                        $('#nueva-categoria-gasto-error').text(msg);
                    });
                return;
            }

            const url = subcategoriaDe
                ? rutas.categoriaGastoSubcategoriaStoreBase + '/' + subcategoriaDe + '/subcategorias'
                : rutas.categoriaGastoStore;

            $.post(url, { nombre })
                .done((resp) => {
                    cfg.categorias.push({ id: resp.categoria.id, nombre: resp.categoria.nombre, categoria_padre_id: resp.categoria.categoria_padre_id, es_sistema: false });
                    llenarCategorias(resp.categoria.id);
                    $('#nueva-categoria-gasto-nombre').val('');
                    bootstrap.Modal.getInstance(document.getElementById('modal-nueva-categoria-gasto'))?.hide();
                    toast('success', 'Categoría creada.');
                })
                .fail((xhr) => {
                    const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo crear la categoría.';
                    $('#nueva-categoria-gasto-nombre').addClass('is-invalid');
                    $('#nueva-categoria-gasto-error').text(msg);
                });
        });

        // Eliminar Categoría/Subcategoría de Gasto (modal de confirmación).
        let idCategoriaGastoAEliminar = null;
        $('#btn-eliminar-categoria-gasto').on('click', function (e) {
            e.preventDefault();
            const id = $('#gasto-categoria').val();
            if (!id) { return; }
            const c = (cfg.categorias || []).find((x) => String(x.id) === String(id));
            if (!c || c.es_sistema) { return; }
            idCategoriaGastoAEliminar = id;
            $('#categoria-gasto-eliminar-nombre').text(c.nombre);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-categoria-gasto-eliminar')).show();
        });
        $('#btn-confirmar-eliminar-categoria-gasto').on('click', function () {
            if (!idCategoriaGastoAEliminar) { return; }
            const id = idCategoriaGastoAEliminar;
            $.post(rutas.categoriaDestroyBase + '/' + id, { _method: 'DELETE' })
                .done((resp) => {
                    cfg.categorias = (cfg.categorias || []).filter((x) => String(x.id) !== String(id));
                    llenarCategorias(null);
                    toast('success', resp.mensaje || 'Categoría eliminada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar la categoría.'))
                .always(() => {
                    bootstrap.Modal.getInstance(document.getElementById('modal-categoria-gasto-eliminar'))?.hide();
                    idCategoriaGastoAEliminar = null;
                });
        });

        $('#btn-guardar-gasto').on('click', function () {
            const id = $('#gasto-id').val();
            const payload = {
                fecha: $('#gasto-fecha').val(),
                monto: $('#gasto-monto').val(),
                categoria_id: $('#gasto-categoria').val(),
                cuenta_tesoreria_id: $('#gasto-pendiente').is(':checked') ? null : $('#gasto-cuenta').val(),
                descripcion: $('#gasto-descripcion').val(),
                pendiente: $('#gasto-pendiente').is(':checked') ? 1 : 0,
            };

            const url = id ? rutas.updateBase + '/' + id : rutas.store;
            const method = id ? 'PUT' : 'POST';

            window.AppBtn.loading('#btn-guardar-gasto', true);
            $.ajax({ url, method, data: payload })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Gasto guardado.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-gasto'))?.hide();
                    tabla.ajax.reload(null, false);
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors)[0][0] : 'No se pudo guardar el gasto.'))
                .always(() => window.AppBtn.loading('#btn-guardar-gasto', false));
        });

        let idAEliminar = null;
        $(document).on('click', '.js-eliminar', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-gasto')).show();
        });
        $('#btn-confirmar-eliminar').on('click', function () {
            if (!idAEliminar) { return; }
            window.AppBtn.loading('#btn-confirmar-eliminar', true);
            $.ajax({ url: rutas.updateBase + '/' + idAEliminar, method: 'DELETE' })
                .done(() => {
                    toast('success', 'Gasto eliminado.');
                    tabla.ajax.reload(null, false);
                    bootstrap.Modal.getInstance(document.getElementById('modal-eliminar-gasto'))?.hide();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar.'))
                .always(() => window.AppBtn.loading('#btn-confirmar-eliminar', false));
        });
    });
})();
