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

    // Mismo patrón que compras.js/ventas.js/presupuestos.js: `renderItems()` reconstruye
    // toda la tabla en cada tecla (para recalcular subtotales en vivo), lo que le hacía
    // perder el foco al input activo — capturarFoco/restaurarFoco lo re-enfocan después
    // de re-renderizar, usando `data-idx`/`data-field` para ubicar el mismo input.
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

    const notaExistente = data.notaCreditoDebito || null;
    const editando = !!notaExistente;
    let itemsDisponibles = [];
    let conceptos = Array.isArray(data.conceptos) && data.conceptos.length ? data.conceptos.slice() : [];

    let afectaStock = editando
        ? !!notaExistente.afecta_stock
        : String(data.queryString.afectaStock || '0') === '1';

    let items = [];
    if (editando && afectaStock) {
        items = (data.items || []).map((i) => ({
            producto_id: i.producto_id, descripcion: i.descripcion, cantidad: i.cantidad,
            precio: i.precio, descuento_pct: i.descuento_pct, iva_pct: i.iva_pct,
            // Spec 096: se conserva la referencia de línea que la nota ya tenía guardada.
            item_origen_id: i.item_origen_id || null,
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

        // Esta rama colapsa la nota a UNA fila, así que si el detalle real no entra en una sola
        // (notas migradas de Contagram: se les reconstruyó el detalle desde la venta y muchas
        // tienen varios productos) la fila valdría menos que la nota, y guardar bajaría el monto
        // sin que nadie lo pida. Cuando la fila no reproduce el importe, se la reemplaza por una
        // línea única que vale exactamente `monto`: el detalle se pierde en el form —donde ya no
        // era representable—, pero la plata queda intacta.
        const montoNota = Number(notaExistente ? notaExistente.monto : 0) || 0;
        const bruto = (Number(items[0].cantidad) || 0) * (Number(items[0].precio) || 0);
        const neto = bruto - (bruto * (Number(items[0].descuento_pct) || 0) / 100);
        const reconstruido = neto + (neto * pctIva(items[0].iva_pct) / 100);

        if (montoNota > 0 && Math.abs(reconstruido - montoNota) > 0.01) {
            items = [{
                producto_id: null,
                descripcion: data.descripcionLibre || '',
                cantidad: 1,
                precio: montoNota,
                descuento_pct: 0,
                iva_pct: null,
            }];
        }
    } else if (afectaStock) {
        // Crear + Stock=Sí: arranca vacío, `cargarItemsDisponibles()` (más abajo) lo
        // completa con TODOS los ítems pendientes de la Venta/Compra apenas resuelve —
        // no hay selector manual de producto (ver nota más abajo, era la fuente de los
        // bugs de "no se agrega al hacer click" y de la validación de cantidad máxima).
        items = [];
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
        AppFecha.set($('#f-fecha-emision'), notaExistente.fecha_emision);
        $('#f-tipo-comprobante').val(notaExistente.tipo_comprobante);
        $('#f-nro-comprobante').val(notaExistente.nro_comprobante);
        $('#f-nota-interna').val(notaExistente.nota_interna);
    } else {
        const qs = data.queryString || {};
        $('#f-tipo').val(qs.tipo || 'credito').prop('disabled', false);
        $('input[name="f-afecta-stock"][value="' + (afectaStock ? '1' : '0') + '"]').prop('checked', true);
        $('#f-mes-imputacion').val(qs.mesImputacion || new Date().toISOString().slice(0, 7));
        AppFecha.set($('#f-fecha-emision'), AppFecha.hoy());
    }
    refreshSelect2($('#f-tipo'));

    if (data.comprobanteOrigen) {
        $('#f-documento-ajusta').val(data.comprobanteOrigen.id);
        refreshSelect2($('#f-documento-ajusta'));
    }

    // ---------------------------------------------------------------------
    // Espejo del comprobante de origen (spec 095)
    //
    // Hasta acá el alta precargaba SÓLO los ítems: el descuento general y el tipo de
    // comprobante nacían vacíos, así que una NC sobre una venta con 5% de descuento
    // proponía el total SIN descuento — $11.497,80 de más en la venta 24740.
    //
    // `cabecera` llega null en edición a propósito (FR-011): ahí manda la nota existente.
    // ---------------------------------------------------------------------
    const cabeceraOrigen = (!editando && data.comprobanteOrigen) ? (data.comprobanteOrigen.cabecera || null) : null;

    if (cabeceraOrigen) {
        // FR-004: el tipo se DERIVA del comprobante. Si el origen no tiene, queda vacío:
        // no se infiere ninguno, porque una nota con el tipo cruzado no se corrige editándola.
        if (cabeceraOrigen.tipoComprobante) {
            $('#f-tipo-comprobante').val(cabeceraOrigen.tipoComprobante);
        }

        // FR-005: las fechas llegan en ISO y AppFecha las muestra en dd/mm/aaaa. El form tiene
        // el vencimiento y el "servicio desde" en un solo campo, así que el vencimiento manda
        // y el servicio cubre el caso del comprobante que no tiene vencimiento cargado.
        if (cabeceraOrigen.fechaEmision) { AppFecha.set($('#f-fecha-emision'), cabeceraOrigen.fechaEmision); }
        const vtoDesde = cabeceraOrigen.fechaVencimiento || cabeceraOrigen.servicioDesde;
        if (vtoDesde) { AppFecha.set($('#f-vto-desde'), vtoDesde); }
        if (cabeceraOrigen.servicioHasta) { AppFecha.set($('#f-vto-hasta'), cabeceraOrigen.servicioHasta); }

        // FR-007: percepciones e impuestos internos del comprobante.
        if (Array.isArray(cabeceraOrigen.conceptos) && cabeceraOrigen.conceptos.length) {
            conceptos = cabeceraOrigen.conceptos.map((c) => ({
                tipo: c.tipo,
                concepto: c.concepto,
                monto: c.monto,
            }));
        }
    }

    function depositoInicial() {
        if (editando) { return notaExistente.deposito_id || null; }
        // Al crear, el depósito correcto es el del comprobante que se está ajustando:
        // una NC tiene que reponer donde la Venta descontó. El query string sigue
        // teniendo prioridad por si la pantalla que abre el form ya lo resolvió.
        return (data.queryString || {}).depositoId
            || (data.comprobanteOrigen || {}).depositoId
            || null;
    }
    const depInicial = depositoInicial();
    // Sin valor se deja en blanco a propósito: la validación obliga a elegirlo (FR-004),
    // que es preferible a arrastrar el primero del listado como si fuera el real.
    $('#f-deposito').val(depInicial || '').trigger('change.select2');

    // ---------------------------------------------------------------------
    // Toggle Stock: productos de la Venta/Compra vs. descripción libre (FR-004/FR-005)
    // ---------------------------------------------------------------------
    function toggleStock() {
        afectaStock = $('input[name="f-afecta-stock"]:checked').val() === '1';
        $('#f-deposito-wrapper').toggleClass('d-none', !afectaStock);
        $('#th-producto').text(afectaStock ? 'Producto' : 'Descripción');
        if (!afectaStock) {
            // Colapsa a una única fila fija. Si el usuario venía de Stock=Sí, no hay
            // descripción libre previa que conservar (esos ítems eran de productos).
            items = [{
                producto_id: null,
                descripcion: items[0]?.producto_id ? '' : (items[0]?.descripcion || ''),
                cantidad: items[0]?.producto_id ? 1 : (items[0]?.cantidad || 1),
                precio: items[0]?.producto_id ? 0 : (items[0]?.precio || 0),
                descuento_pct: items[0]?.producto_id ? 0 : (items[0]?.descuento_pct || 0),
                iva_pct: items[0]?.producto_id ? null : (items[0]?.iva_pct || null),
            }];
            renderItems();
        } else {
            // Precarga (o recalcula _max sobre) los ítems pendientes de la Venta/Compra.
            cargarItemsDisponibles();
        }
    }
    $('input[name="f-afecta-stock"]').on('change', toggleStock);

    // ---------------------------------------------------------------------
    // Ítems pendientes de ajuste (sólo Stock=Sí) — se precargan TODOS de una,
    // sin selector manual: el usuario ajusta cantidades o borra la fila del
    // producto que no quiere tocar (research.md §4 + AjustesPendientesNotaCreditoDebito).
    // Un selector manual (catálogo libre o restringido) era la fuente de dos bugs:
    // el backend rechazaba productos fuera de la Venta/Compra con "cantidad máxima
    // disponible... es 0", y el <select> nativo auto-seleccionaba el primer producto
    // (o el único, cuando sólo había uno) sin disparar el evento de selección de select2.
    // ---------------------------------------------------------------------
    // Match por LÍNEA cuando el renglón la identifica (spec 099): con varias líneas del mismo
    // producto, buscar sólo por producto_id devuelve siempre la primera y le asigna a todos los
    // renglones el máximo de esa. Es el mismo criterio que ahora usa la validación del servidor.
    function disponibleDe(it) {
        if (it.item_origen_id) {
            const porLinea = itemsDisponibles.find((d) => d.item_origen_id === it.item_origen_id);
            if (porLinea) { return porLinea; }
        }

        return itemsDisponibles.find((d) => d.producto_id === it.producto_id);
    }

    function actualizarMaximosItems() {
        items.forEach((it) => {
            if (!it.producto_id) { return; }
            const encontrado = disponibleDe(it);
            if (encontrado) { it._max = encontrado.pendiente; }
        });
    }

    function cargarItemsDisponibles() {
        if (!rutas.itemsDisponibles) { return; }
        $.getJSON(rutas.itemsDisponibles)
            .done((resp) => {
                itemsDisponibles = resp.data || [];
                if (editando) {
                    // La ruta de disponibles no excluye esta misma nota de "ya ajustado" —
                    // se le devuelve acá lo que ella misma consume (mismo ajuste que ya
                    // hacía `abrirEdicionNota` en el modal viejo, ventas.js/compras.js).
                    (data.items || []).forEach((it) => {
                        const existente = disponibleDe(it);
                        if (existente) {
                            existente.pendiente = Math.round((existente.pendiente + it.cantidad) * 1000) / 1000;
                        } else {
                            itemsDisponibles.push({
                                producto_id: it.producto_id,
                                descripcion: it.descripcion || ('Producto #' + it.producto_id),
                                pendiente: it.cantidad,
                                item_origen_id: it.item_origen_id || null,
                            });
                        }
                    });
                    actualizarMaximosItems();
                } else if (afectaStock) {
                    // Precarga precio/descuento/IVA con los que ya tenía el comprobante de
                    // origen para ese producto — el usuario los puede editar igual si la
                    // nota corresponde a un monto distinto.
                    //
                    // Spec 096: cada elemento de itemsDisponibles es ya una LÍNEA independiente
                    // (no fusionada por producto — ver AjustesPendientesNotaCreditoDebito). Si el
                    // comprobante repite un producto, acá llegan varios elementos con el mismo
                    // producto_id y distinto item_origen_id/precio/descuento_pct: el .map() 1:1
                    // los respeta sin deduplicar.
                    items = itemsDisponibles.map((d) => ({
                        producto_id: d.producto_id, descripcion: d.descripcion, cantidad: d.pendiente,
                        precio: d.precio || 0, descuento_pct: d.descuento_pct || 0, iva_pct: d.iva_pct || null, _max: d.pendiente,
                        item_origen_id: d.item_origen_id || null,
                    }));
                }
                renderItems();
            })
            .fail(() => { itemsDisponibles = []; });
    }

    // ---------------------------------------------------------------------
    // Render de la tabla de ítems (mismo patrón que compras.js)
    // ---------------------------------------------------------------------
    function renderItems() {
        const foco = capturarFoco('#items-body');
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
            // item.iva_pct puede venir del backend como number (21) o string con dos decimales
            // ("21.00", cast decimal:2 de NotaCreditoDebitoItem) — comparar como float en vez de
            // string estricta, si no la precarga en edición nunca marcaba ninguna opción.
            const ivaSeleccionado = (v) => {
                if (v === '') { return item.iva_pct === null || item.iva_pct === undefined || item.iva_pct === ''; }
                if (v === 'exento' || v === 'no_gravado') { return v === item.iva_pct; }
                return item.iva_pct !== null && item.iva_pct !== undefined && item.iva_pct !== '' && parseFloat(v) === parseFloat(item.iva_pct);
            };
            const selectHtml = '<select class="form-select form-select-sm">' + opcionesIva.map((v) => '<option value="' + v + '"' + (ivaSeleccionado(v) ? ' selected' : '') + '>' + etiquetas[v] + '</option>').join('') + '</select>';

            const $tr = $('<tr>');
            if (afectaStock) {
                const $celda = $('<td>').text((item.producto_id ? '(' + item.producto_id + ') ' : '') + (item.descripcion || ''));

                // Con varias líneas del mismo producto la descripción se repite y no hay forma de
                // saber cuál renglón es cuál: la compra 2478 muestra tres veces "99999". El importe
                // de la línea es lo único que las distingue (spec 099, FR-006).
                if (item.item_origen_id && items.filter((o) => o.producto_id === item.producto_id).length > 1) {
                    $celda.append(
                        $('<small class="text-muted d-block">').text(
                            'Línea de $' + Number(item.precio || 0).toLocaleString('es-AR', { minimumFractionDigits: 2 })
                        )
                    );
                }

                $tr.append($celda);
            } else {
                $tr.append($('<td>').append(
                    $('<textarea class="form-control form-control-sm" rows="1">').attr('data-idx', idx).attr('data-field', 'descripcion').val(item.descripcion || '')
                        .on('input', function () { items[idx].descripcion = $(this).val(); })
                ));
            }
            $tr.append($('<td style="width:90px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').attr('data-idx', idx).attr('data-field', 'cantidad').val(item.cantidad === undefined ? cant : item.cantidad).on('input', function () {
                let v = normalizarDecimal($(this).val());
                if (item._max != null && parseFloat(v) > item._max) { v = String(item._max); toast('error', 'La cantidad máxima disponible para ajustar es ' + item._max + '.'); }
                items[idx].cantidad = v;
                renderItems();
            })));
            $tr.append($('<td style="width:110px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm" placeholder="Precio">').attr('data-idx', idx).attr('data-field', 'precio').val(item.precio ? item.precio : '').on('input', function () { items[idx].precio = normalizarDecimal($(this).val()); renderItems(); })));
            $tr.append($('<td style="width:90px">').append($('<input type="text" inputmode="decimal" class="form-control form-control-sm">').attr('data-idx', idx).attr('data-field', 'descuento_pct').val(item.descuento_pct || '').on('input', function () { items[idx].descuento_pct = normalizarDecimal($(this).val()); renderItems(); })));
            $tr.append($('<td>').text(money(subtotal)));
            $tr.append($('<td style="width:110px">').append($(selectHtml).attr('data-idx', idx).attr('data-field', 'iva_pct').on('change', function () { items[idx].iva_pct = $(this).val() || null; renderItems(); })));
            $tr.append($('<td>').text(money(subtotalConIva)));
            if (afectaStock) {
                $tr.append($('<td>').append($('<button type="button" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>').on('click', () => { items.splice(idx, 1); renderItems(); })));
            } else {
                $tr.append($('<td>'));
            }
            $body.append($tr);
        });
        restaurarFoco('#items-body', foco);
        recalcular();
    }

    // Toggle %/$ inline del Descuento General (spec 060). NC/ND calcula el monto final
    // client-side (research.md §R4) — no hay CalculoComprobante acá, así que en modo `monto`
    // el valor ingresado se usa directamente como importe a restar, sin pasar por un % efectivo.
    function setModoDescuentoGeneral(modo, limpiarValor) {
        const $btn = $('#f-descuento-general-toggle');
        const $label = $('#f-descuento-general-label');
        const $input = $('#f-descuento-general');
        $btn.data('modo', modo);
        if (modo === 'monto') {
            $btn.text('$');
            $label.text('Descuento General ($)');
            $input.attr('max', null);
        } else {
            $btn.text('%');
            $label.text('Descuento General (%)');
            $input.attr('max', 100);
        }
        if (limpiarValor) { $input.val(''); }
        recalcular();
    }
    $('#f-descuento-general-toggle').on('click', function () {
        const modoActual = $(this).data('modo') || 'porcentaje';
        setModoDescuentoGeneral(modoActual === 'porcentaje' ? 'monto' : 'porcentaje', true);
    });

    function subtotalSinDescuentoActual() {
        let subtotal = 0;
        items.forEach((item) => {
            const cant = Number(item.cantidad) || 0;
            const precio = Number(item.precio) || 0;
            const descPct = Number(item.descuento_pct) || 0;
            const bruto = cant * precio;
            subtotal += bruto - (bruto * descPct / 100);
        });
        return subtotal;
    }

    function recalcular() {
        const modoDescuentoGeneral = $('#f-descuento-general-toggle').data('modo') || 'porcentaje';
        const valorDescuentoGeneral = Number($('#f-descuento-general').val()) || 0;
        const subtotalSinDescuento = subtotalSinDescuentoActual();

        let factor = 1;
        if (modoDescuentoGeneral === 'monto') {
            factor = subtotalSinDescuento > 0 ? Math.max(0, 1 - (valorDescuentoGeneral / subtotalSinDescuento)) : 1;
        } else {
            factor = 1 - (valorDescuentoGeneral / 100);
        }

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
            subtotalConDescuento += subtotal * factor;
            totalConIva += subtotalConIva * factor;
        });
        const descuento = subtotalSinDescuento - subtotalConDescuento;
        const totalConceptos = conceptos.reduce((acc, c) => acc + (Number(c.monto) || 0), 0);

        $('#tot-subtotal-sin-descuento').text(money(subtotalSinDescuento));
        $('#tot-descuento').text(money(descuento));
        $('#tot-subtotal-con-descuento').text(money(subtotalConDescuento));
        $('#tot-total').text(money(totalConIva + totalConceptos));
    }
    $('#f-descuento-general').on('input', recalcular);

    // Precarga del modo/valor del Descuento General. En EDICIÓN manda la nota existente (US2,
    // FR-011); en ALTA lo hereda del comprobante de origen (spec 095, FR-002), que hasta ahora
    // nacía vacío y hacía que la nota propusiera el total SIN descuento.
    //
    // El descuento general se hereda CON su modalidad, sin convertir entre porcentaje y monto:
    // pasar de un modo al otro introduce un redondeo en un documento con efecto fiscal.
    // FR-003: va en la CABECERA, no prorrateado en las líneas — el descuento por línea de cada
    // ítem queda intacto y no se aplica dos veces, porque recalcular() los combina como
    // factores separados.
    //
    // `false` en el segundo argumento evita que el toggle limpie el valor recién puesto.
    //
    // Por qué el descuento general NO se aplica dos veces: `itemsDisponibles()` sirve el
    // `precio_unitario` BRUTO del ítem (venta 24740: 3.572,30), no el `subtotal` que la venta
    // guarda ya descontado. Sin heredar el descuento, el front calcula $229.956,12 — los
    // $11.497,80 de más del reporte original. Heredándolo da $218.458,32, el total real.
    const descuentoOrigen = editando
        ? {
            tipo: notaExistente && notaExistente.descuento_general_tipo,
            pct: notaExistente && notaExistente.descuento_general_pct,
            monto: notaExistente && notaExistente.descuento_general_monto,
        }
        : {
            tipo: cabeceraOrigen && cabeceraOrigen.descuentoGeneralTipo,
            pct: cabeceraOrigen && cabeceraOrigen.descuentoGeneralPct,
            monto: cabeceraOrigen && cabeceraOrigen.descuentoGeneralMonto,
        };

    setModoDescuentoGeneral(descuentoOrigen.tipo || 'porcentaje', false);
    if (descuentoOrigen.tipo === 'monto') {
        if (descuentoOrigen.monto !== undefined && descuentoOrigen.monto !== null) {
            $('#f-descuento-general').val(descuentoOrigen.monto);
        }
    } else if (descuentoOrigen.pct !== undefined && descuentoOrigen.pct !== null) {
        $('#f-descuento-general').val(descuentoOrigen.pct);
    }
    // T010: deja el total propuesto ya calculado al abrir, sin esperar a que se toque un campo.
    recalcular();

    // Percepciones/Impuestos Internos/Intereses (spec 061) — mismo patrón que compras.js/ventas.js.
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
    renderConceptos();

    toggleStock();

    // ---------------------------------------------------------------------
    // Guardar
    // ---------------------------------------------------------------------
    function totalActual() {
        const modoDescuentoGeneral = $('#f-descuento-general-toggle').data('modo') || 'porcentaje';
        const valorDescuentoGeneral = Number($('#f-descuento-general').val()) || 0;
        const subtotalSinDescuento = subtotalSinDescuentoActual();
        let factor;
        if (modoDescuentoGeneral === 'monto') {
            factor = subtotalSinDescuento > 0 ? Math.max(0, 1 - (valorDescuentoGeneral / subtotalSinDescuento)) : 1;
        } else {
            factor = 1 - (valorDescuentoGeneral / 100);
        }
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
        const totalConceptos = conceptos.reduce((acc, c) => acc + (Number(c.monto) || 0), 0);
        return Math.round((total + totalConceptos) * 100) / 100;
    }

    function payload() {
        const p = {
            tipo: $('#f-tipo').val(),
            afecta_stock: afectaStock ? 1 : 0,
            mes_imputacion: $('#f-mes-imputacion').val(),
            fecha_emision: AppFecha.get($('#f-fecha-emision')),
            monto: totalActual(),
            tipo_comprobante: $('#f-tipo-comprobante').val(),
            nro_comprobante: $('#f-nro-comprobante').val(),
            nota_interna: $('#f-nota-interna').val(),
            descuento_general_tipo: $('#f-descuento-general-toggle').data('modo') || 'porcentaje',
            descuento_general_pct: ($('#f-descuento-general-toggle').data('modo') || 'porcentaje') === 'porcentaje' ? ($('#f-descuento-general').val() || null) : null,
            descuento_general_monto: ($('#f-descuento-general-toggle').data('modo') || 'porcentaje') === 'monto' ? ($('#f-descuento-general').val() || null) : null,
            conceptos: conceptos.filter((c) => c.concepto),
        };
        if (afectaStock) {
            p.deposito_id = $('#f-deposito').val();
            p.items = items.filter((i) => i.producto_id).map((i) => ({
                producto_id: i.producto_id,
                cantidad: i.cantidad,
                precio: i.precio,
                descuento_pct: i.descuento_pct || 0,
                iva_pct: i.iva_pct || null,
                // Spec 096 (FR-004): referencia a la línea del comprobante que este ítem ajusta,
                // cuando viene de la precarga. Si el usuario agregó la línea a mano, viaja null.
                item_origen_id: i.item_origen_id || null,
            }));
        } else {
            p.descripcion = items[0]?.descripcion || '';
            // Se persiste igual como ítem (precio/IVA/desc.) aunque no afecte stock, para que
            // la edición pueda reconstruir el IVA seleccionado — antes se perdía en silencio.
            p.items = [{
                producto_id: null,
                cantidad: items[0]?.cantidad || 1,
                precio: items[0]?.precio || 0,
                descuento_pct: items[0]?.descuento_pct || 0,
                iva_pct: items[0]?.iva_pct || null,
            }];
        }
        return p;
    }

    function validar() {
        if (afectaStock) {
            if (!$('#f-deposito').val()) { toast('error', 'Seleccioná un Depósito.'); return false; }
            const itemsProducto = items.filter((i) => i.producto_id);
            if (!itemsProducto.length) { toast('error', 'Agregá al menos un producto.'); return false; }
            // Cantidad en 0/vacía: si no querés ajustar un producto, se borra la fila
            // (botón papelera) — no se deja en 0. Sin este chequeo, el backend lo rechaza
            // igual pero con el mensaje crudo sin traducir ("items.0.cantidad field must
            // be greater than 0").
            if (itemsProducto.some((i) => !(Number(i.cantidad) > 0))) {
                toast('error', 'La cantidad de cada producto tiene que ser mayor a 0 — si no lo querés ajustar, borrá la fila.');
                return false;
            }
            // La precarga sólo trae la cantidad pendiente (el endpoint de disponibles no
            // expone precio) — el Precio arranca en $0 y hay que completarlo a mano. Sin
            // este chequeo puntual, "El total tiene que ser mayor a 0" no deja claro qué
            // campo falta.
            if (itemsProducto.some((i) => !(Number(i.precio) > 0))) {
                toast('error', 'Ingresá un Precio mayor a 0 para cada producto.');
                return false;
            }
        } else if (!items[0]?.descripcion) {
            toast('error', 'Ingresá una Descripción.');
            return false;
        }
        if (!AppFecha.get($('#f-fecha-emision'))) { toast('error', 'Ingresá la fecha de Emisión.'); return false; }

        // FR-012/FR-015 (spec 095): el descuento general en modo monto puede superar el subtotal
        // de las líneas que quedaron — típicamente al borrar filas de una precarga. Se evalúa
        // acá, AL GUARDAR, y no mientras se edita: durante la edición un estado intermedio
        // inválido es normal y avisar en cada tecla sería ruido. El sistema no reajusta el
        // descuento solo: avisa y deja que la persona decida.
        if ($('#f-descuento-general-toggle').data('modo') === 'monto') {
            const descuento = Number($('#f-descuento-general').val()) || 0;
            if (descuento > subtotalSinDescuentoActual()) {
                toast('error', 'El Descuento General supera el subtotal de las líneas: corregilo antes de guardar.');
                return false;
            }
        }

        if (!totalActual()) { toast('error', 'El total tiene que ser mayor a 0.'); return false; }
        return true;
    }

    // FR-004a (spec 095): el tipo de comprobante se puede cambiar, pero si difiere del
    // comprobante de origen se avisa una vez antes de guardar. Informa, no bloquea: hay casos
    // legítimos, pero una nota emitida con el tipo cruzado NO se corrige editándola después.
    let avisoTipoCruzadoMostrado = false;
    function tipoCruzadoConfirmado() {
        const tipoOrigen = cabeceraOrigen && cabeceraOrigen.tipoComprobante;
        const tipoElegido = $('#f-tipo-comprobante').val();
        if (!tipoOrigen || !tipoElegido || tipoElegido === tipoOrigen) { return true; }
        if (avisoTipoCruzadoMostrado) { return true; }
        avisoTipoCruzadoMostrado = true;
        toast('warning', 'El Tipo de Comprobante (' + tipoElegido + ') no coincide con el del comprobante de origen (' + tipoOrigen + '). Una nota emitida con el tipo cruzado no se corrige editándola. Volvé a Guardar para confirmar.');
        return false;
    }

    let enviando = false;
    $('#btn-nota-guardar').on('click', function () {
        if (enviando || !validar() || !tipoCruzadoConfirmado()) { return; }
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
                // El 409 es una regla de negocio (no una validación de campos) y trae su propio
                // mensaje explicativo: se muestra tal cual, sin pasar por el detalle por campo.
                if (xhr.status === 409) { toast('error', xhr.responseJSON?.mensaje || 'No se puede guardar esta nota.'); }
                else { window.AppErrores.toast(xhr, 'No se pudo guardar la nota.'); }
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
