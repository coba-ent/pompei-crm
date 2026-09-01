/**
 * Modal de alta/edición de Proveedor (ficha completa — mismo `_modal_form` de
 * Proveedores) reutilizable desde selects de otras pantallas (Compras):
 * "Crear Proveedor" y el lápiz de cada fila abren ESTE modal, no uno
 * simplificado de sólo nombre.
 *
 * Espejo de `cliente-modal.js` (mismo patrón que usa el select de Cliente en
 * Ventas/Presupuestos). Requiere que la vista incluya `proveedores._modal_form`
 * y llame a `window.ProveedorModal.init(rutas)` con
 * { store, show, localidades, verificarDocumento } antes de invocar
 * `crear()`/`editar()`.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[proveedor-modal] jQuery no está disponible.');
        return;
    }

    function esc(v) {
        return (v || v === 0) ? $('<div>').text(v).html() : '';
    }

    function toast(tipo, mensaje, titulo) {
        if (window.toastr && window.toastr[tipo]) {
            window.toastr[tipo](mensaje, titulo || '');
        } else {
            console.log('[proveedor-modal][' + tipo + ']', mensaje);
        }
    }

    let rutas = {};
    let listo = false;
    let $modal, modal, $form;
    let onGuardadoActual = null;

    function inicializar() {
        if (listo) { return; }
        $modal = $('#modal-proveedor');
        if (!$modal.length) { return; }
        modal = window.bootstrap ? new window.bootstrap.Modal($modal[0]) : null;
        $form = $('#form-proveedor');
        listo = true;
        cablearForm();
    }

    function limpiarErrores() {
        $form.find('.is-invalid, .is-valid').removeClass('is-invalid is-valid');
        $form.find('.invalid-feedback').removeClass('text-success').text('');
    }

    function setSoloLectura(activo) {
        $form.find('input, select, textarea').prop('disabled', activo);
        $form.find('button').not('[data-bs-dismiss="modal"]').prop('disabled', activo);
        $('#btn-guardar-proveedor').toggleClass('d-none', activo);
    }

    function resetForm() {
        $form[0].reset();
        $('#proveedor-id').val('');
        limpiarErrores();
        setSoloLectura(false);
        $('#contactos-container').empty();
        $('#campos-personalizados-container').empty();
        $form.find('.js-localidad').html('<option value="">Seleccionar</option>');
        $('#saldo-inicial-wrap').addClass('d-none');
        resetearTocadoPadron();
    }

    // --- Verificación de CUIT/CUIL ---

    function formatearDocumento(valor) {
        const digitos = (valor || '').replace(/\D/g, '').slice(0, 11);
        if (digitos.length <= 2) { return digitos; }
        if (digitos.length <= 10) { return digitos.slice(0, 2) + '-' + digitos.slice(2); }
        return digitos.slice(0, 2) + '-' + digitos.slice(2, 10) + '-' + digitos.slice(10);
    }

    function limpiarResultadoVerificacion() {
        $form.find('input[name="cuit"]').removeClass('is-invalid is-valid');
        $form.find('.invalid-feedback[data-field="cuit"]').removeClass('text-success').text('');
    }

    function pintarResultadoVerificacion(resp) {
        const $input = $form.find('input[name="cuit"]');
        const $fb = $form.find('.invalid-feedback[data-field="cuit"]');
        $input.removeClass('is-invalid is-valid');
        $fb.removeClass('text-success').text('');

        if (!resp || !resp.aplica) { return; }
        if (resp.valido) {
            $input.addClass('is-valid');
            $fb.addClass('text-success').text('El CUIT/CUIL ingresado es válido.');
        } else {
            $input.addClass('is-invalid');
            $fb.text(resp.mensaje || 'El CUIT ingresado no es válido.');
        }
    }

    const CAMPOS_PADRON = ['razon_social', 'domicilio_fiscal', 'provincia_fiscal', 'localidad_fiscal', 'condicion_iva_id', 'tipo_comprobante_defecto'];

    /** Matchea por texto (case/acentos-insensitive) contra las <option> de un <select>; devuelve el value si matchea. */
    function normalizarTexto(txt) {
        return (txt || '').toString().trim().toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function buscarOpcionPorTexto($select, valor) {
        if (!valor) { return null; }
        const buscado = normalizarTexto(valor);
        const $opcion = $select.find('option').filter(function () {
            return normalizarTexto($(this).text()) === buscado || normalizarTexto($(this).val()) === buscado;
        });
        return $opcion.length ? $opcion.first().val() : null;
    }
    let tocadoPadron = {};

    function resetearTocadoPadron() {
        tocadoPadron = {};
        CAMPOS_PADRON.forEach(function (campo) { tocadoPadron[campo] = false; });
    }
    resetearTocadoPadron();

    function autocompletarDesdePadron(padron) {
        if (!padron || !padron.encontrado) { return; }
        if (padron.razon_social && !tocadoPadron.razon_social) {
            $form.find('input[name="razon_social"]').val(padron.razon_social);
        }
        if (padron.domicilio_fiscal && !tocadoPadron.domicilio_fiscal) {
            $form.find('input[name="domicilio_fiscal"]').val(padron.domicilio_fiscal);
        }
        // Provincia y Localidad son selects linkeados (docs §2.1): primero seleccionar la
        // provincia que devuelve el padrón, y recién con eso disparar la carga AJAX de
        // localidades de esa provincia para poder seleccionar la localidad devuelta.
        if (padron.provincia_fiscal && !tocadoPadron.provincia_fiscal) {
            const $provincia = $form.find('select[name="provincia_fiscal"]');
            const valorProvincia = buscarOpcionPorTexto($provincia, padron.provincia_fiscal);
            if (valorProvincia !== null) {
                $provincia.val(valorProvincia);
                const $loc = $form.find('select[name="localidad_fiscal"]');
                if (!tocadoPadron.localidad_fiscal) {
                    cargarLocalidades($loc, valorProvincia, padron.localidad_fiscal || null);
                }
            }
        }
        if (padron.condicion_iva && !tocadoPadron.condicion_iva_id) {
            const $select = $form.find('select[name="condicion_iva_id"]');
            const $opcion = $select.find('option').filter(function () {
                return $(this).text().trim() === padron.condicion_iva;
            });
            if ($opcion.length) { $select.val($opcion.val()).trigger('change'); }
        }
    }

    /** Deriva Factura A/B en "Comprobante por defecto" según el texto de la Condición de IVA elegida (docs §2.1). */
    function derivarComprobantePorCondicionIva() {
        if (tocadoPadron.tipo_comprobante_defecto) { return; }
        const $condicion = $form.find('select[name="condicion_iva_id"]');
        const texto = $condicion.find('option:selected').text().trim();
        if (!texto) { return; }
        $form.find('select[name="tipo_comprobante_defecto"]').val(texto === 'Responsable Inscripto' ? 'A' : 'B');
    }

    function mostrarMensajePadron(padron) {
        if (!padron) { return; }
        if (!padron.consultado) {
            toast('info', padron.mensaje || 'No se pudo consultar el padrón de ARCA en este momento.');
        } else if (!padron.encontrado) {
            toast('info', padron.mensaje || 'No se encontró el CUIT en el padrón de ARCA.');
        } else {
            toast('success', 'Datos del padrón de ARCA cargados.');
        }
    }

    let verificacionEnCurso = false;

    // --- Provincia → Localidad ---

    function cargarLocalidades($localidad, provincia, seleccionar) {
        $localidad.html('<option value="">Seleccionar</option>');
        if (!provincia || !rutas.localidades) {
            return $.Deferred().resolve().promise();
        }
        const buscado = seleccionar ? normalizarTexto(seleccionar) : null;
        return $.getJSON(rutas.localidades, { provincia: provincia })
            .done(function (resp) {
                (resp.localidades || []).forEach(function (nombre) {
                    const sel = (buscado !== null && normalizarTexto(nombre) === buscado) ? ' selected' : '';
                    $localidad.append('<option value="' + esc(nombre) + '"' + sel + '>' + esc(nombre) + '</option>');
                });
            });
    }

    function precargarLocalidades(proveedor) {
        $form.find('.js-provincia').each(function () {
            const target = $(this).data('localidad-target');
            const $loc = $form.find('.js-localidad[data-provincia="' + target + '"]');
            const provincia = $(this).val();
            if (provincia) { cargarLocalidades($loc, provincia, proveedor[target]); }
        });
    }

    let tooltipsListos = false;
    function initTooltips() {
        if (tooltipsListos || !(window.bootstrap && window.bootstrap.Tooltip)) { return; }
        $modal.find('[data-bs-toggle="tooltip"]').each(function () {
            new window.bootstrap.Tooltip(this);
        });
        tooltipsListos = true;
    }

    function abrirModal(titulo) {
        $('#modal-proveedor-titulo').text(titulo);
        initTooltips();
        if (modal) { modal.show(); } else { $modal.show(); }
    }

    function cerrarModal() {
        if (modal) { modal.hide(); } else { $modal.hide(); }
    }

    // --- Personas de contacto ---
    let contactoIdx = 0;
    function agregarContacto(item) {
        item = item || {};
        const idx = contactoIdx++;
        const checked = item.enviar_mails ? 'checked' : '';
        const html =
            '<div class="js-contacto border-top pt-3 mb-2">' +
            '  <div class="mb-2 d-flex align-items-end gap-2">' +
            '    <div class="flex-grow-1"><label class="form-label">Nombre <span class="text-danger">*</span></label>' +
            '      <input type="text" class="form-control" name="contactos[' + idx + '][nombre]" value="' + esc(item.nombre) + '"></div>' +
            '    <button type="button" class="btn btn-outline-danger js-quitar-contacto" title="Quitar"><i class="fas fa-trash-alt"></i></button>' +
            '  </div>' +
            '  <div class="mb-2"><label class="form-label">Apellido</label>' +
            '    <input type="text" class="form-control" name="contactos[' + idx + '][apellido]" value="' + esc(item.apellido) + '"></div>' +
            '  <div class="row g-2 mb-2">' +
            '    <div class="col-6"><label class="form-label">Teléfono</label>' +
            '      <input type="text" class="form-control" name="contactos[' + idx + '][telefono]" value="' + esc(item.telefono) + '"></div>' +
            '    <div class="col-6"><label class="form-label">Cel.</label>' +
            '      <input type="text" class="form-control" name="contactos[' + idx + '][telefono_celular]" value="' + esc(item.telefono_celular) + '"></div>' +
            '  </div>' +
            '  <div class="mb-2"><label class="form-label">Email</label>' +
            '    <input type="email" class="form-control" name="contactos[' + idx + '][email]" value="' + esc(item.email) + '"></div>' +
            '  <div class="form-check">' +
            '    <input class="form-check-input" type="checkbox" value="1" name="contactos[' + idx + '][enviar_mails]" id="contacto-mails-' + idx + '" ' + checked + '>' +
            '    <label class="form-check-label" for="contacto-mails-' + idx + '">Enviar también mails a esta dirección</label>' +
            '  </div>' +
            '</div>';
        $('#contactos-container').append(html);
    }

    // --- Campos personalizados ---
    let campoAdicionalIdx = 0;
    function renderOpcionesNuevoCampo(opcionesNuevoCampo) {
        const $lista = $('#nuevo-campo-opciones-lista').empty();
        opcionesNuevoCampo.forEach(function (op, i) {
            $lista.append(
                '<li class="d-flex align-items-center mb-1">' +
                '  <span class="me-2">-</span><span class="flex-grow-1">' + esc(op) + '</span>' +
                '  <button type="button" class="btn btn-sm btn-link text-danger js-quitar-opcion" data-i="' + i + '">x</button>' +
                '</li>'
            );
        });
    }

    function renderCampoAdicional(def) {
        def = def || {};
        const idx = campoAdicionalIdx++;
        const base = 'campos_personalizados[' + idx + ']';
        const tipo = def.tipo || 'texto';
        const opciones = def.opciones || [];
        const valor = (def.valor === null || def.valor === undefined) ? '' : def.valor;
        let input;
        if (tipo === 'opciones') {
            input = '<select class="form-select" name="' + base + '[valor]"><option value="">Seleccionar</option>';
            opciones.forEach(function (op) {
                const sel = (op === valor) ? ' selected' : '';
                input += '<option value="' + esc(op) + '"' + sel + '>' + esc(op) + '</option>';
            });
            input += '</select>';
        } else if (tipo === 'numerico') {
            input = '<input type="number" step="any" class="form-control" name="' + base + '[valor]" value="' + esc(valor) + '">';
        } else if (tipo === 'fecha') {
            input = '<input type="date" class="form-control" name="' + base + '[valor]" value="' + esc(valor) + '">';
        } else {
            input = '<input type="text" class="form-control" name="' + base + '[valor]" value="' + esc(valor) + '">';
        }

        let hidden =
            '<input type="hidden" name="' + base + '[nombre]" value="' + esc(def.nombre) + '">' +
            '<input type="hidden" name="' + base + '[tipo]" value="' + esc(tipo) + '">';
        opciones.forEach(function (op) {
            hidden += '<input type="hidden" name="' + base + '[opciones][]" value="' + esc(op) + '">';
        });

        $('#campos-personalizados-container').append(
            '<div class="mb-3 js-campo-personalizado">' +
            '  <label class="form-label d-flex justify-content-between align-items-center">' +
            '    <span>' + esc(def.nombre) + '</span>' +
            '    <button type="button" class="btn btn-sm btn-link text-danger p-0 js-quitar-campo" title="Quitar">' +
            '      <i class="fas fa-times"></i></button>' +
            '  </label>' +
            input + hidden +
            '</div>'
        );
    }

    function mostrarErrores(errors) {
        Object.keys(errors).forEach(function (campo) {
            const nombreCampo = campo.split('.')[0];
            const $input = $form.find('[name="' + campo + '"], [name="' + nombreCampo + '"]');
            $input.addClass('is-invalid');
            $form.find('.invalid-feedback[data-field="' + nombreCampo + '"]').text(errors[campo][0]);
        });
    }

    function cablearForm() {
        $form.on('input', 'input[name="cuit"]', function () {
            const input = this;
            const cursorDigitosAntes = input.value.slice(0, input.selectionStart).replace(/\D/g, '').length;
            input.value = formatearDocumento(input.value);

            let posicion = 0;
            let digitosVistos = 0;
            while (posicion < input.value.length && digitosVistos < cursorDigitosAntes) {
                if (/\d/.test(input.value[posicion])) { digitosVistos++; }
                posicion++;
            }
            input.setSelectionRange(posicion, posicion);
            limpiarResultadoVerificacion();
        });

        $form.on('change', 'select[name="tipo_documento"]', limpiarResultadoVerificacion);

        CAMPOS_PADRON.forEach(function (campo) {
            $form.on('input change', '[name="' + campo + '"]', function () { tocadoPadron[campo] = true; });
        });

        $form.on('click', '.js-verificar-documento', function () {
            if (!rutas.verificarDocumento || verificacionEnCurso) { return; }
            const $boton = $(this);
            verificacionEnCurso = true;
            $boton.prop('disabled', true);

            $.getJSON(rutas.verificarDocumento, {
                tipo_documento: $form.find('select[name="tipo_documento"]').val(),
                numero: $form.find('input[name="cuit"]').val(),
            })
                .done(function (resp) {
                    pintarResultadoVerificacion(resp);
                    if (resp && resp.padron) {
                        autocompletarDesdePadron(resp.padron);
                        mostrarMensajePadron(resp.padron);
                    }
                })
                .fail(function () { toast('error', 'No se pudo verificar el documento.'); })
                .always(function () {
                    verificacionEnCurso = false;
                    $boton.prop('disabled', false);
                });
        });

        $form.on('change', '.js-provincia', function () {
            const target = $(this).data('localidad-target');
            const $loc = $form.find('.js-localidad[data-provincia="' + target + '"]');
            cargarLocalidades($loc, $(this).val(), null);
        });

        $form.on('change', 'select[name="condicion_iva_id"]', derivarComprobantePorCondicionIva);

        $('#btn-agregar-contacto').on('click', function () { agregarContacto({}); });
        $('#contactos-container').on('click', '.js-quitar-contacto', function () {
            $(this).closest('.js-contacto').remove();
        });

        $('#btn-toggle-saldo').on('click', function () {
            const $wrap = $('#saldo-inicial-wrap').toggleClass('d-none');
            if (!$wrap.hasClass('d-none')) {
                const $fecha = $wrap.find('[name="saldo_inicial_fecha"]');
                if (!AppFecha.get($fecha)) { AppFecha.set($fecha, AppFecha.hoy()); }
            }
        });

        // Sub-modal "Crear nuevo campo".
        const $modalCampo = $('#modal-nuevo-campo');
        const modalCampo = window.bootstrap ? new window.bootstrap.Modal($modalCampo[0]) : null;
        let opcionesNuevoCampo = [];

        function resetModalCampo() {
            $('#nuevo-campo-nombre').val('').removeClass('is-invalid');
            $('#nuevo-campo-tipo').val('texto');
            $('#nuevo-campo-opcion-input').val('');
            $('#nuevo-campo-error-nombre').text('');
            $('#nuevo-campo-error-opciones').text('');
            opcionesNuevoCampo = [];
            renderOpcionesNuevoCampo(opcionesNuevoCampo);
            $('#nuevo-campo-opciones-wrap').addClass('d-none');
        }

        $('#btn-agregar-campo').on('click', function () {
            resetModalCampo();
            if (modalCampo) { modalCampo.show(); } else { $modalCampo.show(); }
        });

        $('#nuevo-campo-tipo').on('change', function () {
            $('#nuevo-campo-opciones-wrap').toggleClass('d-none', $(this).val() !== 'opciones');
        });

        $('#btn-agregar-opcion').on('click', function () {
            const val = ($('#nuevo-campo-opcion-input').val() || '').trim();
            if (!val) { return; }
            opcionesNuevoCampo.push(val);
            $('#nuevo-campo-opcion-input').val('');
            renderOpcionesNuevoCampo(opcionesNuevoCampo);
        });

        $('#nuevo-campo-opcion-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#btn-agregar-opcion').click();
            }
        });

        $('#nuevo-campo-opciones-lista').on('click', '.js-quitar-opcion', function () {
            opcionesNuevoCampo.splice($(this).data('i'), 1);
            renderOpcionesNuevoCampo(opcionesNuevoCampo);
        });

        $('#campos-personalizados-container').on('click', '.js-quitar-campo', function () {
            $(this).closest('.js-campo-personalizado').remove();
        });

        $('#btn-guardar-campo').on('click', function () {
            const nombre = ($('#nuevo-campo-nombre').val() || '').trim();
            const tipo = $('#nuevo-campo-tipo').val();
            $('#nuevo-campo-nombre').removeClass('is-invalid');
            $('#nuevo-campo-error-nombre').text('');
            $('#nuevo-campo-error-opciones').text('');

            if (!nombre) {
                $('#nuevo-campo-nombre').addClass('is-invalid');
                $('#nuevo-campo-error-nombre').text('Ingresá un nombre.');
                return;
            }

            const def = { nombre: nombre, tipo: tipo, opciones: [] };
            if (tipo === 'opciones') {
                if (!opcionesNuevoCampo.length) {
                    $('#nuevo-campo-error-opciones').text('Agregá al menos una opción.');
                    return;
                }
                def.opciones = opcionesNuevoCampo.slice();
            }

            renderCampoAdicional(def);
            if (modalCampo) { modalCampo.hide(); } else { $modalCampo.hide(); }
        });

        // Submit (store/update) por AJAX.
        $form.on('submit', function (e) {
            e.preventDefault();
            limpiarErrores();

            const id = $('#proveedor-id').val();
            const esEdicion = !!id;
            const url = esEdicion ? rutas.show + '/' + id : rutas.store;
            const datos = AppFecha.serializeArray($form);
            if (esEdicion) { datos.push({ name: '_method', value: 'PATCH' }); }

            if (window.AppBtn) { window.AppBtn.loading('#btn-guardar-proveedor', true); }

            $.ajax({
                url: url,
                method: 'POST',
                data: $.param(datos),
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .done(function (resp) {
                    cerrarModal();
                    toast('success', resp.mensaje || 'Guardado.');
                    if (typeof onGuardadoActual === 'function') { onGuardadoActual(resp.proveedor); }
                })
                .fail(function (xhr) {
                    if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                        mostrarErrores(xhr.responseJSON.errors);
                        toast('error', 'Revisá los datos del formulario.');
                    } else {
                        toast('error', 'No se pudo guardar el proveedor.');
                    }
                })
                .always(function () {
                    if (window.AppBtn) { window.AppBtn.loading('#btn-guardar-proveedor', false); }
                });
        });
    }

    // --- API pública ---

    function init(rutasConfig) {
        rutas = rutasConfig || {};
        inicializar();
    }

    function crear(nombreSugerido, onGuardado) {
        inicializar();
        if (!listo) { return; }
        onGuardadoActual = onGuardado || null;
        resetForm();
        if (nombreSugerido) { $form.find('input[name="nombre"]').val(nombreSugerido); }
        abrirModal('Nuevo Proveedor');
    }

    function editar(id, onGuardado, soloLectura, titulo) {
        inicializar();
        if (!listo) { return; }
        onGuardadoActual = onGuardado || null;
        resetForm();
        $.getJSON(rutas.show + '/' + id)
            .done(function (resp) {
                const p = resp.proveedor;
                $('#proveedor-id').val(p.id);
                const complejos = ['campos_personalizados', 'contactos'];
                Object.keys(p).forEach(function (campo) {
                    const $input = $form.find('[name="' + campo + '"]');
                    if ($input.length && complejos.indexOf(campo) === -1) {
                        // Ver el comentario equivalente en `proveedores.js`: asignar el ISO crudo a un
                        // campo dd/mm/aaaa haría que se guarde vacío.
                        if ($input.is('[data-fecha-ar]')) {
                            AppFecha.set($input, p[campo]);
                        } else {
                            $input.val(p[campo] === null ? '' : p[campo]);
                        }
                    }
                });
                (p.campos_personalizados || []).forEach(function (campo) { renderCampoAdicional(campo); });
                precargarLocalidades(p);
                (p.contactos || []).forEach(function (item) { agregarContacto(item); });
                if ((p.saldo_inicial && parseFloat(p.saldo_inicial) !== 0) || p.saldo_inicial_fecha) {
                    $('#saldo-inicial-wrap').removeClass('d-none');
                }
                setSoloLectura(!!soloLectura);
                abrirModal(titulo || (soloLectura ? 'Ver Proveedor' : 'Editar Proveedor'));
            })
            .fail(function () { toast('error', 'No se pudo cargar el proveedor.'); });
    }

    window.ProveedorModal = { init: init, crear: crear, editar: editar };
})();
