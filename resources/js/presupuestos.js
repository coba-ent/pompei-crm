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

    function money(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

        function filtrosActuales() {
            return {
                cliente_id: $('#filtro-cliente').val(),
                estado: $('#filtro-estado').val(),
                buscar: $('#filtro-buscar').val(),
            };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true, responsive: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ presupuestos', infoEmpty: 'Sin presupuestos',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron presupuestos',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: { url: rutas.data, data: (d) => $.extend(d, filtrosActuales()) },
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
        });

        // Reordenamos: "acciones" primero en el modelo de columnas pero la
        // tabla visual pide "Estado" primero — reutilizamos la misma columna
        // (el badge de estado abre el menú de fila).
        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => {
            $('#filtro-cliente').val(null).trigger('change');
            $('#filtro-estado').val('');
            $('#filtro-buscar').val('');
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

        initSelect2($('#f-categoria'), { placeholder: 'Seleccioná una Categoría', allowClear: true });
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

        // Precarga (edición o pre-carga desde Presupuesto → Venta).
        if (data.cliente) {
            const opt = new Option(data.cliente.nombre, data.cliente.id, true, true);
            $('#f-cliente').append(opt);
            refreshSelect2($('#f-cliente'));
        }
        if (data.presupuesto) {
            $('#f-categoria').val(data.presupuesto.categoria_id || '');
            $('#f-lista-precio').val(data.presupuesto.lista_precio_id || '');
            $('#f-descuento-general').val(data.presupuesto.descuento_general_pct || '');
        }
        refreshSelect2($('#f-categoria'));
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
        $('#f-cliente').on('select2:select', function (e) {
            const cliente = e.params.data.cliente;
            if (!cliente) { return; }
            if (cliente.categoria_id) { $('#f-categoria').val(cliente.categoria_id).trigger('change'); }
            if (cliente.descuento_general_pct !== null && cliente.descuento_general_pct !== undefined) {
                $('#f-descuento-general').val(cliente.descuento_general_pct);
                recalcular();
            }
        });

        $('#f-producto').on('select2:select', function (e) {
            const producto = e.params.data.producto;
            items.push({
                producto_id: producto.id,
                descripcion: producto.nombre,
                cantidad: 1,
                precio_unitario: producto.precio || 0,
                descuento_pct: null,
                iva_pct: producto.iva_venta_pct || '21',
            });
            renderItems();
            $(this).val(null).trigger('change');
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
                $tr.append($('<td style="width:90px">').append(
                    $('<input type="number" step="0.001" class="form-control form-control-sm js-item-cant">').val(cant).on('input', function () {
                        items[idx].cantidad = $(this).val(); renderItems();
                    })
                ));
                $tr.append($('<td style="width:110px">').append(
                    $('<input type="number" step="0.01" class="form-control form-control-sm js-item-precio">').val(precio).on('input', function () {
                        items[idx].precio_unitario = $(this).val(); renderItems();
                    })
                ));
                $tr.append($('<td style="width:90px">').append(
                    $('<input type="number" step="0.01" class="form-control form-control-sm">').val(item.descuento_pct || '').on('input', function () {
                        items[idx].descuento_pct = $(this).val(); renderItems();
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
        }

        function renderConceptos() {
            const $body = $('#conceptos-body').empty();
            const etiquetas = { percepcion: 'Percepción', impuesto_interno: 'Impuesto Interno', interes: 'Interés' };
            conceptos.forEach((c, idx) => {
                const $row = $('<div class="input-group input-group-sm mb-2">');
                $row.append($('<span class="input-group-text">').text(etiquetas[c.tipo] || c.tipo));
                $row.append($('<input type="text" class="form-control" placeholder="Concepto">').val(c.concepto || '').on('input', function () {
                    conceptos[idx].concepto = $(this).val();
                }));
                $row.append($('<input type="number" step="0.01" class="form-control" placeholder="Monto">').val(c.monto || '').on('input', function () {
                    conceptos[idx].monto = $(this).val(); recalcular();
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

        // "Crear Categoría de ventas" inline.
        $('#btn-nueva-categoria').on('click', () => {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria')).show();
        });
        $('#btn-crear-categoria').on('click', function () {
            const nombre = $('#nueva-categoria-nombre').val();
            if (!nombre) { return; }
            $.post(rutas.categoriaVentaStore, { nombre })
                .done((resp) => {
                    const opt = new Option(resp.categoria.nombre, resp.categoria.id, true, true);
                    $('#f-categoria').append(opt).trigger('change');
                    $('#nueva-categoria-nombre').val('');
                    bootstrap.Modal.getInstance(document.getElementById('modal-nueva-categoria'))?.hide();
                    toast('success', 'Categoría creada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo crear la categoría.'));
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
            $('#btn-guardar-presupuesto').prop('disabled', true);

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
                    $('#btn-guardar-presupuesto').prop('disabled', false);
                });
        });
    }
})();
