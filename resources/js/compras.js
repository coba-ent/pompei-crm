/**
 * Módulo Compras (US1) — listado, formulario de página completa y detalle
 * (barra de ecuación + Pago/Retención/NC-ND AJAX). Espejo estructural de
 * ventas.js (spec 008), con IVA por ítem sin default (research.md §2).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[compras] jQuery no está disponible.');
        return;
    }

    const cfg = window.ComprasConfig || {};
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
            console.log('[compras][' + tipo + ']', mensaje);
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
    function money(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    const PCT_IVA = { '5': 5, '10.5': 10.5, '21': 21, '27': 27 };
    function pctIva(valor) {
        return PCT_IVA[valor] || 0;
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
        inicializarDetalle();
    });

    // ---------------------------------------------------------------------
    // Listado (index)
    // ---------------------------------------------------------------------
    function inicializarListado() {
        const $tabla = $('#tabla-compras');
        if (!$tabla.length) { return; }

        initSelect2($('#filtro-proveedor'), {
            placeholder: 'Todos', allowClear: true, multiple: true,
            ajax: {
                url: rutas.proveedoresOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({ results: data.data.map((p) => ({ id: p.id, text: p.nombre })) }),
            },
        });
        initSelect2($('#filtro-categoria'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-etiqueta'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-usuario'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-medio-pago'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-deposito'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-estado-pago'));
        initSelect2($('#filtro-facturado'));

        // --- Rangos de fecha (Emisión / Vencimiento) con presets, mismo patrón que ventas.js ---
        let emisionDesde = '';
        let emisionHasta = '';
        let vencimientoDesde = '';
        let vencimientoHasta = '';

        function opcionesRango() {
            const hoy = moment();

            return {
                autoUpdateInput: false,
                opens: 'left',
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
                    'Año actual': [hoy.clone().startOf('year'), hoy.clone().endOf('year')],
                },
            };
        }

        if ($.fn.daterangepicker) {
            $('#filtro-rango-emision').daterangepicker(opcionesRango());
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
            $('#filtro-rango-emision').on('blur keyup', function () {
                if ($(this).val() === '' && emisionDesde !== '') {
                    emisionDesde = ''; emisionHasta = '';
                    tabla.ajax.reload();
                }
            });
            $('#btn-limpiar-rango-emision').on('click', function () {
                emisionDesde = ''; emisionHasta = '';
                $('#filtro-rango-emision').val('');
                tabla.ajax.reload();
            });

            $('#filtro-rango-vencimiento').daterangepicker(opcionesRango());
            $('#filtro-rango-vencimiento').on('apply.daterangepicker', function (e, picker) {
                vencimientoDesde = picker.startDate.format('YYYY-MM-DD');
                vencimientoHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val('Vencimiento: ' + picker.startDate.format('D MMM') + ' - ' + picker.endDate.format('D MMM'));
                tabla.ajax.reload();
            });
            $('#filtro-rango-vencimiento').on('cancel.daterangepicker', function () {
                vencimientoDesde = ''; vencimientoHasta = '';
                $(this).val('');
                tabla.ajax.reload();
            });
            $('#filtro-rango-vencimiento').on('blur keyup', function () {
                if ($(this).val() === '' && vencimientoDesde !== '') {
                    vencimientoDesde = ''; vencimientoHasta = '';
                    tabla.ajax.reload();
                }
            });
            $('#btn-limpiar-rango-vencimiento').on('click', function () {
                vencimientoDesde = ''; vencimientoHasta = '';
                $('#filtro-rango-vencimiento').val('');
                tabla.ajax.reload();
            });
        }

        function filtrosActuales() {
            return {
                id: $('#filtro-id').val(),
                proveedor_id: $('#filtro-proveedor').val(),
                categoria_id: $('#filtro-categoria').val(),
                estado_pago: $('#filtro-estado-pago').val(),
                factura_buscar: $('#filtro-factura').val(),
                etiqueta_id: $('#filtro-etiqueta').val(),
                facturado: $('#filtro-facturado').val(),
                medio_pago_id: $('#filtro-medio-pago').val(),
                usuario_id: $('#filtro-usuario').val(),
                nota_interna: $('#filtro-nota-interna').val(),
                deposito_id: $('#filtro-deposito').val(),
                servicio_desde: $('#filtro-servicio-desde').val(),
                servicio_hasta: $('#filtro-servicio-hasta').val(),
                emision_desde: emisionDesde,
                emision_hasta: emisionHasta,
                vencimiento_desde: vencimientoDesde,
                vencimiento_hasta: vencimientoHasta,
            };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ compras', infoEmpty: 'Sin compras',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron compras',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data, data: (d) => $.extend(d, filtrosActuales()) },
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
                { data: 'id', name: 'id' },
                { data: 'fecha_emision', name: 'fecha_emision' },
                { data: 'fecha_vto_pago', name: 'fecha_vto_pago' },
                { data: 'proveedor', name: 'proveedor.nombre' },
                { data: 'categoria', name: 'categoria.nombre' },
                { data: 'subtotal_sin_descuento', name: 'subtotal_sin_descuento', render: money },
                { data: 'descuento', name: 'descuento', render: money },
                { data: 'subtotal_con_descuento', name: 'subtotal_con_descuento', render: money },
                { data: 'total', name: 'total', render: money },
                { data: 'pagado', name: 'pagado', render: money },
                { data: 'a_pagar', name: 'a_pagar', render: money },
                { data: 'medio_de_pago', name: 'medio_de_pago' },
                { data: 'etiquetas', name: 'etiquetas', orderable: false, searchable: false, visible: false },
                { data: 'cuit', name: 'cuit', orderable: false, searchable: false, visible: false },
                { data: 'telefono', name: 'telefono', orderable: false, searchable: false, visible: false },
                { data: 'mail', name: 'mail', orderable: false, searchable: false, visible: false },
            ],
            order: [[2, 'desc']],
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
            tabla.buttons().container().appendTo('#dt-buttons-compras');
        });

        // Las Cards de KPIs arriba del listado tienen que reflejar los mismos filtros que la
        // tabla — se recalculan en el server (misma query filtrada) cada vez que la tabla
        // vuelve a pedir datos: reload por Buscar/Limpiar, rango de fechas, eliminar, paginado.
        function actualizarKpis() {
            if (!rutas.kpis) { return; }
            $.getJSON(rutas.kpis, filtrosActuales()).done((kpis) => {
                $('#kpi-cantidad').text(kpis.cantidad);
                $('#kpi-pagado').text(money(kpis.pagado));
                $('#kpi-a-pagar').text(money(kpis.a_pagar));
                $('#kpi-vencido').text(money(kpis.vencido));
                $('#kpi-total').text(money(kpis.total));
            });
        }
        tabla.on('xhr.dt', actualizarKpis);

        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => {
            $('#filtro-id, #filtro-factura, #filtro-nota-interna, #filtro-servicio-desde, #filtro-servicio-hasta').val('');
            $('#filtro-proveedor, #filtro-categoria, #filtro-etiqueta, #filtro-usuario').val(null).trigger('change');
            $('#filtro-estado-pago, #filtro-facturado, #filtro-medio-pago, #filtro-deposito').val('').trigger('change');
            emisionDesde = ''; emisionHasta = '';
            vencimientoDesde = ''; vencimientoHasta = '';
            $('#filtro-rango-emision, #filtro-rango-vencimiento').val('');
            tabla.ajax.reload();
        });

        $(document).on('click', '.js-imprimir', function (e) {
            e.preventDefault();
            const url = rutas.pdf + '/' + $(this).data('id') + '/pdf';
            if (window.AppPdf) { window.AppPdf.abrir(url, 'Detalle de Compra'); } else { window.open(url, '_blank'); }
        });

        let idAEliminar = null;
        $(document).on('click', '.js-eliminar', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-compra')).show();
        });
        $('#btn-confirmar-eliminar').on('click', function () {
            if (!idAEliminar) { return; }
            $.ajax({ url: rutas.show + '/' + idAEliminar, method: 'DELETE' })
                .done(() => {
                    toast('success', 'Compra eliminada.');
                    tabla.ajax.reload(null, false);
                    bootstrap.Modal.getInstance(document.getElementById('modal-eliminar-compra'))?.hide();
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

        const data = window.CompraFormData || {};
        let items = Array.isArray(data.items) && data.items.length ? data.items.slice() : [];
        let conceptos = Array.isArray(data.conceptos) && data.conceptos.length ? data.conceptos.slice() : [];

        // ---- Categoría de Compras (catálogo con Select2 + crear/renombrar/eliminar) ----
        let categoriasCompra = (cfg.categorias || []).slice();
        const $categoriaSel = $('#f-categoria');
        let categoriaPrevia = '';

        function actualizarBotonesCategoria() {
            const val = $categoriaSel.val();
            const cat = categoriasCompra.find((c) => String(c.id) === String(val));
            const real = !!val && val !== '__nuevo__' && !(cat && cat.es_sistema);
            $('#btn-renombrar-categoria, #btn-eliminar-categoria').toggleClass('d-none', !real);
        }

        function renderCategorias(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $categoriaSel.empty();
            $categoriaSel.append(new Option('', '', false, !sel));
            $categoriaSel.append(new Option('＋ Crear Categoría de Compras', '__nuevo__', false, false));
            categoriasCompra.forEach((c) => $categoriaSel.append(new Option(c.nombre, c.id, false, String(c.id) === sel)));
            refreshSelect2($categoriaSel);
            categoriaPrevia = sel;
            actualizarBotonesCategoria();
        }

        initSelect2($categoriaSel, { placeholder: 'Seleccioná una Categoría', allowClear: true });
        renderCategorias('');

        $categoriaSel.on('change', function () {
            const val = $(this).val();
            if (val === '__nuevo__') {
                $(this).val(categoriaPrevia).trigger('change.select2');
                abrirModalCategoria('crear', '', '');
            } else {
                categoriaPrevia = val || '';
                actualizarBotonesCategoria();
            }
        });

        initSelect2($('#f-deposito'), { placeholder: 'Seleccioná un Depósito' });

        initSelect2($('#f-proveedor'), {
            placeholder: 'Seleccionar Proveedor',
            ajax: {
                url: rutas.proveedoresOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (resp) => ({ results: resp.data.map((p) => ({ id: p.id, text: p.nombre, proveedor: p })) }),
            },
        });

        initSelect2($('#f-producto'), {
            placeholder: 'Buscar producto...',
            ajax: {
                url: rutas.productosOpciones,
                data: (params) => ({ q: params.term, incluir_servicios: 1 }),
                processResults: (resp) => ({ results: resp.data.map((p) => ({ id: p.id, text: '(' + p.id + ') ' + p.nombre + (p.codigo ? ' (' + p.codigo + ')' : ''), producto: p })) }),
            },
        });

        if (data.proveedor) {
            $('#f-proveedor').append(new Option(data.proveedor.nombre, data.proveedor.id, true, true));
            refreshSelect2($('#f-proveedor'));
        }
        if (data.categoriaId) { renderCategorias(data.categoriaId); }
        if (data.depositoId) { $('#f-deposito').val(data.depositoId).trigger('change.select2'); }
        if (data.nroComprobante) { $('#f-nro-comprobante').val(data.nroComprobante); }
        if (!$('#f-deposito option').length) {
            $('#f-deposito').prop('disabled', true);
            $('#btn-guardar-compra').prop('disabled', true);
            toast('error', 'No hay Depósitos activos — creá uno en Configuración & Ajustes → Depósitos antes de cargar una Compra.');
        }
        if (data.compra && data.compra.tipo_comprobante) {
            $('#f-tipo-comprobante').val(data.compra.tipo_comprobante);
        } else if (data.tipoComprobanteDefault) {
            $('#f-tipo-comprobante').val(data.tipoComprobanteDefault);
        }
        setModoDescuentoGeneral(data.descuentoGeneralTipo || 'porcentaje', false);
        if (data.descuentoGeneralTipo === 'monto') {
            if (data.descuentoGeneralMonto !== undefined && data.descuentoGeneralMonto !== null) { $('#f-descuento-general').val(data.descuentoGeneralMonto); }
        } else if (data.descuentoGeneralPct !== undefined && data.descuentoGeneralPct !== null) {
            $('#f-descuento-general').val(data.descuentoGeneralPct);
        }
        if (data.notaInterna) { $('#f-nota-interna').val(data.notaInterna); }
        if (data.fechaVtoPago) { $('#f-fecha-vto-pago').val(data.fechaVtoPago); }
        if (data.mesImputacionIva) { $('#f-mes-imputacion-iva').val(data.mesImputacionIva); }

        // Autocompletar Categoría de Compras al elegir Proveedor (FR-002).
        $('#f-proveedor').on('select2:select', function (e) {
            const proveedor = e.params.data.proveedor;
            if (!proveedor) { return; }
            if (proveedor.categoria_id) { $('#f-categoria').val(proveedor.categoria_id).trigger('change'); }
        });

        // IVA sin preseleccionar ("Elegir") — research.md §2: sólo se sugiere el
        // costo/IVA de compra del producto, nunca se fuerza un valor por defecto.
        // Excepción: si el comprobante es tipo A (discrimina IVA), se precarga el
        // iva_compra_pct del producto para ahorrar el tipeo manual en cada ítem.
        $('#f-producto').on('select2:select', function (e) {
            const producto = e.params.data.producto;
            const ivaAuto = $('#f-tipo-comprobante').val() === 'A' ? (producto.iva_compra_pct || null) : null;
            items.unshift({ producto_id: producto.id, descripcion: producto.nombre, cantidad: 1, precio_unitario: producto.costo || 0, descuento_pct: null, iva_pct: ivaAuto, _precioCatalogoOriginal: producto.costo || 0 });
            renderItems();
            $(this).val(null).trigger('change');
        });

        // Refresco de fila al editar el producto desde el desplegable ▾ del detalle
        // (spec 052): actualiza nombre siempre; precio (costo) sólo si no fue tipeado
        // a mano.
        document.addEventListener('producto:actualizado', function (e) {
            const producto = e.detail && e.detail.producto;
            if (!producto) { return; }
            let cambio = false;
            items.forEach(function (item) {
                if (String(item.producto_id) !== String(producto.id)) { return; }
                item.descripcion = producto.nombre;
                if (Number(item.precio_unitario) === Number(item._precioCatalogoOriginal)) {
                    item.precio_unitario = producto.costo;
                }
                item._precioCatalogoOriginal = producto.costo;
                cambio = true;
            });
            if (cambio) { renderItems(); }
        });

        // Si se cambia el comprobante a tipo A, completa el IVA de los ítems que
        // todavía no tengan uno elegido (no pisa lo que el usuario ya seleccionó).
        $('#f-tipo-comprobante').on('change', function () {
            if ($(this).val() !== 'A') { return; }
            const idsSinIva = items.filter((i) => i.producto_id && !i.iva_pct).map((i) => i.producto_id);
            if (!idsSinIva.length) { return; }
            $.get(rutas.productosOpciones, { ids: idsSinIva, incluir_servicios: 1 })
                .done((resp) => {
                    const ivas = {};
                    (resp.data || []).forEach((p) => { ivas[p.id] = p.iva_compra_pct; });
                    items.forEach((item) => {
                        if (item.producto_id && !item.iva_pct && ivas[item.producto_id]) {
                            item.iva_pct = ivas[item.producto_id];
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
                const ivaPct = pctIva(item.iva_pct);
                const bruto = cant * precio;
                const subtotal = bruto - (bruto * descPct / 100);
                const subtotalConIva = subtotal + (subtotal * ivaPct / 100);

                const opcionesIva = ['', '5', '10.5', '21', '27', 'exento', 'no_gravado'];
                const etiquetas = { '': 'Elegir', '5': '5', '10.5': '10,5', '21': '21', '27': '27', exento: 'Exento', no_gravado: 'No Gravado' };
                const selectHtml = '<select class="form-select form-select-sm">' + opcionesIva.map((v) => '<option value="' + v + '"' + (v === (item.iva_pct || '') ? ' selected' : '') + '>' + etiquetas[v] + '</option>').join('') + '</select>';

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
                $tr.append($('<td style="width:90px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').attr('data-idx', idx).attr('data-field', 'cantidad').val(item.cantidad === undefined ? cant : item.cantidad).on('input', function () { items[idx].cantidad = normalizarDecimal($(this).val()); renderItems(); })));
                $tr.append($('<td style="width:110px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').attr('data-idx', idx).attr('data-field', 'precio_unitario').val(item.precio_unitario === undefined ? precio : item.precio_unitario).on('input', function () { items[idx].precio_unitario = normalizarDecimal($(this).val()); renderItems(); })));
                $tr.append($('<td style="width:90px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').attr('data-idx', idx).attr('data-field', 'descuento_pct').val(item.descuento_pct || '').on('input', function () { items[idx].descuento_pct = normalizarDecimal($(this).val()); renderItems(); })));
                $tr.append($('<td>').text(money(subtotal)));
                $tr.append($('<td style="width:110px">').append($(selectHtml).on('change', function () { items[idx].iva_pct = $(this).val() || null; renderItems(); })));
                $tr.append($('<td>').text(money(subtotalConIva)));
                $tr.append($('<td>').append($('<button type="button" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => { items.splice(idx, 1); renderItems(); })));
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
                    $row.append($('<input type="text" class="form-control" placeholder="Concepto">').val(c.concepto || '').on('input', function () { conceptos[idx].concepto = $(this).val(); }));
                }
                $row.append($('<input type="text" inputmode="decimal" class="form-control" placeholder="Monto">').val(c.monto || '').on('input', function () { conceptos[idx].monto = normalizarDecimal($(this).val()); recalcular(); }));
                $row.append($('<button type="button" class="btn btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => { conceptos.splice(idx, 1); renderConceptos(); recalcular(); }));
                $body.append($row);
            });
        }

        $('.js-add-concepto').on('click', function (e) { e.preventDefault(); conceptos.push({ tipo: $(this).data('tipo'), concepto: '', monto: 0 }); renderConceptos(); });
        $('#f-descuento-general').on('input', recalcular);

        // Toggle %/$ inline del Descuento General (spec 060). El backend recalcula siempre —
        // este preview client-side sólo replica el mismo criterio de conversión monto→% efectivo.
        function setModoDescuentoGeneral(modo, limpiarValor) {
            const $btn = $('#f-descuento-general-toggle');
            const $label = $('#f-descuento-general-label');
            const $input = $('#f-descuento-general');
            $btn.data('modo', modo);
            if (modo === 'monto') {
                $btn.text('$');
                $label.text('Descuento General ($)');
                $input.removeAttr('max').attr('step', '0.01');
            } else {
                $btn.text('%');
                $label.text('Descuento General (%)');
                $input.attr('max', '100').attr('step', '0.01');
            }
            if (limpiarValor) {
                $input.val('');
                recalcular();
            }
        }

        $('#f-descuento-general-toggle').on('click', function () {
            const modoActual = $(this).data('modo') || 'porcentaje';
            setModoDescuentoGeneral(modoActual === 'porcentaje' ? 'monto' : 'porcentaje', true);
        });

        function recalcular() {
            // Descuento General % se aplica sobre la base imponible de cada linea (subtotal
            // post-descuento de linea) y por lo tanto tambien reduce el IVA proporcionalmente
            // -- igual que App\Services\Ingresos\CalculoComprobante (backend, fuente de verdad
            // real al guardar). Antes este preview calculaba el IVA completo sin descontar y
            // solo restaba el descuento del subtotal, mostrando un Total mas alto del que
            // terminaba quedando guardado.
            const modoDescuentoGeneral = $('#f-descuento-general-toggle').data('modo') || 'porcentaje';
            const valorDescuentoGeneral = Number($('#f-descuento-general').val()) || 0;

            let subtotalBruto = 0;
            items.forEach((item) => {
                const cant = Number(item.cantidad) || 0;
                const precio = Number(item.precio_unitario) || 0;
                const descPct = Number(item.descuento_pct) || 0;
                const bruto = cant * precio;
                subtotalBruto += bruto - (bruto * descPct / 100);
            });

            const descuentoGeneralPct = modoDescuentoGeneral === 'monto'
                ? (subtotalBruto > 0 ? Math.min(100, (valorDescuentoGeneral / subtotalBruto) * 100) : 0)
                : valorDescuentoGeneral;
            const factor = 1 - (descuentoGeneralPct / 100);

            let subtotalSinDescuento = 0;
            let subtotalConDescuento = 0;
            let totalConIva = 0;
            let hayGravado = false;
            items.forEach((item) => {
                const cant = Number(item.cantidad) || 0;
                const precio = Number(item.precio_unitario) || 0;
                const descPct = Number(item.descuento_pct) || 0;
                const ivaPct = pctIva(item.iva_pct);
                if (item.iva_pct) { hayGravado = true; }
                const bruto = cant * precio;
                const subtotal = bruto - (bruto * descPct / 100);
                const subtotalConIva = subtotal + (subtotal * ivaPct / 100);
                subtotalSinDescuento += subtotal;
                subtotalConDescuento += subtotal * factor;
                totalConIva += subtotalConIva * factor;
            });
            const descuento = subtotalSinDescuento - subtotalConDescuento;
            const totalConceptos = conceptos.reduce((acc, c) => acc + (Number(c.monto) || 0), 0);
            const total = totalConIva + totalConceptos;

            $('#lbl-importe-neto').text(hayGravado ? 'Importe Neto Gravado' : 'Importe Neto No Gravado');
            $('#tot-subtotal-sin-descuento').text(money(subtotalSinDescuento));
            $('#tot-descuento').text(money(descuento));
            $('#tot-subtotal-con-descuento').text(money(subtotalConDescuento));
            $('#tot-total').text(money(total));
        }

        renderItems();
        renderConceptos();

        // Modal crear/renombrar Categoría de Compras.
        let modoCategoria = 'crear';
        let idCategoriaEditar = null;

        function abrirModalCategoria(modo, id, nombreActual) {
            modoCategoria = modo;
            idCategoriaEditar = id || null;
            $('#nueva-categoria-nombre').val(nombreActual || '').removeClass('is-invalid');
            $('#nueva-categoria-error').text('');
            $('#modal-nueva-categoria-titulo').text(modo === 'renombrar' ? 'Renombrar Categoría de Compras' : 'Crear Categoría de Compras');
            $('#btn-crear-categoria').text(modo === 'renombrar' ? 'Guardar' : 'Crear');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria')).show();
            setTimeout(() => $('#nueva-categoria-nombre').trigger('focus'), 300);
        }

        $('#btn-renombrar-categoria').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categoriasCompra.find((x) => String(x.id) === String(id));
            abrirModalCategoria('renombrar', id, c ? c.nombre : '');
        });

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
            const url = esRenombrar ? rutas.categoriaUpdateBase + '/' + idCategoriaEditar : rutas.categoriaCompraStore;
            const datos = esRenombrar ? { _method: 'PATCH', nombre } : { nombre };

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const c = categoriasCompra.find((x) => String(x.id) === String(idCategoriaEditar));
                        if (c) { c.nombre = resp.categoria.nombre; }
                        categoriasCompra.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderCategorias(idCategoriaEditar);
                    } else {
                        categoriasCompra.push({ id: resp.categoria.id, nombre: resp.categoria.nombre, es_sistema: false });
                        categoriasCompra.sort((a, b) => a.nombre.localeCompare(b.nombre));
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

        // Eliminar Categoría de Compras (modal de confirmación).
        let idCategoriaAEliminar = null;
        $('#btn-eliminar-categoria').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categoriasCompra.find((x) => String(x.id) === String(id));
            idCategoriaAEliminar = id;
            $('#categoria-eliminar-nombre').text(c ? c.nombre : '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-categoria-eliminar')).show();
        });
        $('#btn-confirmar-eliminar-categoria').on('click', function () {
            if (!idCategoriaAEliminar) { return; }
            const id = idCategoriaAEliminar;
            $.post(rutas.categoriaDestroyBase + '/' + id, { _method: 'DELETE' })
                .done((resp) => {
                    categoriasCompra = categoriasCompra.filter((x) => String(x.id) !== String(id));
                    renderCategorias('');
                    toast('success', resp.mensaje || 'Categoría eliminada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar la categoría.'))
                .always(() => {
                    bootstrap.Modal.getInstance(document.getElementById('modal-categoria-eliminar'))?.hide();
                    idCategoriaAEliminar = null;
                });
        });

        function payload() {
            return {
                submit_token: cfg.submitToken,
                proveedor_id: $('#f-proveedor').val(),
                categoria_id: $('#f-categoria').val() || null,
                deposito_id: $('#f-deposito').val(),
                nro_comprobante: $('#f-nro-comprobante').val(),
                fecha_emision: $('#f-fecha-emision').val(),
                tipo_comprobante: $('#f-tipo-comprobante').val(),
                fecha_vto_pago: $('#f-fecha-vto-pago').val() || null,
                mes_imputacion_iva: $('#f-mes-imputacion-iva').val() ? $('#f-mes-imputacion-iva').val() + '-01' : null,
                descuento_general_tipo: $('#f-descuento-general-toggle').data('modo') || 'porcentaje',
                descuento_general_pct: ($('#f-descuento-general-toggle').data('modo') || 'porcentaje') === 'porcentaje' ? ($('#f-descuento-general').val() || null) : null,
                descuento_general_monto: ($('#f-descuento-general-toggle').data('modo') || 'porcentaje') === 'monto' ? ($('#f-descuento-general').val() || null) : null,
                nota_interna: $('#f-nota-interna').val(),
                items: items,
                conceptos: conceptos.filter((c) => c.concepto),
            };
        }

        function validar() {
            if (!$('#f-proveedor').val()) { toast('error', 'Seleccioná un proveedor.'); return false; }
            if (!$('#f-deposito').val()) { toast('error', 'Seleccioná un Depósito.'); return false; }
            if (!$('#f-nro-comprobante').val()) { toast('error', 'Ingresá el N° de Comprobante.'); return false; }
            if (!items.length) { toast('error', 'Agregá al menos un ítem.'); return false; }
            return true;
        }

        let enviando = false;
        $('#btn-guardar-compra').on('click', function () {
            if (enviando || !validar()) { return; }
            enviando = true;
            window.AppBtn.loading('#btn-guardar-compra', true);

            const url = rutas.update || rutas.store;
            const method = rutas.update ? 'PUT' : 'POST';

            $.ajax({ url, method, data: payload() })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Compra guardada.');
                    window.location.href = resp.redirect || rutas.index;
                })
                .fail((xhr) => {
                    toast('error', xhr.responseJSON?.message || 'No se salvó la Compra, revise el formulario.');
                    enviando = false;
                    window.AppBtn.loading('#btn-guardar-compra', false);
                });
        });
    }

    // ---------------------------------------------------------------------
    // Detalle (barra de ecuación + Pago + Retención)
    // ---------------------------------------------------------------------
    function inicializarDetalle() {
        const data = window.CompraDetalleData;
        if (!data) { return; }

        function abrirPago() {
            $('#pago-total').text(money(data.total));
            $('#pago-a-pagar').text(money(data.aPagar));
            $('#pago-monto').val(data.aPagar);
            $('#pago-fecha').val(new Date().toISOString().slice(0, 10));
            $('#pago-nota').val('');

            const $cuentas = $('#pago-cuentas').empty();
            data.cuentas.forEach((cuenta) => {
                const $col = $('<div class="col-6">');
                const $btn = $('<button type="button" class="btn btn-outline-primary w-100">').text(cuenta.nombre)
                    .on('click', () => pagar(cuenta.id));
                $col.append($btn);
                $cuentas.append($col);
            });
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-pago')).show();
        }

        function pagar(cuentaId) {
            $.post(rutas.pagoStore, {
                cuenta_tesoreria_id: cuentaId,
                monto: $('#pago-monto').val(),
                fecha: $('#pago-fecha').val(),
                nota: $('#pago-nota').val(),
            })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Compra actualizada con éxito.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-pago'))?.hide();
                    window.location.reload();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.message || xhr.responseJSON?.errors?.monto?.[0] || 'No se pudo registrar el pago.'));
        }

        $('#btn-agregar-pago, .js-agregar-pago').on('click', function (e) {
            e.preventDefault();
            abrirPago();
        });

        $(document).on('click', '.js-eliminar-pago', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            if (!confirm('¿Anular este pago?')) { return; }
            $.ajax({ url: rutas.pagoDestroyBase + '/' + id, method: 'DELETE' })
                .done((resp) => { toast('success', resp.mensaje); window.location.reload(); })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo anular.'));
        });

        $(document).on('click', '.js-ver-recibo-pago', function (e) {
            e.preventDefault();
            const url = $(this).data('url');
            fetch(url, { method: 'HEAD' })
                .then((r) => {
                    if (!r.ok) { throw new Error(); }
                    if (window.AppPdf) { window.AppPdf.abrir(url, 'Recibo'); } else { window.open(url, '_blank'); }
                })
                .catch(() => toast('error', 'No se pudo abrir el Recibo — el pago pudo haber sido eliminado.'));
        });

        $('#btn-agregar-retencion').on('click', function () {
            $('#retencion-fecha').val(new Date().toISOString().slice(0, 10));
            $('#retencion-monto').val('');
            $('#retencion-comprobante').val('');
            $('#retencion-descripcion').val('');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-retencion')).show();
        });
        $('#btn-guardar-retencion').on('click', function () {
            $.post(rutas.retencionStore, {
                fecha: $('#retencion-fecha').val(),
                monto: $('#retencion-monto').val(),
                tipo_retencion: $('#retencion-tipo').val(),
                nro_comprobante: $('#retencion-comprobante').val(),
                descripcion: $('#retencion-descripcion').val(),
            })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Retención creada.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-retencion'))?.hide();
                    window.location.reload();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors)[0][0] : 'No se pudo crear la retención.'));
        });

        $('#btn-crear-remito').on('click', function () {
            $.post(rutas.remitoStore, {})
                .done((resp) => toast('success', resp.mensaje || 'Remito creado.'))
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo crear el remito.'));
        });

        $('.js-imprimir').on('click', function (e) {
            e.preventDefault();
            if (window.AppPdf) { window.AppPdf.abrir(rutas.pdf, 'Detalle de Compra'); } else { window.open(rutas.pdf, '_blank'); }
        });

        inicializarNcNd(data);
    }

    // ---------------------------------------------------------------------
    // Modal NC/ND paso 1 (spec 059) — mismo patrón que ventas.js: sólo Tipo/
    // Documento que Ajusta/Stock/Mes; "Siguiente" navega a la página completa.
    // ---------------------------------------------------------------------
    function inicializarNcNd(data) {
        const $modal = $('#modal-ncnd');
        if (!$modal.length) { return; }

        let mesImputacionTocado = false;
        let notaEnEdicion = null;
        let notaAEliminarId = null;

        // La página completa (notas-credito-debito.js) precarga TODOS los ítems
        // pendientes del comprobante cuando Stock=Sí — el modal ya no necesita
        // elegir productos/depósito, sólo saber si el comprobante tiene productos
        // (para deshabilitar "Sí" si no los tiene).
        function chequearSinProductos() {
            $.getJSON(window.ComprasConfig.rutas.notasItemsDisponibles)
                .done((resp) => {
                    const sinProductos = (resp.data || []).length === 0;
                    $('#ncnd-afecta-si').prop('disabled', sinProductos);
                    $('#ncnd-sin-productos').toggleClass('d-none', !sinProductos);
                })
                .fail(() => {});
        }

        $('#btn-agregar-nota').on('click', function () {
            notaEnEdicion = null;
            $('#ncnd-titulo').text('Crear NC/ND');
            $('#ncnd-tipo').val('credito').prop('disabled', false);
            const $doc = $('#ncnd-documento').empty();
            $doc.append(new Option(data.nroComprobante || 'Sin comprobante', data.compraId, true, true));
            const hoy = new Date();
            $('#ncnd-mes-imputacion').val(hoy.toISOString().slice(0, 7));
            mesImputacionTocado = false;
            $('input[name="ncnd-afecta-stock"][value="0"]').prop('checked', true).prop('disabled', false);
            $('#ncnd-afecta-si').prop('disabled', false);

            chequearSinProductos();

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ncnd')).show();
        });

        $(document).on('click', '.js-ver-detalle-nota', function (e) {
            e.preventDefault();
            const url = $(this).data('url');
            if (window.AppPdf) { window.AppPdf.abrir(url, 'Nota de Crédito/Débito'); } else { window.open(url, '_blank'); }
        });

        // ---------------------------------------------------------------------
        // Editar NC/ND (spec 057) — Tipo Y Stock quedan deshabilitados (spec 059, FR-008)
        // ---------------------------------------------------------------------
        function abrirEdicionNota(id) {
            const nota = (data.notas || []).find((n) => String(n.id) === String(id));
            if (!nota) { return; }
            notaEnEdicion = nota;

            $('#ncnd-titulo').text('Editar NC/ND');
            $('#ncnd-tipo').val(nota.tipo).prop('disabled', true);
            const $doc = $('#ncnd-documento').empty();
            $doc.append(new Option(data.nroComprobante || 'Sin comprobante', data.compraId, true, true));
            $('input[name="ncnd-afecta-stock"][value="' + (nota.afecta_stock ? '1' : '0') + '"]').prop('checked', true);
            $('#ncnd-afecta-si, #ncnd-afecta-no').prop('disabled', true);
            $('#ncnd-mes-imputacion').val(nota.mes_imputacion);
            mesImputacionTocado = true;

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ncnd')).show();
        }

        $(document).on('click', '.js-editar-nota', function (e) {
            e.preventDefault();
            abrirEdicionNota($(this).data('id'));
        });

        $(document).on('click', '.js-eliminar-nota', function (e) {
            e.preventDefault();
            notaAEliminarId = $(this).data('id');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-nota')).show();
        });

        $('#btn-confirmar-eliminar-nota').on('click', function () {
            if (!notaAEliminarId) { return; }
            $.ajax({ url: window.ComprasConfig.rutas.notasDestroyBase + '/' + notaAEliminarId, method: 'DELETE' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Nota eliminada.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-eliminar-nota'))?.hide();
                    window.location.reload();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar la nota.'));
        });

        // ---------------------------------------------------------------------
        // Siguiente (spec 059): ya no muestra un 2do paso — navega a la página
        // completa (compras.notas.create/edit) pasando el paso 1 por query string.
        // ---------------------------------------------------------------------
        $('#btn-ncnd-siguiente').on('click', function () {
            const afectaStock = $('input[name="ncnd-afecta-stock"]:checked').val() === '1';
            const qs = new URLSearchParams({
                tipo: $('#ncnd-tipo').val() || 'credito',
                afecta_stock: afectaStock ? '1' : '0',
                mes_imputacion: $('#ncnd-mes-imputacion').val() || '',
            });

            const url = notaEnEdicion
                ? window.ComprasConfig.rutas.notasEditPaginaBase + '/' + notaEnEdicion.id + '/editar'
                : window.ComprasConfig.rutas.notasCreatePagina;

            window.location.href = url + '?' + qs.toString();
        });
    }
})();
