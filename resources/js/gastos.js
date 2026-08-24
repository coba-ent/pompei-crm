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

        // Catálogo editable inline (mismo patrón de resources/js/ventas.js, spec 028): opción fija
        // "Crear Categoría de Gasto" siempre primera, lápiz por fila para editar sin seleccionar, y
        // una fila "Crear Subcategoría" debajo de cada categoría.
        const ID_CREAR_CATEGORIA = '__crear_categoria__';
        const PREFIJO_CREAR_SUB = '__crear_sub__:';

        // Categorías plegadas por defecto: al abrir el select sólo se ven las categorías raíz, y
        // cada una se despliega con el chevron (las subcategorías y su fila "Crear Subcategoría"
        // llevan `padreId`, y el matcher las descarta mientras el padre no esté en `expandidas`).
        const expandidas = new Set();

        function catalogoCategoriasGasto() {
            const out = [{ id: ID_CREAR_CATEGORIA, tipo: 'crear', text: 'Crear Categoría de Gasto' }];
            categoriasJerarquia().forEach(({ raiz, hijos }) => {
                out.push({ id: raiz.id, tipo: 'categoria', text: raiz.nombre, esSistema: !!raiz.es_sistema, padre: true });
                out.push({ id: PREFIJO_CREAR_SUB + raiz.id, tipo: 'crear_sub', text: 'Crear Subcategoría', categoriaId: raiz.id, padreId: raiz.id });
                hijos.forEach((h) => out.push({ id: h.id, tipo: 'subcategoria', text: h.nombre, esSistema: !!h.es_sistema, padreId: raiz.id }));
            });
            return out;
        }

        function normalizar(t) {
            return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        }

        // Con el buscador vacío se respeta el plegado; al tipear se busca sobre TODAS las
        // subcategorías aunque su padre esté cerrado (si no, el buscador quedaría inservible).
        function matcherCategoriaGasto(params, data) {
            const termino = normalizar(params && params.term).trim();
            if (!termino) {
                if (data.padreId != null && !expandidas.has(String(data.padreId))) { return null; }
                return data;
            }
            if (data.tipo === 'crear' || data.tipo === 'crear_sub') { return null; }
            return normalizar(data.text).indexOf(termino) > -1 ? data : null;
        }

        // Re-pide los resultados sin cerrar el desplegable (`query` es el evento interno que
        // dispara el propio buscador de Select2 en cada tecla), así el chevron pliega/despliega
        // en el lugar y no se pierde el foco ni el término tipeado.
        function refrescarResultadosCategorias() {
            const s2 = $('#gasto-categoria').data('select2');
            if (!s2) { return; }
            const $busqueda = s2.$dropdown ? s2.$dropdown.find('.select2-search__field') : $();
            s2.trigger('query', { term: $busqueda.length ? $busqueda.val() : '' });
        }

        function alternarCategoria(id) {
            const clave = String(id);
            if (expandidas.has(clave)) { expandidas.delete(clave); } else { expandidas.add(clave); }
            refrescarResultadosCategorias();
        }

        function templateResultCategoriaGasto(data) {
            if (!data.id || data.loading) { return data.text; }
            if (data.tipo === 'crear') {
                const $fila = $('<span class="d-flex align-items-center justify-content-between w-100 text-primary fw-semibold select2-resultado-crear"></span>');
                $fila.append($('<span></span>').text(data.text));
                $fila.append('<i class="fas fa-plus-circle ms-2"></i>');
                return $fila;
            }
            if (data.tipo === 'crear_sub') {
                const $fila = $('<span class="d-flex align-items-center justify-content-between w-100 text-primary ps-3 select2-resultado-crear"></span>');
                $fila.append($('<span></span>').text(data.text));
                $fila.append('<i class="fas fa-plus-circle ms-2"></i>');
                return $fila;
            }
            const esSub = data.tipo === 'subcategoria';
            const $fila = $('<span class="d-flex align-items-center justify-content-between w-100"></span>').toggleClass('ps-3', esSub).toggleClass('fw-semibold', !esSub);
            const $izq = $('<span class="d-flex align-items-center"></span>');
            if (data.padre) {
                const abierta = expandidas.has(String(data.id));
                const $chevron = $('<a href="#" class="js-toggle-categoria-gasto text-muted me-2" style="width:1rem"></a>')
                    .attr('title', abierta ? 'Plegar' : 'Desplegar')
                    .append('<i class="fas fa-chevron-' + (abierta ? 'down' : 'right') + '"></i>');
                $chevron.on('mousedown mouseup', (e) => e.stopPropagation());
                $chevron.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    alternarCategoria(data.id);
                });
                $izq.append($chevron);
            }
            $izq.append($('<span></span>').text(data.text));
            $fila.append($izq);
            if (!data.esSistema) {
                const $lapiz = $('<a href="#" class="js-editar-categoria-gasto text-muted ms-2" title="Editar"><i class="fas fa-pencil-alt"></i></a>');
                $lapiz.on('mousedown mouseup', (e) => e.stopPropagation());
                $lapiz.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#gasto-categoria').select2('close');
                    abrirEdicionCategoriaGasto(data.id, data.text);
                });
                $fila.append($lapiz);
            }
            return $fila;
        }

        function llenarCategorias(seleccion) {
            const sel = seleccion ? String(seleccion) : '';
            const $sel = $('#gasto-categoria');
            if (hasSelect2 && $sel.hasClass('select2-hidden-accessible')) { $sel.select2('destroy'); }
            $sel.empty().append('<option></option>');
            // Todo plegado al abrir el modal; única excepción: si lo que viene seleccionado es una
            // subcategoría, se deja abierto su padre para que se vea dónde está parado.
            expandidas.clear();
            const elegida = sel ? (cfg.categorias || []).find((c) => String(c.id) === sel) : null;
            if (elegida && elegida.categoria_padre_id) { expandidas.add(String(elegida.categoria_padre_id)); }
            initSelect2($sel, {
                placeholder: 'Seleccionar Categoría',
                data: catalogoCategoriasGasto(),
                templateResult: templateResultCategoriaGasto,
                matcher: matcherCategoriaGasto,
            });
            if (sel) { $sel.val(sel); }
            $sel.trigger('change.select2');
        }
        function llenarCuentas(seleccion) {
            const $sel = $('#gasto-cuenta').empty();
            $sel.append(new Option('', ''));
            (cfg.cuentas || []).forEach((c) => $sel.append(new Option(c.nombre, c.id, false, c.id === seleccion)));
            $sel.trigger('change.select2');
        }

        initSelect2($('#gasto-cuenta'), { placeholder: 'Seleccioná un Medio de Pago', allowClear: true });
        llenarCategorias(null);
        llenarCuentas(null);
        $('#gasto-categoria').on('select2:selecting', function (e) {
            const d = e.params.args.data;
            if (d.tipo === 'crear') { e.preventDefault(); abrirCrearCategoriaGasto(null); }
            else if (d.tipo === 'crear_sub') { e.preventDefault(); abrirCrearCategoriaGasto(d.categoriaId); }
        });

        // Panel de Filtros (informe §3.2, captura [138]) + el rango de Emisión del control superior.
        initSelect2($('#filtro-categoria'), { placeholder: 'Todos', allowClear: true, dropdownParent: $(document.body) });
        initSelect2($('#filtro-medio-pago'), { placeholder: 'Todos', allowClear: true, dropdownParent: $(document.body) });
        initSelect2($('#filtro-estado-pago'), { placeholder: 'Todos', allowClear: true, dropdownParent: $(document.body) });
        initSelect2($('#filtro-usuario'), { placeholder: 'Todos', allowClear: true, dropdownParent: $(document.body) });

        let emisionDesde = '';
        let emisionHasta = '';

        if ($.fn.daterangepicker && window.RangoEmision) {
            $('#filtro-rango-emision').daterangepicker(window.RangoEmision.opciones());
            $('#filtro-rango-emision').on('apply.daterangepicker', function (e, picker) {
                emisionDesde = picker.startDate.format('YYYY-MM-DD');
                emisionHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val('Emisión: ' + picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
                tabla.ajax.reload();
            });
            $('#filtro-rango-emision').on('cancel.daterangepicker', function () {
                emisionDesde = ''; emisionHasta = '';
                $(this).val('');
                tabla.ajax.reload();
            });
            $('#btn-limpiar-rango-emision').on('click', function () {
                emisionDesde = ''; emisionHasta = '';
                $('#filtro-rango-emision').val('');
                tabla.ajax.reload();
            });
        }

        function filtrosActuales() {
            return {
                id: $('#filtro-id').val(),
                categoria_id: $('#filtro-categoria').val(),
                medio_pago_id: $('#filtro-medio-pago').val(),
                estado_pago: $('#filtro-estado-pago').val(),
                descripcion: $('#filtro-descripcion').val(),
                usuario_id: $('#filtro-usuario').val(),
                emision_desde: emisionDesde,
                emision_hasta: emisionHasta,
            };
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
                { data: 'created_at', name: 'created_at' },
                { data: 'categoria', name: 'categoria.nombre' },
                { data: 'descripcion', name: 'descripcion' },
                { data: 'medio_de_pago', name: 'medio_de_pago' },
                { data: 'monto', name: 'monto', render: money },
            ],
            order: [[3, 'desc']],
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
        $('#btn-limpiar-filtros').on('click', () => {
            $('#filtro-id, #filtro-descripcion').val('');
            $('#filtro-categoria, #filtro-medio-pago, #filtro-estado-pago, #filtro-usuario').val(null).trigger('change');
            emisionDesde = ''; emisionHasta = '';
            $('#filtro-rango-emision').val('');
            tabla.ajax.reload();
        });

        function togglePendiente() {
            const pendiente = $('#gasto-pendiente').is(':checked');
            $('#gasto-cuenta-wrapper').toggle(!pendiente);
        }
        $('#gasto-pendiente').on('change', togglePendiente);

        function abrirModal(row) {
            $('#gasto-id').val(row ? row.id : '');
            $('#modal-gasto-titulo').text(row ? 'Editar Gasto' : 'Nuevo Gasto');
            $('#btn-guardar-gasto').text(row ? 'Guardar' : 'Crear');
            AppFecha.set($('#gasto-fecha'), row ? row.fecha_raw : AppFecha.hoy());
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
        let seleccionAlAbrirEdicion = null;

        function abrirCrearCategoriaGasto(categoriaPadreId) {
            subcategoriaDe = categoriaPadreId;
            modoCategoriaGasto = 'crear';
            $('#modal-nueva-categoria-gasto-titulo').text(categoriaPadreId ? 'Crear Subcategoría' : 'Crear Categoría de Gasto');
            $('#btn-crear-categoria-gasto').text('Crear');
            $('#nueva-categoria-gasto-nombre').val('').removeClass('is-invalid');
            $('#nueva-categoria-gasto-error').text('');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria-gasto')).show();
        }

        function abrirEdicionCategoriaGasto(id, nombreActual) {
            modoCategoriaGasto = 'renombrar';
            idCategoriaGastoEditar = id;
            seleccionAlAbrirEdicion = $('#gasto-categoria').val();
            $('#modal-nueva-categoria-gasto-titulo').text('Renombrar Categoría');
            $('#btn-crear-categoria-gasto').text('Guardar');
            $('#nueva-categoria-gasto-nombre').val(nombreActual).removeClass('is-invalid');
            $('#nueva-categoria-gasto-error').text('');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria-gasto')).show();
        }

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
                        llenarCategorias(seleccionAlAbrirEdicion);
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

        $('#btn-guardar-gasto').on('click', function () {
            const id = $('#gasto-id').val();
            const payload = {
                fecha: AppFecha.get($('#gasto-fecha')),
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
