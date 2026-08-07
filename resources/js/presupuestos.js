/**
 * Módulo Presupuestos (US1) — listado (DataTable + KPIs) y formulario de
 * página completa (Select2, cálculo en vivo, idempotencia de guardado).
 * Mismo patrón que resources/js/productos.js.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[presupuestos] jQuery no está disponible.');
        return;
    }

    const cfg = window.PresupuestosConfig || {};
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
            console.log('[presupuestos][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
    }
    function refreshSelect2($el) {
        if (hasSelect2 && $el && $el.length && $el.hasClass('select2-hidden-accessible')) {
            $el.trigger('change.select2');
        }
    }

    // Select2 "catálogo editable": opción fija "Crear X" (ícono +) siempre primera
    // en el dropdown, y un ícono de lápiz por fila que abre la edición de ESE ítem
    // sin seleccionarlo (spec 028). Sirve tanto para selects locales (Categoría,
    // Vendedor) como para selects con `ajax` (Cliente).
    const ID_CREAR = '__crear__';

    function matcherConCrear(params, data) {
        if (data.id === ID_CREAR) { return data; }
        const term = $.trim(params.term || '');
        if (term === '') { return data; }
        if (typeof data.text === 'undefined') { return null; }
        return data.text.toUpperCase().indexOf(term.toUpperCase()) > -1 ? data : null;
    }

    function templateResultCatalogo($el, opts) {
        return function (data) {
            if (!data.id || data.loading) { return data.text; }
            if (data.id === ID_CREAR) {
                // Fidelidad estructural con Contagram real (docs/capturas/saldos): texto a la
                // izquierda, ícono "+" a la derecha, misma posición que el lápiz de las demás filas.
                const $fila = $('<span class="d-flex align-items-center justify-content-between w-100 text-primary fw-semibold select2-resultado-crear"></span>');
                $fila.append($('<span></span>').text(data.text));
                $fila.append('<i class="fas fa-plus-circle ms-2"></i>');
                return $fila;
            }
            const $fila = $('<span class="d-flex align-items-center justify-content-between w-100"></span>');
            $fila.append($('<span></span>').text(data.text));
            if (typeof opts.onEditar === 'function') {
                const $lapiz = $('<a href="#" class="js-editar-item text-muted ms-2" title="Editar"><i class="fas fa-pencil-alt"></i></a>');
                // Select2 selecciona el resultado en "mouseup" (antes de que llegue el "click"),
                // así que hay que frenar la propagación desde el propio ícono en ambos eventos
                // para que el lápiz no dispare la selección de la fila (spec 028, FR-002).
                $lapiz.on('mousedown mouseup', function (e) { e.stopPropagation(); });
                $lapiz.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $el.select2('close');
                    opts.onEditar(data.id, data);
                });
                $fila.append($lapiz);
            }
            return $fila;
        };
    }

    function iniciarSelect2Catalogo($el, opciones) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        const opts = opciones || {};
        const select2Opts = Object.assign({}, opts.select2 || {});
        let ultimoTermino = '';

        if (select2Opts.ajax) {
            const ajaxOrig = select2Opts.ajax;
            const processResultsOrig = ajaxOrig.processResults;
            select2Opts.ajax = Object.assign({}, ajaxOrig, {
                processResults: function (resp, params) {
                    ultimoTermino = (params && params.term) || '';
                    const out = processResultsOrig ? processResultsOrig(resp, params) : { results: resp };
                    if (!params.page) {
                        out.results = [{ id: ID_CREAR, text: opts.textoCrear || 'Crear' }].concat(out.results || []);
                    }
                    return out;
                },
            });
        } else {
            select2Opts.matcher = function (params, data) {
                ultimoTermino = (params && params.term) || '';
                return matcherConCrear(params, data);
            };
        }

        select2Opts.templateResult = templateResultCatalogo($el, opts);
        initSelect2($el, select2Opts);

        $el.on('select2:selecting', function (e) {
            if (e.params.args.data.id === ID_CREAR) {
                e.preventDefault();
                if (typeof opts.onCrear === 'function') { opts.onCrear(ultimoTermino); }
            }
        });
    }

    function money(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Acepta coma o punto como separador decimal (teclado es-AR) y lo normaliza
    // a punto, que es lo que Number()/el backend esperan.
    function normalizarDecimal(v) {
        return String(v == null ? '' : v).replace(',', '.');
    }

    // Preserva el foco (y la posición del cursor) de un input dentro de un
    // contenedor que se re-renderiza por completo, para que escribir un
    // caracter no lo saque del campo (re-render en cada 'input').
    function capturarFoco(contenedorSelector) {
        const activo = document.activeElement;
        if (!activo || !$.contains(document.querySelector(contenedorSelector), activo)) { return null; }
        const $activo = $(activo);
        return {
            idx: $activo.attr('data-idx'),
            field: $activo.attr('data-field'),
            selectionStart: activo.selectionStart,
            selectionEnd: activo.selectionEnd,
        };
    }
    function restaurarFoco(contenedorSelector, foco) {
        if (!foco || foco.idx === undefined || !foco.field) { return; }
        const $input = $(contenedorSelector).find('[data-idx="' + foco.idx + '"][data-field="' + foco.field + '"]');
        if (!$input.length) { return; }
        $input.trigger('focus');
        if (typeof foco.selectionStart === 'number' && $input[0].setSelectionRange) {
            try { $input[0].setSelectionRange(foco.selectionStart, foco.selectionEnd); } catch (e) { /* input type=number en algunos navegadores no soporta selectionRange */ }
        }
    }

    $(function () {
        inicializarListado();
        inicializarFormulario();
    });

    // ---------------------------------------------------------------------
    // Listado (index)
    // ---------------------------------------------------------------------
    function inicializarListado() {
        const $tabla = $('#tabla-presupuestos');
        if (!$tabla.length) { return; }

        initSelect2($('#filtro-cliente'), {
            placeholder: 'Todos', allowClear: true,
            ajax: {
                url: rutas.clientesOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({ results: data.data.map((c) => ({ id: c.id, text: c.nombre })) }),
            },
        });
        initSelect2($('#filtro-producto'), {
            placeholder: 'Todos', allowClear: true,
            ajax: {
                url: rutas.productosOpciones,
                data: (params) => ({ q: params.term, incluir_servicios: 1 }),
                processResults: (resp) => ({ results: resp.data.map((p) => ({ id: p.id, text: '(' + p.id + ') ' + p.nombre + (p.codigo ? ' (' + p.codigo + ')' : '') })) }),
            },
        });
        initSelect2($('#filtro-categoria'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-etiqueta'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-vendedor'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-usuario'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-estado'));

        function filtrosActuales() {
            return {
                id: $('#filtro-id').val(),
                producto_id: $('#filtro-producto').val(),
                cliente_id: $('#filtro-cliente').val(),
                estado: $('#filtro-estado').val(),
                categoria_id: $('#filtro-categoria').val(),
                buscar: $('#filtro-buscar').val(),
                etiqueta_id: $('#filtro-etiqueta').val(),
                vendedor_id: $('#filtro-vendedor').val(),
                formas_pago: $('#filtro-formas-pago').val(),
                metodos_envio: $('#filtro-metodos-envio').val(),
                usuario_id: $('#filtro-usuario').val(),
                nota_cliente: $('#filtro-nota-cliente').val(),
                nota_interna: $('#filtro-nota-interna').val(),
                servicio_desde: $('#filtro-servicio-desde').val(),
                servicio_hasta: $('#filtro-servicio-hasta').val(),
            };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ presupuestos', infoEmpty: 'Sin presupuestos',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron presupuestos',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data, data: (d) => $.extend(d, filtrosActuales()) },
            order: [[2, 'desc']],
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
                { data: 'id', name: 'id' },
                { data: 'fecha_emision', name: 'fecha_emision' },
                { data: 'fecha_validez', name: 'fecha_validez' },
                { data: 'cliente', name: 'cliente.nombre' },
                { data: 'categoria', name: 'categoria.nombre' },
                { data: 'nro_presupuesto', name: 'nro_presupuesto' },
                { data: 'subtotal_sin_descuento', name: 'subtotal_sin_descuento', render: money },
                { data: 'descuento', name: 'descuento', render: money },
                { data: 'subtotal_con_descuento', name: 'subtotal_con_descuento', render: money },
                { data: 'total', name: 'total', render: money },
                { data: 'nota_cliente', name: 'nota_cliente' },
                { data: 'nota_interna', name: 'nota_interna' },
            ],
            stateSave: true,
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                    // Columna 0 es "Acciones", no se puede ocultar.
                    columns: function (idx) { return idx !== 0; },
                },
            ],
        });

        $tabla.one('init.dt', function () {
            tabla.buttons().container().appendTo('#dt-buttons-presupuestos');
        });

        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => {
            $('#filtro-id, #filtro-buscar, #filtro-formas-pago, #filtro-metodos-envio, #filtro-nota-cliente, #filtro-nota-interna, #filtro-servicio-desde, #filtro-servicio-hasta').val('');
            $('#filtro-producto, #filtro-cliente, #filtro-categoria, #filtro-etiqueta, #filtro-vendedor, #filtro-usuario').val(null).trigger('change');
            $('#filtro-estado').val('').trigger('change');
            tabla.ajax.reload();
        });

        $(document).on('click', '.js-cambiar-estado', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const estado = $(this).data('estado');
            $.ajax({ url: rutas.estado + '/' + id + '/estado', method: 'PATCH', data: { estado } })
                .done(() => { toast('success', 'Estado actualizado.'); tabla.ajax.reload(null, false); })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo actualizar el estado.'));
        });

        $(document).on('click', '.js-crear-venta', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            $.post(rutas.crearVenta + '/' + id + '/crear-venta')
                .done((resp) => { if (resp.redirect) { window.location.href = resp.redirect; } })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo crear la venta.'));
        });

        $(document).on('click', '.js-imprimir', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const url = rutas.pdf + '/' + id + '/pdf';
            if (window.AppPdf) { window.AppPdf.abrir(url, 'Presupuesto'); } else { window.open(url, '_blank'); }
        });

        let idAEliminar = null;
        $(document).on('click', '.js-eliminar', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-presupuesto')).show();
        });
        $('#btn-confirmar-eliminar').on('click', function () {
            if (!idAEliminar) { return; }
            $.ajax({ url: rutas.show + '/' + idAEliminar, method: 'DELETE' })
                .done(() => {
                    toast('success', 'Presupuesto eliminado.');
                    tabla.ajax.reload(null, false);
                    bootstrap.Modal.getInstance(document.getElementById('modal-eliminar-presupuesto'))?.hide();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar.'));
        });
    }

    // ---------------------------------------------------------------------
    // Formulario (create/edit)
    // ---------------------------------------------------------------------
    function inicializarFormulario() {
        const $tablaItems = $('#tabla-items');
        if (!$tablaItems.length) { return; }

        const data = window.PresupuestoFormData || {};
        let items = Array.isArray(data.items) && data.items.length ? data.items.slice() : [];
        let conceptos = Array.isArray(data.conceptos) && data.conceptos.length ? data.conceptos.slice() : [];

        // ---- Categoría de ventas (catálogo editable inline: "Crear X" + lápiz por fila) ----
        let categoriasVenta = (cfg.categorias || []).slice();
        const $categoriaSel = $('#f-categoria');

        function renderCategorias(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $categoriaSel.empty();
            $categoriaSel.append(new Option('', '', false, !sel));
            $categoriaSel.append(new Option('Crear Categoría de ventas', ID_CREAR, false, false));
            categoriasVenta.forEach((c) => $categoriaSel.append(new Option(c.nombre, c.id, false, String(c.id) === sel)));
            refreshSelect2($categoriaSel);
        }

        iniciarSelect2Catalogo($categoriaSel, {
            select2: { placeholder: 'Seleccioná una Categoría', allowClear: true },
            onCrear: (termino) => abrirModalCategoria('crear', '', termino || ''),
            onEditar: (id) => {
                const c = categoriasVenta.find((x) => String(x.id) === String(id));
                abrirModalCategoria('renombrar', id, c ? c.nombre : '');
            },
        });
        renderCategorias('');

        // ---- Vendedor (catálogo editable inline: "Crear X" + lápiz por fila, spec 020/028) ----
        let vendedores = (cfg.vendedores || []).slice();
        const $vendedorSel = $('#f-vendedor');

        function renderVendedores(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $vendedorSel.empty();
            $vendedorSel.append(new Option('', '', false, !sel));
            $vendedorSel.append(new Option('Crear Vendedor', ID_CREAR, false, false));
            vendedores.forEach((v) => $vendedorSel.append(new Option(v.nombre, v.id, false, String(v.id) === sel)));
            refreshSelect2($vendedorSel);
        }

        iniciarSelect2Catalogo($vendedorSel, {
            select2: { placeholder: 'Seleccioná un Vendedor', allowClear: true },
            onCrear: (termino) => abrirModalVendedor('crear', '', termino || ''),
            onEditar: (id) => {
                const v = vendedores.find((x) => String(x.id) === String(id));
                abrirModalVendedor('renombrar', id, v ? v.nombre : '');
            },
        });
        renderVendedores((data.presupuesto && data.presupuesto.vendedor_id) || (data.defaults && data.defaults.vendedorId) || '');

        initSelect2($('#f-lista-precio'));
        initSelect2($('#f-etiquetas'), { tags: true, tokenSeparators: [','], placeholder: 'Buscar o crear etiqueta...' });

        // ---- Cliente (catálogo editable inline: "Crear Cliente" + lápiz por fila, abren
        // la ficha COMPLETA de Cliente — mismo modal que en el módulo Clientes) ----
        if (window.ClienteModal) {
            window.ClienteModal.init({
                store: rutas.clientesStore,
                show: rutas.clientesUpdateBase,
                localidades: rutas.clientesLocalidades,
                verificarDocumento: rutas.clientesVerificarDocumento,
            });
        }

        function aplicarClienteGuardado(cliente) {
            const $clienteSel = $('#f-cliente');
            $clienteSel.find('option[value="' + cliente.id + '"]').remove();
            $clienteSel.append(new Option(cliente.nombre, cliente.id, true, true));
            refreshSelect2($clienteSel);
            aplicarAutocompletadoCliente(cliente);
        }

        iniciarSelect2Catalogo($('#f-cliente'), {
            select2: {
                placeholder: 'Seleccionar Cliente',
                ajax: {
                    url: rutas.clientesOpciones,
                    data: (params) => ({ q: params.term }),
                    processResults: (resp) => ({ results: resp.data.map((c) => ({ id: c.id, text: c.nombre, cliente: c })) }),
                },
            },
            textoCrear: 'Crear Cliente',
            onCrear: (termino) => window.ClienteModal && window.ClienteModal.crear(termino, aplicarClienteGuardado),
            onEditar: (id) => window.ClienteModal && window.ClienteModal.editar(id, aplicarClienteGuardado),
        });

        initSelect2($('#f-producto'), {
            placeholder: 'Buscar producto...',
            ajax: {
                url: rutas.productosOpciones,
                data: (params) => ({ q: params.term, incluir_servicios: 1, lista_precio_id: $('#f-lista-precio').val() || null }),
                processResults: (resp) => ({ results: resp.data.map((p) => ({ id: p.id, text: '(' + p.id + ') ' + p.nombre + (p.codigo ? ' (' + p.codigo + ')' : ''), producto: p })) }),
            },
        });

        // Precarga (edición o pre-carga desde Presupuesto → Venta).
        if (data.cliente) {
            const opt = new Option(data.cliente.nombre, data.cliente.id, true, true);
            $('#f-cliente').append(opt);
            refreshSelect2($('#f-cliente'));
        }
        if (data.presupuesto) {
            renderCategorias(data.presupuesto.categoria_id || '');
            $('#f-lista-precio').val(data.presupuesto.lista_precio_id || '');
            $('#f-descuento-general').val(data.presupuesto.descuento_general_pct || '');
        } else {
            // Defaults de Configuración & Ajustes → Ventas (spec 043), sólo alta nueva.
            const defaults = data.defaults || {};
            if (defaults.categoriaId) { renderCategorias(defaults.categoriaId); }
            if (defaults.listaPrecioId) { $('#f-lista-precio').val(defaults.listaPrecioId); }
            if (defaults.fechaValidez) { $('#f-fecha-validez').val(defaults.fechaValidez); }
        }
        refreshSelect2($('#f-lista-precio'));
        if (Array.isArray(data.etiquetas)) {
            data.etiquetas.forEach((nombre) => {
                $('#f-etiquetas').append(new Option(nombre, nombre, true, true));
            });
            refreshSelect2($('#f-etiquetas'));
        }

        // Autocompletado de Categoría/Descuento al elegir Cliente (FR-003, informe §2.5).
        // OJO: Lista de Precios NO se autocompleta — el hallazgo del informe confirma sólo
        // Categoría y Descuento General; incluir Lista acá sería inventar comportamiento.
        function aplicarAutocompletadoCliente(cliente) {
            if (!cliente) { return; }
            if (cliente.categoria_id) { $('#f-categoria').val(cliente.categoria_id).trigger('change'); }
            if (cliente.descuento_general_pct !== null && cliente.descuento_general_pct !== undefined) {
                $('#f-descuento-general').val(cliente.descuento_general_pct);
                recalcular();
            }
        }

        $('#f-cliente').on('select2:select', function (e) {
            aplicarAutocompletadoCliente(e.params.data.cliente);
        });

        $('#f-producto').on('select2:select', function (e) {
            const producto = e.params.data.producto;
            items.unshift({
                producto_id: producto.id,
                descripcion: producto.nombre,
                cantidad: 1,
                precio_unitario: producto.precio || 0,
                descuento_pct: null,
                iva_pct: producto.iva_venta_pct || '21',
                _precioCatalogoOriginal: producto.precio || 0,
            });
            renderItems();
            $(this).val(null).trigger('change');
        });

        // Refresco de fila al editar el producto desde el desplegable ▾ del detalle
        // (spec 052): actualiza nombre siempre; precio sólo si no fue tipeado a mano.
        document.addEventListener('producto:actualizado', function (e) {
            const producto = e.detail && e.detail.producto;
            if (!producto) { return; }
            let cambio = false;
            items.forEach(function (item) {
                if (String(item.producto_id) !== String(producto.id)) { return; }
                item.descripcion = producto.nombre;
                if (Number(item.precio_unitario) === Number(item._precioCatalogoOriginal)) {
                    item.precio_unitario = producto.precio_venta;
                }
                item._precioCatalogoOriginal = producto.precio_venta;
                cambio = true;
            });
            if (cambio) { renderItems(); }
        });

        // Al cambiar la Lista de Precios, recotiza los ítems ya cargados que tengan producto
        // asociado (no toca las descripciones libres) contra la nueva lista.
        $('#f-lista-precio').on('change', function () {
            const listaPrecioId = $(this).val();
            const idsConProducto = items.filter((i) => i.producto_id).map((i) => i.producto_id);
            if (!idsConProducto.length) { return; }

            $.get(rutas.productosOpciones, { ids: idsConProducto, incluir_servicios: 1, lista_precio_id: listaPrecioId || null })
                .done((resp) => {
                    const precios = {};
                    (resp.data || []).forEach((p) => { precios[p.id] = p.precio; });
                    items.forEach((item) => {
                        if (item.producto_id && precios[item.producto_id] !== undefined) {
                            item.precio_unitario = precios[item.producto_id];
                            item._precioCatalogoOriginal = precios[item.producto_id];
                        }
                    });
                    renderItems();
                });
        });

        function renderItems() {
            const foco = capturarFoco('#items-body');
            const $body = $('#items-body').empty();
            items.forEach((item, idx) => {
                const cant = Number(item.cantidad) || 0;
                const precio = Number(item.precio_unitario) || 0;
                const descPct = Number(item.descuento_pct) || 0;
                const ivaPct = { '5': 5, '10.5': 10.5, '21': 21, '27': 27 }[item.iva_pct] || 0;
                const bruto = cant * precio;
                const subtotal = bruto - (bruto * descPct / 100);
                const subtotalConIva = subtotal + (subtotal * ivaPct / 100);

                const $tr = $('<tr>');
                if (item.producto_id) {
                    $tr.append(
                        $('<td>').append(
                            $('<div class="dropdown d-inline-block me-1">').append(
                                $('<button type="button" class="btn btn-sm btn-link p-0 text-body" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">').html('<i class="fas fa-caret-down"></i>'),
                                $('<ul class="dropdown-menu">').append(
                                    $('<li>').append($('<a class="dropdown-item js-item-producto-ver" href="#">').text('Ver').on('click', function (e) { e.preventDefault(); if (window.ProductoModales) { window.ProductoModales.abrirVer(item.producto_id); } })),
                                    $('<li>').append($('<a class="dropdown-item js-item-producto-editar" href="#">').text('Editar').on('click', function (e) { e.preventDefault(); if (window.ProductoModales) { window.ProductoModales.abrirEditar(item.producto_id); } }))
                                )
                            ),
                            $('<span>').text(item.descripcion)
                        )
                    );
                } else {
                    $tr.append($('<td>').text(item.descripcion));
                }
                $tr.append($('<td style="width:90px">').append(
                    $('<input type="text" inputmode="decimal" class="form-control form-control-sm js-item-cant">').attr('data-idx', idx).attr('data-field', 'cantidad').val(item.cantidad === undefined ? cant : item.cantidad).on('input', function () {
                        items[idx].cantidad = normalizarDecimal($(this).val()); renderItems();
                    })
                ));
                $tr.append($('<td style="width:110px">').append(
                    $('<input type="text" inputmode="decimal" class="form-control form-control-sm js-item-precio">').attr('data-idx', idx).attr('data-field', 'precio_unitario').val(item.precio_unitario === undefined ? precio : item.precio_unitario).on('input', function () {
                        items[idx].precio_unitario = normalizarDecimal($(this).val()); renderItems();
                    })
                ));
                $tr.append($('<td style="width:90px">').append(
                    $('<input type="text" inputmode="decimal" class="form-control form-control-sm">').attr('data-idx', idx).attr('data-field', 'descuento_pct').val(item.descuento_pct || '').on('input', function () {
                        items[idx].descuento_pct = normalizarDecimal($(this).val()); renderItems();
                    })
                ));
                $tr.append($('<td>').text(money(subtotal)));
                $tr.append($('<td style="width:90px">').append(
                    $('<select class="form-select form-select-sm">' +
                        ['5', '10.5', '21', '27', 'exento', 'no_gravado'].map((v) => '<option value="' + v + '"' + (v === item.iva_pct ? ' selected' : '') + '>' + v + '</option>').join('') +
                        '</select>').on('change', function () {
                        items[idx].iva_pct = $(this).val(); renderItems();
                    })
                ));
                $tr.append($('<td>').text(money(subtotalConIva)));
                $tr.append($('<td>').append(
                    $('<button type="button" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => {
                        items.splice(idx, 1); renderItems();
                    })
                ));
                $body.append($tr);
            });
            recalcular();
            restaurarFoco('#items-body', foco);
        }

        const PERCEPCIONES = ['IVA (Percepción)', 'Ganancias', 'Sellos', 'IIBB Buenos Aires', 'IIBB CABA', 'IIBB Catamarca', 'IIBB Chaco', 'IIBB Chubut', 'IIBB Córdoba', 'IIBB Corrientes', 'IIBB Entre Ríos', 'IIBB Formosa', 'IIBB Jujuy', 'IIBB La Pampa', 'IIBB La Rioja', 'IIBB Mendoza', 'IIBB Misiones', 'IIBB Neuquén', 'IIBB Río Negro', 'IIBB Salta', 'IIBB San Juan', 'IIBB San Luis', 'IIBB Santa Cruz', 'IIBB Santa Fe', 'IIBB Santiago del Estero', 'IIBB Tierra del Fuego', 'IIBB Tucumán'];

        function renderConceptos() {
            const $body = $('#conceptos-body').empty();
            const etiquetas = { percepcion: 'Percepción', impuesto_interno: 'Impuesto Interno', interes: 'Interés' };
            conceptos.forEach((c, idx) => {
                const $row = $('<div class="input-group input-group-sm mb-2">');
                $row.append($('<span class="input-group-text">').text(etiquetas[c.tipo] || c.tipo));
                if (c.tipo === 'percepcion') {
                    const $select = $('<select class="form-select"><option value="">Seleccionar...</option></select>');
                    PERCEPCIONES.forEach((p) => { $select.append($('<option>').val(p).text(p)); });
                    $select.val(c.concepto || '').on('change', function () { conceptos[idx].concepto = $(this).val(); });
                    $row.append($select);
                } else {
                    $row.append($('<input type="text" class="form-control" placeholder="Concepto">').val(c.concepto || '').on('input', function () {
                        conceptos[idx].concepto = $(this).val();
                    }));
                }
                $row.append($('<input type="text" inputmode="decimal" class="form-control" placeholder="Monto">').val(c.monto || '').on('input', function () {
                    conceptos[idx].monto = normalizarDecimal($(this).val()); recalcular();
                }));
                $row.append($('<button type="button" class="btn btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => {
                    conceptos.splice(idx, 1); renderConceptos(); recalcular();
                }));
                $body.append($row);
            });
        }

        $('.js-add-concepto').on('click', function (e) {
            e.preventDefault();
            conceptos.push({ tipo: $(this).data('tipo'), concepto: '', monto: 0 });
            renderConceptos();
        });

        $('#f-descuento-general').on('input', recalcular);

        function recalcular() {
            let subtotalSinDescuento = 0;
            let totalConIva = 0;
            items.forEach((item) => {
                const cant = Number(item.cantidad) || 0;
                const precio = Number(item.precio_unitario) || 0;
                const descPct = Number(item.descuento_pct) || 0;
                const ivaPct = { '5': 5, '10.5': 10.5, '21': 21, '27': 27 }[item.iva_pct] || 0;
                const bruto = cant * precio;
                const subtotal = bruto - (bruto * descPct / 100);
                subtotalSinDescuento += subtotal;
                totalConIva += subtotal + (subtotal * ivaPct / 100);
            });
            const descuentoGeneralPct = Number($('#f-descuento-general').val()) || 0;
            const descuento = subtotalSinDescuento * descuentoGeneralPct / 100;
            const subtotalConDescuento = subtotalSinDescuento - descuento;
            const totalConceptos = conceptos.reduce((acc, c) => acc + (Number(c.monto) || 0), 0);
            const total = totalConIva - descuento + totalConceptos;

            $('#tot-subtotal-sin-descuento').text(money(subtotalSinDescuento));
            $('#tot-descuento').text(money(descuento));
            $('#tot-subtotal-con-descuento').text(money(subtotalConDescuento));
            $('#tot-total').text(money(total));
        }

        renderItems();
        renderConceptos();

        // Modal crear/renombrar Categoría de ventas.
        let modoCategoria = 'crear';
        let idCategoriaEditar = null;

        function abrirModalCategoria(modo, id, nombreActual) {
            modoCategoria = modo;
            idCategoriaEditar = id || null;
            $('#nueva-categoria-nombre').val(nombreActual || '').removeClass('is-invalid');
            $('#nueva-categoria-error').text('');
            $('#modal-nueva-categoria-titulo').text(modo === 'renombrar' ? 'Renombrar Categoría de ventas' : 'Crear Categoría de ventas');
            $('#btn-crear-categoria').text(modo === 'renombrar' ? 'Guardar' : 'Crear');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria')).show();
            setTimeout(() => $('#nueva-categoria-nombre').trigger('focus'), 300);
        }

        $('#btn-crear-categoria').on('click', function () {
            const nombre = $('#nueva-categoria-nombre').val().trim();
            $('#nueva-categoria-nombre').removeClass('is-invalid');
            $('#nueva-categoria-error').text('');
            if (!nombre) {
                $('#nueva-categoria-nombre').addClass('is-invalid');
                $('#nueva-categoria-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = modoCategoria === 'renombrar';
            const url = esRenombrar ? rutas.categoriaUpdateBase + '/' + idCategoriaEditar : rutas.categoriaVentaStore;
            const datos = esRenombrar ? { _method: 'PATCH', nombre } : { nombre };
            const seleccionVigente = $categoriaSel.val();

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const c = categoriasVenta.find((x) => String(x.id) === String(idCategoriaEditar));
                        if (c) { c.nombre = resp.categoria.nombre; }
                        categoriasVenta.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderCategorias(seleccionVigente);
                    } else {
                        categoriasVenta.push({ id: resp.categoria.id, nombre: resp.categoria.nombre, es_sistema: false });
                        categoriasVenta.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderCategorias(resp.categoria.id);
                    }
                    bootstrap.Modal.getInstance(document.getElementById('modal-nueva-categoria'))?.hide();
                    toast('success', resp.mensaje || 'Categoría guardada.');
                })
                .fail((xhr) => {
                    const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo guardar la categoría.';
                    $('#nueva-categoria-nombre').addClass('is-invalid');
                    $('#nueva-categoria-error').text(msg);
                });
        });

        // Modal crear/renombrar Vendedor.
        let modoVendedor = 'crear';
        let idVendedorEditar = null;

        function abrirModalVendedor(modo, id, nombreActual) {
            modoVendedor = modo;
            idVendedorEditar = id || null;
            $('#nuevo-vendedor-nombre').val(nombreActual || '').removeClass('is-invalid');
            $('#nuevo-vendedor-error').text('');
            $('#modal-nuevo-vendedor-titulo').text(modo === 'renombrar' ? 'Renombrar Vendedor' : 'Crear Vendedor');
            $('#btn-crear-vendedor').text(modo === 'renombrar' ? 'Guardar' : 'Crear');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nuevo-vendedor')).show();
            setTimeout(() => $('#nuevo-vendedor-nombre').trigger('focus'), 300);
        }

        $('#btn-crear-vendedor').on('click', function () {
            const nombre = $('#nuevo-vendedor-nombre').val().trim();
            $('#nuevo-vendedor-nombre').removeClass('is-invalid');
            $('#nuevo-vendedor-error').text('');
            if (!nombre) {
                $('#nuevo-vendedor-nombre').addClass('is-invalid');
                $('#nuevo-vendedor-error').text('Ingresá un nombre.');
                return;
            }

            const esRenombrar = modoVendedor === 'renombrar';
            const url = esRenombrar ? rutas.vendedorUpdateBase + '/' + idVendedorEditar : rutas.vendedorStore;
            const datos = esRenombrar ? { _method: 'PATCH', nombre } : { nombre };
            const seleccionVigente = $vendedorSel.val();

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const v = vendedores.find((x) => String(x.id) === String(idVendedorEditar));
                        if (v) { v.nombre = resp.vendedor.nombre; }
                        vendedores.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderVendedores(seleccionVigente);
                    } else {
                        vendedores.push({ id: resp.vendedor.id, nombre: resp.vendedor.nombre });
                        vendedores.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderVendedores(resp.vendedor.id);
                    }
                    bootstrap.Modal.getInstance(document.getElementById('modal-nuevo-vendedor'))?.hide();
                    toast('success', resp.mensaje || 'Vendedor guardado.');
                })
                .fail((xhr) => {
                    const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo guardar el vendedor.';
                    $('#nuevo-vendedor-nombre').addClass('is-invalid');
                    $('#nuevo-vendedor-error').text(msg);
                });
        });

        // Guardado (idempotente por token — SC-007).
        let enviando = false;
        $('#btn-guardar-presupuesto').on('click', function () {
            if (enviando) { return; }
            if (!$('#f-cliente').val()) { toast('error', 'Seleccioná un cliente.'); return; }
            if (!items.length) { toast('error', 'Agregá al menos un ítem.'); return; }

            const payload = {
                submit_token: cfg.submitToken,
                cliente_id: $('#f-cliente').val(),
                categoria_id: $('#f-categoria').val() || null,
                lista_precio_id: $('#f-lista-precio').val() || null,
                vendedor_id: $('#f-vendedor').val() || null,
                fecha_emision: $('#f-fecha-emision').val(),
                fecha_validez: $('#f-fecha-validez').val() || null,
                servicio_desde: $('#f-servicio-desde').val() || null,
                servicio_hasta: $('#f-servicio-hasta').val() || null,
                descuento_general_pct: $('#f-descuento-general').val() || null,
                nota_cliente: $('#f-nota-cliente').val(),
                nota_interna: $('#f-nota-interna').val(),
                formas_pago: $('#f-formas-pago').val(),
                metodos_envio: $('#f-metodos-envio').val(),
                etiquetas: $('#f-etiquetas').val() || [],
                items: items,
                conceptos: conceptos.filter((c) => c.concepto),
            };

            enviando = true;
            window.AppBtn.loading('#btn-guardar-presupuesto', true);

            const url = rutas.update || rutas.store;
            const method = rutas.update ? 'PUT' : 'POST';

            $.ajax({ url, method, data: payload })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Presupuesto guardado.');
                    window.location.href = resp.redirect || rutas.index;
                })
                .fail((xhr) => {
                    toast('error', xhr.responseJSON?.message || 'No se salvó el Presupuesto, revise el formulario.');
                    enviando = false;
                    window.AppBtn.loading('#btn-guardar-presupuesto', false);
                });
        });
    }
})();
