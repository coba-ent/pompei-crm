/**
 * Módulo Ventas (US2) — listado, formulario de página completa y detalle
 * (barra de ecuación + Cobranza AJAX). Mismo patrón que presupuestos.js.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[ventas] jQuery no está disponible.');
        return;
    }

    const cfg = window.VentasConfig || {};
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
            console.log('[ventas][' + tipo + ']', mensaje);
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

    $(function () {
        inicializarListado();
        inicializarFormulario();
        inicializarDetalle();
    });

    // ---------------------------------------------------------------------
    // Listado (index)
    // ---------------------------------------------------------------------
    function inicializarListado() {
        const $tabla = $('#tabla-ventas');
        if (!$tabla.length) { return; }

        initSelect2($('#filtro-cliente'), {
            placeholder: 'Todos', allowClear: true,
            ajax: {
                url: rutas.clientesOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({ results: data.data.map((c) => ({ id: c.id, text: c.nombre })) }),
            },
        });

        function filtrosActuales() {
            return {
                cliente_id: $('#filtro-cliente').val(),
                buscar: $('#filtro-buscar').val(),
                creada_desde: $('#filtro-creada-desde').val(),
            };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true, responsive: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ ventas', infoEmpty: 'Sin ventas',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron ventas',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data, data: (d) => $.extend(d, filtrosActuales()) },
            columns: [
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false },
                { data: 'id', name: 'id' },
                { data: 'creada_desde', name: 'creada_desde' },
                { data: 'fecha_emision', name: 'fecha_emision' },
                { data: 'fecha_validez', name: 'fecha_validez' },
                { data: 'cliente', name: 'cliente.nombre' },
                { data: 'categoria', name: 'categoria.nombre' },
                { data: 'subtotal_sin_descuento', name: 'subtotal_sin_descuento', render: money },
                { data: 'descuento', name: 'descuento', render: money },
                { data: 'subtotal_con_descuento', name: 'subtotal_con_descuento', render: money },
                { data: 'total', name: 'total', render: money },
                { data: 'a_cobrar', name: 'a_cobrar', render: money },
                { data: 'cobrado', name: 'cobrado', render: money },
                { data: 'etiquetas', name: 'etiquetas' },
                { data: 'medio_de_cobro', name: 'medio_de_cobro' },
                { data: 'nota_cliente', name: 'nota_cliente' },
                { data: 'nota_interna', name: 'nota_interna' },
                { data: 'lista_precio', name: 'lista_precio' },
                { data: 'vendedor', name: 'vendedor' },
            ],
        });

        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => {
            $('#filtro-cliente').val(null).trigger('change');
            $('#filtro-buscar').val('');
            $('#filtro-creada-desde').val('');
            tabla.ajax.reload();
        });

        $(document).on('click', '.js-imprimir', function (e) {
            e.preventDefault();
            const url = rutas.pdf + '/' + $(this).data('id') + '/pdf';
            if (window.AppPdf) { window.AppPdf.abrir(url, 'Detalle de Venta'); } else { window.open(url, '_blank'); }
        });
        $(document).on('click', '.js-imprimir-ticket', function (e) {
            e.preventDefault();
            const url = rutas.ticket + '/' + $(this).data('id') + '/ticket';
            if (window.AppPdf) { window.AppPdf.abrir(url, 'Ticket'); } else { window.open(url, '_blank'); }
        });

        let idAEliminar = null;
        $(document).on('click', '.js-eliminar', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-venta')).show();
        });
        $('#btn-confirmar-eliminar').on('click', function () {
            if (!idAEliminar) { return; }
            $.ajax({ url: rutas.show + '/' + idAEliminar, method: 'DELETE' })
                .done(() => {
                    toast('success', 'Venta eliminada.');
                    tabla.ajax.reload(null, false);
                    bootstrap.Modal.getInstance(document.getElementById('modal-eliminar-venta'))?.hide();
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

        const data = window.VentaFormData || {};
        let items = Array.isArray(data.items) && data.items.length ? data.items.slice() : [];
        let conceptos = Array.isArray(data.conceptos) && data.conceptos.length ? data.conceptos.slice() : [];

        // ---- Categoría de ventas (catálogo con Select2 + crear/renombrar/eliminar) ----
        let categoriasVenta = (cfg.categorias || []).slice();
        const $categoriaSel = $('#f-categoria');
        let categoriaPrevia = '';

        function actualizarBotonesCategoria() {
            const val = $categoriaSel.val();
            const cat = categoriasVenta.find((c) => String(c.id) === String(val));
            const real = !!val && val !== '__nuevo__' && !(cat && cat.es_sistema);
            $('#btn-renombrar-categoria, #btn-eliminar-categoria').toggleClass('d-none', !real);
        }

        function renderCategorias(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $categoriaSel.empty();
            $categoriaSel.append(new Option('', '', false, !sel));
            $categoriaSel.append(new Option('＋ Crear Categoría de ventas', '__nuevo__', false, false));
            categoriasVenta.forEach((c) => $categoriaSel.append(new Option(c.nombre, c.id, false, String(c.id) === sel)));
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

        // ---- Vendedor (catálogo con Select2 + crear/renombrar/eliminar, spec 020) ----
        let vendedores = (cfg.vendedores || []).slice();
        const $vendedorSel = $('#f-vendedor');
        let vendedorPrevio = '';

        function actualizarBotonesVendedor() {
            const val = $vendedorSel.val();
            const real = !!val && val !== '__nuevo__';
            $('#btn-renombrar-vendedor, #btn-eliminar-vendedor').toggleClass('d-none', !real);
        }

        function renderVendedores(selectedId) {
            const sel = selectedId ? String(selectedId) : '';
            $vendedorSel.empty();
            $vendedorSel.append(new Option('', '', false, !sel));
            $vendedorSel.append(new Option('＋ Crear Vendedor', '__nuevo__', false, false));
            vendedores.forEach((v) => $vendedorSel.append(new Option(v.nombre, v.id, false, String(v.id) === sel)));
            refreshSelect2($vendedorSel);
            vendedorPrevio = sel;
            actualizarBotonesVendedor();
        }

        initSelect2($vendedorSel, { placeholder: 'Seleccioná un Vendedor', allowClear: true });
        renderVendedores(data.vendedorId || '');

        $vendedorSel.on('change', function () {
            const val = $(this).val();
            if (val === '__nuevo__') {
                $(this).val(vendedorPrevio).trigger('change.select2');
                abrirModalVendedor('crear', '', '');
            } else {
                vendedorPrevio = val || '';
                actualizarBotonesVendedor();
            }
        });

        initSelect2($('#f-lista-precio'), { placeholder: 'Seleccioná una Lista de Precios', allowClear: true });
        initSelect2($('#f-etiquetas'), { tags: true, tokenSeparators: [','], placeholder: 'Buscar o crear etiqueta...' });

        initSelect2($('#f-cliente'), {
            placeholder: 'Seleccionar Cliente',
            ajax: {
                url: rutas.clientesOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (resp) => ({ results: resp.data.map((c) => ({ id: c.id, text: c.nombre, cliente: c })) }),
            },
        });

        initSelect2($('#f-producto'), {
            placeholder: 'Buscar producto...',
            ajax: {
                url: rutas.productosOpciones,
                data: (params) => ({ q: params.term, incluir_servicios: 1, lista_precio_id: $('#f-lista-precio').val() || null }),
                processResults: (resp) => ({ results: resp.data.map((p) => ({ id: p.id, text: p.nombre + (p.codigo ? ' (' + p.codigo + ')' : ''), producto: p })) }),
            },
        });

        if (data.cliente) {
            $('#f-cliente').append(new Option(data.cliente.nombre, data.cliente.id, true, true));
            refreshSelect2($('#f-cliente'));
        }
        if (data.categoriaId) { renderCategorias(data.categoriaId); }
        if (data.listaPrecioId) { $('#f-lista-precio').val(data.listaPrecioId); }
        if (data.servicioDesde) { $('#f-servicio-desde').val(data.servicioDesde); }
        if (data.servicioHasta) { $('#f-servicio-hasta').val(data.servicioHasta); }
        refreshSelect2($('#f-lista-precio'));
        if (data.venta && data.venta.tipo_comprobante) { $('#f-tipo-comprobante').val(data.venta.tipo_comprobante); }
        if (data.descuentoGeneralPct !== undefined && data.descuentoGeneralPct !== null) { $('#f-descuento-general').val(data.descuentoGeneralPct); }
        if (data.notaCliente) { $('#f-nota-cliente').val(data.notaCliente); }
        if (data.notaInterna) { $('#f-nota-interna').val(data.notaInterna); }
        if (data.formasPago) { $('#f-formas-pago').val(data.formasPago); }
        if (data.metodosEnvio) { $('#f-metodos-envio').val(data.metodosEnvio); }
        if (Array.isArray(data.etiquetas)) {
            data.etiquetas.forEach((nombre) => $('#f-etiquetas').append(new Option(nombre, nombre, true, true)));
            refreshSelect2($('#f-etiquetas'));
        }

        // Autocompletado de Categoría/Descuento al elegir Cliente (FR-003). Lista de Precios
        // NO se autocompleta — el informe sólo confirma Categoría y Descuento General. Tipo de
        // Comprobante sí se autocompleta desde el default del cliente (clientes.tipo_comprobante_defecto).
        $('#f-cliente').on('select2:select', function (e) {
            const cliente = e.params.data.cliente;
            if (!cliente) { return; }
            if (cliente.categoria_id) { $('#f-categoria').val(cliente.categoria_id).trigger('change'); }
            if (cliente.descuento_general_pct !== null && cliente.descuento_general_pct !== undefined) {
                $('#f-descuento-general').val(cliente.descuento_general_pct);
                recalcular();
            }
            if (cliente.tipo_comprobante_defecto) { $('#f-tipo-comprobante').val(cliente.tipo_comprobante_defecto); }
        });

        $('#f-producto').on('select2:select', function (e) {
            const producto = e.params.data.producto;
            items.push({ producto_id: producto.id, descripcion: producto.nombre, cantidad: 1, precio_unitario: producto.precio || 0, descuento_pct: null, iva_pct: producto.iva_venta_pct || '21' });
            renderItems();
            $(this).val(null).trigger('change');
        });

        // Al cambiar la Lista de Precios, recotiza los ítems ya cargados que tengan producto asociado.
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
                        }
                    });
                    renderItems();
                });
        });

        function renderItems() {
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
                $tr.append($('<td>').text(item.descripcion));
                $tr.append($('<td style="width:90px">').append($('<input type="number" step="0.001" class="form-control form-control-sm">').val(cant).on('input', function () { items[idx].cantidad = $(this).val(); renderItems(); })));
                $tr.append($('<td style="width:110px">').append($('<input type="number" step="0.01" class="form-control form-control-sm">').val(precio).on('input', function () { items[idx].precio_unitario = $(this).val(); renderItems(); })));
                $tr.append($('<td style="width:90px">').append($('<input type="number" step="0.01" class="form-control form-control-sm">').val(item.descuento_pct || '').on('input', function () { items[idx].descuento_pct = $(this).val(); renderItems(); })));
                $tr.append($('<td>').text(money(subtotal)));
                $tr.append($('<td style="width:90px">').append($('<select class="form-select form-select-sm">' + ['5', '10.5', '21', '27', 'exento', 'no_gravado'].map((v) => '<option value="' + v + '"' + (v === item.iva_pct ? ' selected' : '') + '>' + v + '</option>').join('') + '</select>').on('change', function () { items[idx].iva_pct = $(this).val(); renderItems(); })));
                $tr.append($('<td>').text(money(subtotalConIva)));
                $tr.append($('<td>').append($('<button type="button" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => { items.splice(idx, 1); renderItems(); })));
                $body.append($tr);
            });
            recalcular();
        }

        function renderConceptos() {
            const $body = $('#conceptos-body').empty();
            const etiquetas = { percepcion: 'Percepción', impuesto_interno: 'Impuesto Interno', interes: 'Interés' };
            conceptos.forEach((c, idx) => {
                const $row = $('<div class="input-group input-group-sm mb-2">');
                $row.append($('<span class="input-group-text">').text(etiquetas[c.tipo] || c.tipo));
                $row.append($('<input type="text" class="form-control" placeholder="Concepto">').val(c.concepto || '').on('input', function () { conceptos[idx].concepto = $(this).val(); }));
                $row.append($('<input type="number" step="0.01" class="form-control" placeholder="Monto">').val(c.monto || '').on('input', function () { conceptos[idx].monto = $(this).val(); recalcular(); }));
                $row.append($('<button type="button" class="btn btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => { conceptos.splice(idx, 1); renderConceptos(); recalcular(); }));
                $body.append($row);
            });
        }

        $('.js-add-concepto').on('click', function (e) { e.preventDefault(); conceptos.push({ tipo: $(this).data('tipo'), concepto: '', monto: 0 }); renderConceptos(); });
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

        $('#btn-renombrar-categoria').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categoriasVenta.find((x) => String(x.id) === String(id));
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
            const url = esRenombrar ? rutas.categoriaUpdateBase + '/' + idCategoriaEditar : rutas.categoriaVentaStore;
            const datos = esRenombrar ? { _method: 'PATCH', nombre } : { nombre };

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const c = categoriasVenta.find((x) => String(x.id) === String(idCategoriaEditar));
                        if (c) { c.nombre = resp.categoria.nombre; }
                        categoriasVenta.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderCategorias(idCategoriaEditar);
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

        // Eliminar Categoría de ventas (modal de confirmación).
        let idCategoriaAEliminar = null;
        $('#btn-eliminar-categoria').on('click', function (e) {
            e.preventDefault();
            const id = $categoriaSel.val();
            if (!id || id === '__nuevo__') { return; }
            const c = categoriasVenta.find((x) => String(x.id) === String(id));
            idCategoriaAEliminar = id;
            $('#categoria-eliminar-nombre').text(c ? c.nombre : '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-categoria-eliminar')).show();
        });
        $('#btn-confirmar-eliminar-categoria').on('click', function () {
            if (!idCategoriaAEliminar) { return; }
            const id = idCategoriaAEliminar;
            $.post(rutas.categoriaDestroyBase + '/' + id, { _method: 'DELETE' })
                .done((resp) => {
                    categoriasVenta = categoriasVenta.filter((x) => String(x.id) !== String(id));
                    renderCategorias('');
                    toast('success', resp.mensaje || 'Categoría eliminada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar la categoría.'))
                .always(() => {
                    bootstrap.Modal.getInstance(document.getElementById('modal-categoria-eliminar'))?.hide();
                    idCategoriaAEliminar = null;
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

        $('#btn-renombrar-vendedor').on('click', function (e) {
            e.preventDefault();
            const id = $vendedorSel.val();
            if (!id || id === '__nuevo__') { return; }
            const v = vendedores.find((x) => String(x.id) === String(id));
            abrirModalVendedor('renombrar', id, v ? v.nombre : '');
        });

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

            $.post(url, datos)
                .done((resp) => {
                    if (esRenombrar) {
                        const v = vendedores.find((x) => String(x.id) === String(idVendedorEditar));
                        if (v) { v.nombre = resp.vendedor.nombre; }
                        vendedores.sort((a, b) => a.nombre.localeCompare(b.nombre));
                        renderVendedores(idVendedorEditar);
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

        // Eliminar Vendedor (modal de confirmación).
        let idVendedorAEliminar = null;
        $('#btn-eliminar-vendedor').on('click', function (e) {
            e.preventDefault();
            const id = $vendedorSel.val();
            if (!id || id === '__nuevo__') { return; }
            const v = vendedores.find((x) => String(x.id) === String(id));
            idVendedorAEliminar = id;
            $('#vendedor-eliminar-nombre').text(v ? v.nombre : '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-vendedor-eliminar')).show();
        });
        $('#btn-confirmar-eliminar-vendedor').on('click', function () {
            if (!idVendedorAEliminar) { return; }
            const id = idVendedorAEliminar;
            $.post(rutas.vendedorDestroyBase + '/' + id, { _method: 'DELETE' })
                .done((resp) => {
                    vendedores = vendedores.filter((x) => String(x.id) !== String(id));
                    renderVendedores('');
                    toast('success', resp.mensaje || 'Vendedor eliminado.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar el vendedor.'))
                .always(() => {
                    bootstrap.Modal.getInstance(document.getElementById('modal-vendedor-eliminar'))?.hide();
                    idVendedorAEliminar = null;
                });
        });

        function payload() {
            return {
                submit_token: cfg.submitToken,
                presupuesto_id: data.presupuestoId || null,
                cliente_id: $('#f-cliente').val(),
                categoria_id: $('#f-categoria').val() || null,
                lista_precio_id: $('#f-lista-precio').val() || null,
                vendedor_id: $('#f-vendedor').val() || null,
                fecha_emision: $('#f-fecha-emision').val(),
                servicio_desde: $('#f-servicio-desde').val() || null,
                servicio_hasta: $('#f-servicio-hasta').val() || null,
                tipo_comprobante: $('#f-tipo-comprobante').val(),
                fecha_vto_cobro: $('#f-fecha-vto-cobro').val() || null,
                descuento_general_pct: $('#f-descuento-general').val() || null,
                nota_cliente: $('#f-nota-cliente').val(),
                nota_interna: $('#f-nota-interna').val(),
                formas_pago: $('#f-formas-pago').val(),
                metodos_envio: $('#f-metodos-envio').val(),
                etiquetas: $('#f-etiquetas').val() || [],
                items: items,
                conceptos: conceptos.filter((c) => c.concepto),
            };
        }

        function validar() {
            if (!$('#f-cliente').val()) { toast('error', 'Seleccioná un cliente.'); return false; }
            if (!items.length) { toast('error', 'Agregá al menos un ítem.'); return false; }
            return true;
        }

        let enviando = false;
        function guardar(onDone) {
            if (enviando || !validar()) { return; }
            enviando = true;
            window.AppBtn.loading('#btn-guardar-venta, #btn-cobrar-venta', true);

            const url = rutas.update || rutas.store;
            const method = rutas.update ? 'PUT' : 'POST';

            $.ajax({ url, method, data: payload() })
                .done((resp) => { toast('success', resp.mensaje || 'Venta guardada.'); onDone(resp); })
                .fail((xhr) => {
                    toast('error', xhr.responseJSON?.message || 'No se salvó la Venta, revise el formulario.');
                    enviando = false;
                    window.AppBtn.loading('#btn-guardar-venta, #btn-cobrar-venta', false);
                });
        }

        $('#btn-guardar-venta').on('click', () => guardar((resp) => { window.location.href = resp.redirect || rutas.index; }));
        $('#btn-cobrar-venta').on('click', () => guardar((resp) => {
            window.location.href = (resp.redirect || rutas.index) + '?cobrar=1';
        }));
    }

    // ---------------------------------------------------------------------
    // Detalle (barra de ecuación + Cobranza)
    // ---------------------------------------------------------------------
    function inicializarDetalle() {
        const data = window.VentaDetalleData;
        if (!data) { return; }

        function abrirCobranza() {
            $('#cobranza-total').text(money(data.total));
            $('#cobranza-a-cobrar').text(money(data.aCobrar));
            $('#cobranza-monto').val(data.aCobrar);
            $('#cobranza-fecha').val(new Date().toISOString().slice(0, 10));

            const $cuentas = $('#cobranza-cuentas').empty();
            data.cuentas.forEach((cuenta) => {
                const $col = $('<div class="col-6">');
                const $btn = $('<button type="button" class="btn btn-outline-primary w-100">').text(cuenta.nombre)
                    .on('click', () => cobrar(cuenta.id));
                $col.append($btn);
                $cuentas.append($col);
            });
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-cobranza')).show();
        }

        function cobrar(cuentaId) {
            $.post(rutas.cobranzaStore, {
                cuenta_tesoreria_id: cuentaId,
                monto: $('#cobranza-monto').val(),
                fecha: $('#cobranza-fecha').val(),
            })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Venta actualizada con éxito.');
                    if (resp.arca_error) {
                        toast('warning', 'ARCA: ' + resp.arca_error);
                    }
                    bootstrap.Modal.getInstance(document.getElementById('modal-cobranza'))?.hide();
                    window.location.reload();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.message || xhr.responseJSON?.errors?.monto?.[0] || 'No se pudo registrar la cobranza.'));
        }

        $('#btn-agregar-cobranza, .js-agregar-cobranza').on('click', function (e) {
            e.preventDefault();
            abrirCobranza();
        });

        $(document).on('click', '.js-eliminar-cobro', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            if (!confirm('¿Anular esta cobranza?')) { return; }
            $.ajax({ url: rutas.cobranzaDestroyBase + '/' + id, method: 'DELETE' })
                .done((resp) => { toast('success', resp.mensaje); window.location.reload(); })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo anular.'));
        });

        $('#btn-crear-remito').on('click', function () {
            $.post(rutas.remitoStore, {})
                .done((resp) => toast('success', resp.mensaje || 'Remito creado.'))
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo crear el remito.'));
        });

        $('.js-imprimir').on('click', function (e) {
            e.preventDefault();
            if (window.AppPdf) { window.AppPdf.abrir(rutas.pdf, 'Detalle de Venta'); } else { window.open(rutas.pdf, '_blank'); }
        });
        $('.js-imprimir-ticket').on('click', function (e) {
            e.preventDefault();
            if (window.AppPdf) { window.AppPdf.abrir(rutas.ticket, 'Ticket'); } else { window.open(rutas.ticket, '_blank'); }
        });

        $(document).on('click', '.js-ver-detalle-nota', function (e) {
            e.preventDefault();
            const url = $(this).data('url');
            if (window.AppPdf) { window.AppPdf.abrir(url, 'Nota de Crédito/Débito'); } else { window.open(url, '_blank'); }
        });

        $(document).on('click', '.js-ver-recibo-cobranza', function (e) {
            e.preventDefault();
            const url = $(this).data('url');
            fetch(url, { method: 'HEAD' })
                .then((r) => {
                    if (!r.ok) { throw new Error(); }
                    if (window.AppPdf) { window.AppPdf.abrir(url, 'Recibo'); } else { window.open(url, '_blank'); }
                })
                .catch(() => toast('error', 'No se pudo abrir el Recibo — la cobranza pudo haber sido eliminada.'));
        });

        if (data.autoAbrirCobranza) {
            abrirCobranza();
        }

        inicializarNcNd(data);
    }

    // ---------------------------------------------------------------------
    // Wizard NC/ND (US4, 2 pasos)
    // ---------------------------------------------------------------------
    function inicializarNcNd(data) {
        const $modal = $('#modal-ncnd');
        if (!$modal.length) { return; }

        function irAPaso(n) {
            $('#ncnd-paso-1').toggleClass('d-none', n !== 1);
            $('#ncnd-paso-2').toggleClass('d-none', n !== 2);
            $('#btn-ncnd-siguiente').toggleClass('d-none', n !== 1);
            $('#btn-ncnd-volver, #btn-ncnd-guardar').toggleClass('d-none', n !== 2);
        }

        function renderItemsStock() {
            const $box = $('#ncnd-items').empty();
            (data.items || []).forEach((item) => {
                const id = 'ncnd-item-' + item.producto_id;
                const $row = $('<div class="row g-2 align-items-center mb-1">');
                $row.append($('<div class="col-6">').append(
                    $('<div class="form-check">').append(
                        $('<input type="checkbox" class="form-check-input ncnd-item-chk">').attr('id', id).data('producto', item.producto_id),
                        $('<label class="form-check-label">').attr('for', id).text(item.descripcion)
                    )
                ));
                $row.append($('<div class="col-6">').append(
                    $('<input type="number" step="0.001" class="form-control form-control-sm" placeholder="Cantidad">').addClass('ncnd-item-cant').data('producto', item.producto_id)
                ));
                $box.append($row);
            });
        }

        function toggleStockBloque() {
            const afecta = $('input[name="ncnd-afecta-stock"]:checked').val() === '1';
            $('#ncnd-stock-bloque').toggleClass('d-none', !afecta);
            $('#ncnd-descripcion-wrapper').toggleClass('d-none', afecta);
        }
        $('input[name="ncnd-afecta-stock"]').on('change', toggleStockBloque);

        $('#btn-agregar-nota').on('click', function () {
            $('#ncnd-documento').val(data.nroComprobante);
            $('#ncnd-fecha').val(new Date().toISOString().slice(0, 10));
            $('#ncnd-monto').val('');
            $('#ncnd-descripcion').val('');
            const $dep = $('#ncnd-deposito').empty();
            (data.depositos || []).forEach((d) => $dep.append(new Option(d.nombre, d.id)));
            renderItemsStock();
            toggleStockBloque();
            irAPaso(1);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ncnd')).show();
        });

        $('#btn-ncnd-siguiente').on('click', () => irAPaso(2));
        $('#btn-ncnd-volver').on('click', () => irAPaso(1));

        $('#btn-ncnd-guardar').on('click', function () {
            const afectaStock = $('input[name="ncnd-afecta-stock"]:checked').val() === '1';
            const payload = {
                tipo: $('#ncnd-tipo').val(),
                afecta_stock: afectaStock,
                fecha_emision: $('#ncnd-fecha').val(),
                monto: $('#ncnd-monto').val(),
                descripcion: $('#ncnd-descripcion').val(),
            };
            if (afectaStock) {
                payload.deposito_id = $('#ncnd-deposito').val();
                payload.items = [];
                $('.ncnd-item-chk:checked').each(function () {
                    const productoId = $(this).data('producto');
                    const cantidad = $('.ncnd-item-cant[data-producto="' + productoId + '"]').val();
                    if (cantidad) { payload.items.push({ producto_id: productoId, cantidad }); }
                });
            }

            $.post(window.VentasConfig.rutas.notasStore, payload)
                .done((resp) => {
                    toast('success', resp.mensaje || 'Nota creada.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-ncnd'))?.hide();
                    window.location.reload();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors)[0][0] : 'No se pudo crear la nota.'));
        });
    }
})();
