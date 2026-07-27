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
            placeholder: 'Todos', allowClear: true,
            ajax: {
                url: rutas.proveedoresOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({ results: data.data.map((p) => ({ id: p.id, text: p.nombre })) }),
            },
        });

        function filtrosActuales() {
            return { proveedor_id: $('#filtro-proveedor').val(), buscar: $('#filtro-buscar').val() };
        }

        const tabla = $tabla.DataTable({
            processing: true, serverSide: true, responsive: true,
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
            ],
        });

        $('#btn-aplicar-filtros').on('click', () => tabla.ajax.reload());
        $('#btn-limpiar-filtros').on('click', () => {
            $('#filtro-proveedor').val(null).trigger('change');
            $('#filtro-buscar').val('');
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

        initSelect2($('#f-categoria'), { placeholder: 'Seleccioná una Categoría', allowClear: true });

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
                processResults: (resp) => ({ results: resp.data.map((p) => ({ id: p.id, text: p.nombre + (p.codigo ? ' (' + p.codigo + ')' : ''), producto: p })) }),
            },
        });

        if (data.proveedor) {
            $('#f-proveedor').append(new Option(data.proveedor.nombre, data.proveedor.id, true, true));
            refreshSelect2($('#f-proveedor'));
        }
        if (data.categoriaId) { $('#f-categoria').val(data.categoriaId); }
        refreshSelect2($('#f-categoria'));
        if (data.compra && data.compra.tipo_comprobante) { $('#f-tipo-comprobante').val(data.compra.tipo_comprobante); }
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
        $('#f-producto').on('select2:select', function (e) {
            const producto = e.params.data.producto;
            items.push({ producto_id: producto.id, descripcion: producto.nombre, cantidad: 1, precio_unitario: producto.costo || 0, descuento_pct: null, iva_pct: null });
            renderItems();
            $(this).val(null).trigger('change');
        });

        function renderItems() {
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
                $tr.append($('<td>').text(item.descripcion));
                $tr.append($('<td style="width:90px">').append($('<input type="number" step="0.001" class="form-control form-control-sm">').val(cant).on('input', function () { items[idx].cantidad = $(this).val(); renderItems(); })));
                $tr.append($('<td style="width:110px">').append($('<input type="number" step="0.01" class="form-control form-control-sm">').val(precio).on('input', function () { items[idx].precio_unitario = $(this).val(); renderItems(); })));
                $tr.append($('<td style="width:90px">').append($('<input type="number" step="0.01" class="form-control form-control-sm">').val(item.descuento_pct || '').on('input', function () { items[idx].descuento_pct = $(this).val(); renderItems(); })));
                $tr.append($('<td>').text(money(subtotal)));
                $tr.append($('<td style="width:110px">').append($(selectHtml).on('change', function () { items[idx].iva_pct = $(this).val() || null; renderItems(); })));
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
            let hayGravado = false;
            items.forEach((item) => {
                const cant = Number(item.cantidad) || 0;
                const precio = Number(item.precio_unitario) || 0;
                const descPct = Number(item.descuento_pct) || 0;
                const ivaPct = pctIva(item.iva_pct);
                if (item.iva_pct) { hayGravado = true; }
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

            $('#lbl-importe-neto').text(hayGravado ? 'Importe Neto Gravado' : 'Importe Neto No Gravado');
            $('#tot-subtotal-sin-descuento').text(money(subtotalSinDescuento));
            $('#tot-descuento').text(money(descuento));
            $('#tot-subtotal-con-descuento').text(money(subtotalConDescuento));
            $('#tot-total').text(money(total));
        }

        renderItems();
        renderConceptos();

        $('#btn-nueva-categoria').on('click', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nueva-categoria')).show());
        $('#btn-crear-categoria').on('click', function () {
            const nombre = $('#nueva-categoria-nombre').val();
            if (!nombre) { return; }
            $.post(rutas.categoriaCompraStore, { nombre })
                .done((resp) => {
                    $('#f-categoria').append(new Option(resp.categoria.nombre, resp.categoria.id, true, true)).trigger('change');
                    $('#nueva-categoria-nombre').val('');
                    bootstrap.Modal.getInstance(document.getElementById('modal-nueva-categoria'))?.hide();
                    toast('success', 'Categoría creada.');
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo crear la categoría.'));
        });

        function payload() {
            return {
                submit_token: cfg.submitToken,
                proveedor_id: $('#f-proveedor').val(),
                categoria_id: $('#f-categoria').val() || null,
                fecha_emision: $('#f-fecha-emision').val(),
                tipo_comprobante: $('#f-tipo-comprobante').val(),
                fecha_vto_pago: $('#f-fecha-vto-pago').val() || null,
                mes_imputacion_iva: $('#f-mes-imputacion-iva').val() ? $('#f-mes-imputacion-iva').val() + '-01' : null,
                descuento_general_pct: $('#f-descuento-general').val() || null,
                nota_interna: $('#f-nota-interna').val(),
                items: items,
                conceptos: conceptos.filter((c) => c.concepto),
            };
        }

        function validar() {
            if (!$('#f-proveedor').val()) { toast('error', 'Seleccioná un proveedor.'); return false; }
            if (!items.length) { toast('error', 'Agregá al menos un ítem.'); return false; }
            return true;
        }

        let enviando = false;
        $('#btn-guardar-compra').on('click', function () {
            if (enviando || !validar()) { return; }
            enviando = true;
            $('#btn-guardar-compra').prop('disabled', true);

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
                    $('#btn-guardar-compra').prop('disabled', false);
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
    // Wizard NC/ND (US4, 2 pasos) — mismo patrón que ventas.js
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

        $('#btn-agregar-nota').on('click', function () {
            $('#ncnd-documento').val(data.nroComprobante);
            $('#ncnd-fecha').val(new Date().toISOString().slice(0, 10));
            $('#ncnd-monto').val('');
            $('#ncnd-descripcion').val('');
            irAPaso(1);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-ncnd')).show();
        });

        $('#btn-ncnd-siguiente').on('click', () => irAPaso(2));
        $('#btn-ncnd-volver').on('click', () => irAPaso(1));

        $('#btn-ncnd-guardar').on('click', function () {
            const payload = {
                tipo: $('#ncnd-tipo').val(),
                afecta_stock: false,
                fecha_emision: $('#ncnd-fecha').val(),
                monto: $('#ncnd-monto').val(),
                descripcion: $('#ncnd-descripcion').val(),
            };

            $.post(window.ComprasConfig.rutas.notasStore, payload)
                .done((resp) => {
                    toast('success', resp.mensaje || 'Nota creada.');
                    bootstrap.Modal.getInstance(document.getElementById('modal-ncnd'))?.hide();
                    window.location.reload();
                })
                .fail((xhr) => toast('error', xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors)[0][0] : 'No se pudo crear la nota.'));
        });
    }
})();
