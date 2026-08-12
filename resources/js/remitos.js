/**
 * Módulo Remitos (spec 064) — página completa de Crear/Editar, compartida entre Ventas y
 * Compras. Mismo precedente de página completa que notas-credito-debito.js (spec 059).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[remitos] jQuery no está disponible.');
        return;
    }

    const $tabla = $('#tabla-lineas-remito');
    if (!$tabla.length) { return; }

    const data = window.RemitoFormData || {};
    const cfg = window.RemitoFormConfig || {};
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
            console.log('[remitos][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
    }

    let lineas = (data.lineas || []).map((l) => Object.assign({}, l));

    function recalcularTotalBultos() {
        const total = lineas.reduce((acc, l) => acc + (Number(l.cantidad) || 0), 0);
        $('#total-bultos').text(total);
    }

    function renderLineas() {
        const $body = $('#lineas-remito-body');
        $body.empty();

        if (!lineas.length) {
            $body.append('<tr><td colspan="4" class="text-center text-muted">Sin líneas</td></tr>');
            recalcularTotalBultos();
            return;
        }

        lineas.forEach((linea, idx) => {
            const excedeOrigen = Number(linea.cantidad) > Number(linea.cantidad_origen || 0);
            const $tr = $('<tr>');
            $tr.append($('<td>').text(linea.descripcion || '')
                .prepend(excedeOrigen
                    ? $('<div>').addClass('small text-warning').text('Supera la cantidad de la operación de origen')
                    : ''));
            $tr.append($('<td>').append(
                $('<input>').attr('type', 'text').addClass('form-control form-control-sm')
                    .val(linea.observacion || '')
                    .on('input', function () { linea.observacion = $(this).val(); })
            ));
            $tr.append($('<td>').append(
                $('<input>').attr('type', 'number').attr('step', '0.001').attr('min', '0.001')
                    .addClass('form-control form-control-sm')
                    .val(linea.cantidad)
                    .on('input', function () {
                        linea.cantidad = $(this).val();
                        recalcularTotalBultos();
                        renderLineas();
                    })
            ));
            $tr.append($('<td>').append(
                $('<button>').attr('type', 'button').addClass('btn btn-sm btn-outline-danger')
                    .html('<i class="fas fa-trash-alt"></i>')
                    .on('click', function () {
                        lineas.splice(idx, 1);
                        renderLineas();
                    })
            ));
            $body.append($tr);
        });

        recalcularTotalBultos();
    }

    renderLineas();

    // Monto Asegurado: interruptor que habilita el importe (FR-007).
    const $montoToggle = $('#f-monto-asegurado-toggle');
    const $montoInput = $('#f-monto-asegurado');
    $montoToggle.on('change', function () {
        $montoInput.prop('disabled', !this.checked);
        if (this.checked && !$montoInput.val()) {
            $montoInput.val(data.totalOperacion || '');
        }
    });

    // Transportista: Select2 con buscador por AJAX + alta al vuelo (FR-021, FR-022).
    const $transportista = $('#f-transportista');
    initSelect2($transportista, {
        placeholder: 'Seleccioná un Transportista',
        allowClear: true,
        ajax: {
            url: rutas.transportistaOpciones,
            dataType: 'json',
            delay: 250,
            data: (params) => ({ q: params.term || '' }),
            processResults: (resp) => ({
                results: (resp.data || []).map((t) => ({ id: t.id, text: t.nombre })),
            }),
        },
    });
    if (data.transportista) {
        const opt = new Option(data.transportista.nombre, data.transportista.id, true, true);
        $transportista.append(opt).trigger('change.select2');
    }

    $('#btn-nuevo-transportista').on('click', function (e) {
        e.preventDefault();
        $('#nuevo-transportista-nombre').val('').removeClass('is-invalid');
        $('#nuevo-transportista-error').text('');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-nuevo-transportista')).show();
        setTimeout(() => $('#nuevo-transportista-nombre').trigger('focus'), 300);
    });

    $('#btn-crear-transportista').on('click', function () {
        const nombre = $('#nuevo-transportista-nombre').val().trim();
        $('#nuevo-transportista-nombre').removeClass('is-invalid');
        $('#nuevo-transportista-error').text('');
        if (!nombre) {
            $('#nuevo-transportista-nombre').addClass('is-invalid');
            $('#nuevo-transportista-error').text('Ingresá un nombre.');
            return;
        }
        $.post(rutas.transportistaStore, { nombre })
            .done((resp) => {
                const opt = new Option(resp.transportista.nombre, resp.transportista.id, true, true);
                $transportista.append(opt).trigger('change.select2');
                bootstrap.Modal.getInstance(document.getElementById('modal-nuevo-transportista'))?.hide();
                toast('success', resp.mensaje);
            })
            .fail((xhr) => {
                const msg = xhr.responseJSON?.mensaje || xhr.responseJSON?.errors?.nombre?.[0] || 'No se pudo crear el transportista.';
                $('#nuevo-transportista-nombre').addClass('is-invalid');
                $('#nuevo-transportista-error').text(msg);
            });
    });

    function payload() {
        return {
            fecha: $('#f-fecha').val(),
            tipo: $('#f-tipo').val(),
            transportista_id: $transportista.val() || null,
            domicilio_entrega: $('#f-domicilio-entrega').val(),
            nota: $('#f-nota').val(),
            monto_asegurado: $montoToggle.is(':checked') ? ($montoInput.val() || 0) : null,
            items: lineas.map((l) => ({
                producto_id: l.producto_id,
                codigo: l.codigo,
                descripcion: l.descripcion,
                observacion: l.observacion,
                cantidad: l.cantidad,
            })),
        };
    }

    function limpiarErrores() {
        $tabla.closest('.card').find('.is-invalid').removeClass('is-invalid');
    }

    function mostrarErrores(errors) {
        if (!errors) { return; }
        const primero = Object.values(errors)[0];
        if (Array.isArray(primero) && primero.length) {
            toast('error', primero[0]);
        }
    }

    $('#btn-remito-guardar').on('click', function () {
        limpiarErrores();
        if (!lineas.length) {
            toast('error', 'El remito necesita al menos una línea.');
            return;
        }

        const editando = !!(data.remito && data.remito.id);
        const url = editando ? rutas.update : rutas.store;
        const method = editando ? 'PUT' : 'POST';

        $.ajax({ url, method, data: payload() })
            .done((resp) => {
                toast('success', resp.mensaje);
                window.location.href = rutas.volver;
            })
            .fail((xhr) => {
                mostrarErrores(xhr.responseJSON?.errors);
                if (!xhr.responseJSON?.errors) {
                    toast('error', xhr.responseJSON?.mensaje || 'No se pudo guardar el remito.');
                }
            });
    });

    $('#btn-remito-eliminar').on('click', function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-eliminar-remito')).show();
    });

    $('#btn-confirmar-eliminar-remito').on('click', function () {
        $.ajax({ url: rutas.destroy, method: 'DELETE' })
            .done((resp) => {
                toast('success', resp.mensaje);
                window.location.href = rutas.volver;
            })
            .fail((xhr) => toast('error', xhr.responseJSON?.mensaje || 'No se pudo eliminar el remito.'));
    });
})();
