/**
 * Módulo NC/ND — página completa de Crear/Editar (spec 059), compartida entre
 * Ventas y Compras. Reemplaza el paso 2 del modal `_modal_ncnd.blade.php`
 * (spec 057), reusando el mismo patrón de tabla de ítems que `compras.js`.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[notas-credito-debito] jQuery no está disponible.');
        return;
    }

    const $tabla = $('#tabla-items');
    if (!$tabla.length) { return; }

    const data = window.NotaFormData || {};
    const cfg = window.NotaFormConfig || {};
    const rutas = cfg.rutas || {};

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true, progressBar: true, positionClass: 'toast-top-right',
            preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
        };
    }
    function toast(tipo, mensaje) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje, '');
        } else {
            console.log('[notas-credito-debito][' + tipo + ']', mensaje);
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
    function normalizarDecimal(v) {
        return String(v == null ? '' : v).replace(',', '.');
    }

    const notaExistente = data.notaCreditoDebito || null;
    const editando = !!notaExistente;

    let afectaStock = editando
        ? !!notaExistente.afecta_stock
        : String(data.queryString.afectaStock || '0') === '1';

    let items = [];
    if (editando && afectaStock) {
        items = (data.items || []).map((i) => ({
            producto_id: i.producto_id, descripcion: i.descripcion, cantidad: i.cantidad,
            precio: i.precio, descuento_pct: i.descuento_pct, iva_pct: i.iva_pct,
        }));
    } else if (editando && !afectaStock) {
        // Sin stock: una única fila fija — descripción libre, cantidad/precio/iva del primer ítem si existía.
        const primero = (data.items || [])[0] || {};
        items = [{
            producto_id: null,
            descripcion: data.descripcionLibre || '',
            cantidad: primero.cantidad || 1,
            precio: primero.precio || (notaExistente ? notaExistente.monto : 0),
            descuento_pct: primero.descuento_pct || 0,
            iva_pct: primero.iva_pct || null,
        }];
    } else {
        items = [{ producto_id: null, descripcion: '', cantidad: 1, precio: 0, descuento_pct: 0, iva_pct: null }];
    }

    // ---------------------------------------------------------------------
    // Precarga de cabecera
    // ---------------------------------------------------------------------
    initSelect2($('#f-tipo'));
    initSelect2($('#f-documento-ajusta'));
    initSelect2($('#f-deposito'), { placeholder: 'Seleccioná un Depósito' });

    if (editando) {
        $('#f-tipo').val(notaExistente.tipo).prop('disabled', true);
        $('#f-afecta-si, #f-afecta-no').prop('disabled', true);
        $('input[name="f-afecta-stock"][value="' + (afectaStock ? '1' : '0') + '"]').prop('checked', true);
        $('#f-mes-imputacion').val(notaExistente.mes_imputacion);
        $('#f-fecha-emision').val(notaExistente.fecha_emision);
        $('#f-tipo-comprobante').val(notaExistente.tipo_comprobante);
        $('#f-nro-comprobante').val(notaExistente.nro_comprobante);
    } else {
        const qs = data.queryString || {};
        $('#f-tipo').val(qs.tipo || 'credito').prop('disabled', false);
        $('input[name="f-afecta-stock"][value="' + (afectaStock ? '1' : '0') + '"]').prop('checked', true);
        $('#f-mes-imputacion').val(qs.mesImputacion || new Date().toISOString().slice(0, 7));
        $('#f-fecha-emision').val(new Date().toISOString().slice(0, 10));
    }
    refreshSelect2($('#f-tipo'));

    if (data.comprobanteOrigen) {
        $('#f-documento-ajusta').val(data.comprobanteOrigen.id);
        refreshSelect2($('#f-documento-ajusta'));
    }

    function depositoInicial() {
        if (editando) { return notaExistente.deposito_id || null; }
        return (data.queryString || {}).depositoId || null;
    }
    const depInicial = depositoInicial();
    if (depInicial) { $('#f-deposito').val(depInicial).trigger('change.select2'); }

    // ---------------------------------------------------------------------
    // Toggle Stock: producto vs. descripción libre (FR-004/FR-005)
    // ---------------------------------------------------------------------
    function toggleStock() {
        afectaStock = $('input[name="f-afecta-stock"]:checked').val() === '1';
        $('#f-deposito-wrapper, #f-producto-wrapper').toggleClass('d-none', !afectaStock);
        $('#th-producto').text(afectaStock ? 'Producto' : 'Descripción');
        if (!afectaStock) {
            // Colapsa a una única fila fija.
            items = [{
                producto_id: null,
                descripcion: items[0]?.descripcion || '',
                cantidad: items[0]?.cantidad || 1,
                precio: items[0]?.precio || 0,
                descuento_pct: items[0]?.descuento_pct || 0,
                iva_pct: items[0]?.iva_pct || null,
            }];
        } else if (items.length === 1 && !items[0].producto_id && !items[0].descripcion) {
            items = [];
        }
        renderItems();
    }
    $('input[name="f-afecta-stock"]').on('change', toggleStock);

    // ---------------------------------------------------------------------
    // Selector de Producto (sólo Stock=Sí)
    // ---------------------------------------------------------------------
    initSelect2($('#f-producto'), {
        placeholder: 'Buscar producto...',
        ajax: {
            url: rutas.productosOpciones,
            data: (params) => ({ q: params.term, incluir_servicios: 1 }),
            processResults: (resp) => ({ results: resp.data.map((p) => ({ id: p.id, text: '(' + p.id + ') ' + p.nombre + (p.codigo ? ' (' + p.codigo + ')' : ''), producto: p })) }),
        },
    });
    $('#f-producto').on('select2:select', function (e) {
        const producto = e.params.data.producto;
        items.unshift({ producto_id: producto.id, descripcion: producto.nombre, cantidad: 1, precio: producto.precio || producto.costo || 0, descuento_pct: null, iva_pct: null });
        renderItems();
        $(this).val(null).trigger('change');
    });

    // ---------------------------------------------------------------------
    // Render de la tabla de ítems (mismo patrón que compras.js)
    // ---------------------------------------------------------------------
    function renderItems() {
        const $body = $('#items-body').empty();
        items.forEach((item, idx) => {
            const cant = Number(item.cantidad) || 0;
            const precio = Number(item.precio) || 0;
            const descPct = Number(item.descuento_pct) || 0;
            const ivaPct = pctIva(item.iva_pct);
            const bruto = cant * precio;
            const subtotal = bruto - (bruto * descPct / 100);
            const subtotalConIva = subtotal + (subtotal * ivaPct / 100);

            const opcionesIva = ['', '5', '10.5', '21', '27', 'exento', 'no_gravado'];
            const etiquetas = { '': 'Elegir', '5': '5', '10.5': '10,5', '21': '21', '27': '27', exento: 'Exento', no_gravado: 'No Gravado' };
            const selectHtml = '<select class="form-select form-select-sm">' + opcionesIva.map((v) => '<option value="' + v + '"' + (v === (item.iva_pct || '') ? ' selected' : '') + '>' + etiquetas[v] + '</option>').join('') + '</select>';

            const $tr = $('<tr>');
            if (afectaStock) {
                $tr.append($('<td>').text(item.descripcion));
            } else {
                $tr.append($('<td>').append(
                    $('<textarea class="form-control form-control-sm" rows="1">').val(item.descripcion || '')
                        .on('input', function () { items[idx].descripcion = $(this).val(); })
                ));
            }
            $tr.append($('<td style="width:90px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').val(item.cantidad === undefined ? cant : item.cantidad).on('input', function () { items[idx].cantidad = normalizarDecimal($(this).val()); renderItems(); })));
            $tr.append($('<td style="width:110px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').val(item.precio === undefined ? precio : item.precio).on('input', function () { items[idx].precio = normalizarDecimal($(this).val()); renderItems(); })));
            $tr.append($('<td style="width:90px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').val(item.descuento_pct || '').on('input', function () { items[idx].descuento_pct = normalizarDecimal($(this).val()); renderItems(); })));
            $tr.append($('<td>').text(money(subtotal)));
            $tr.append($('<td style="width:110px">').append($(selectHtml).on('change', function () { items[idx].iva_pct = $(this).val() || null; renderItems(); })));
            $tr.append($('<td>').text(money(subtotalConIva)));
            if (afectaStock) {
                $tr.append($('<td>').append($('<button type="button" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => { items.splice(idx, 1); renderItems(); })));
            } else {
                $tr.append($('<td>'));
            }
            $body.append($tr);
        });
        recalcular();
    }

    function recalcular() {
        const descuentoGeneralPct = Number($('#f-descuento-general').val()) || 0;
        const factor = 1 - (descuentoGeneralPct / 100);

        let subtotalSinDescuento = 0;
        let subtotalConDescuento = 0;
        let totalConIva = 0;
        items.forEach((item) => {
            const cant = Number(item.cantidad) || 0;
            const precio = Number(item.precio) || 0;
            const descPct = Number(item.descuento_pct) || 0;
            const ivaPct = pctIva(item.iva_pct);
            const bruto = cant * precio;
            const subtotal = bruto - (bruto * descPct / 100);
            const subtotalConIva = subtotal + (subtotal * ivaPct / 100);
            subtotalSinDescuento += subtotal;
            subtotalConDescuento += subtotal * factor;
            totalConIva += subtotalConIva * factor;
        });
        const descuento = subtotalSinDescuento - subtotalConDescuento;

        $('#tot-subtotal-sin-descuento').text(money(subtotalSinDescuento));
        $('#tot-descuento').text(money(descuento));
        $('#tot-subtotal-con-descuento').text(money(subtotalConDescuento));
        $('#tot-total').text(money(totalConIva));
    }
    $('#f-descuento-general').on('input', recalcular);

    // Los bloques +Percepciones/+Impuestos Internos/+Intereses quedan fuera de alcance (FR-003).
    $('.js-concepto-noop').on('click', function (e) { e.preventDefault(); });

    toggleStock();

    // ---------------------------------------------------------------------
    // Guardar
    // ---------------------------------------------------------------------
    function totalActual() {
        const descuentoGeneralPct = Number($('#f-descuento-general').val()) || 0;
        const factor = 1 - (descuentoGeneralPct / 100);
        let total = 0;
        items.forEach((item) => {
            const cant = Number(item.cantidad) || 0;
            const precio = Number(item.precio) || 0;
            const descPct = Number(item.descuento_pct) || 0;
            const ivaPct = pctIva(item.iva_pct);
            const bruto = cant * precio;
            const subtotal = bruto - (bruto * descPct / 100);
            const subtotalConIva = subtotal + (subtotal * ivaPct / 100);
            total += subtotalConIva * factor;
        });
        return Math.round(total * 100) / 100;
    }

    function payload() {
        const p = {
            tipo: $('#f-tipo').val(),
            afecta_stock: afectaStock ? 1 : 0,
            mes_imputacion: $('#f-mes-imputacion').val(),
            fecha_emision: $('#f-fecha-emision').val(),
            monto: totalActual(),
            tipo_comprobante: $('#f-tipo-comprobante').val(),
            nro_comprobante: $('#f-nro-comprobante').val(),
        };
        if (afectaStock) {
            p.deposito_id = $('#f-deposito').val();
            p.items = items.filter((i) => i.producto_id).map((i) => ({
                producto_id: i.producto_id,
                cantidad: i.cantidad,
                precio: i.precio,
                descuento_pct: i.descuento_pct || 0,
                iva_pct: i.iva_pct || null,
            }));
        } else {
            p.descripcion = items[0]?.descripcion || '';
        }
        return p;
    }

    function validar() {
        if (afectaStock) {
            if (!$('#f-deposito').val()) { toast('error', 'Seleccioná un Depósito.'); return false; }
            if (!items.filter((i) => i.producto_id).length) { toast('error', 'Agregá al menos un producto.'); return false; }
        } else if (!items[0]?.descripcion) {
            toast('error', 'Ingresá una Descripción.');
            return false;
        }
        if (!$('#f-fecha-emision').val()) { toast('error', 'Ingresá la fecha de Emisión.'); return false; }
        if (!totalActual()) { toast('error', 'El total tiene que ser mayor a 0.'); return false; }
        return true;
    }

    let enviando = false;
    $('#btn-nota-guardar').on('click', function () {
        if (enviando || !validar()) { return; }
        enviando = true;
        if (window.AppBtn) { window.AppBtn.loading('#btn-nota-guardar', true); }

        const url = editando ? rutas.update : rutas.store;
        const method = editando ? 'PUT' : 'POST';

        $.ajax({ url, method, data: payload() })
            .done((resp) => {
                toast('success', resp.mensaje || 'Nota guardada.');
                window.location.href = rutas.volver;
            })
            .fail((xhr) => {
                if (xhr.status === 409) { toast('error', xhr.responseJSON?.mensaje || 'No se puede guardar esta nota.'); }
                else { toast('error', xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors)[0][0] : (xhr.responseJSON?.mensaje || 'No se pudo guardar la nota.')); }
                enviando = false;
                if (window.AppBtn) { window.AppBtn.loading('#btn-nota-guardar', false); }
            });
    });

    // ---------------------------------------------------------------------
    // Eliminar (sólo edición)
    // ---------------------------------------------------------------------
    $('#btn-nota-eliminar').on('click', function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-nota-pagina')).show();
    });
    $('#btn-confirmar-eliminar-nota-pagina').on('click', function () {
        if (!rutas.destroy) { return; }
        $.ajax({ url: rutas.destroy, method: 'DELETE' })
            .done((resp) => {
                toast('success', resp.mensaje || 'Nota eliminada.');
                window.location.href = rutas.volver;
            })
            .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar la nota.'));
    });
})();
