/**
 * Modal "Enviar Información a tu Contador por Correo" (spec 087).
 *
 * El panel de adjuntos se arma en el cliente por AJAX (`adjuntos-previstos`), nunca calculado a
 * mano en JS (research Decisión 3, SC-004): la única fuente de qué corresponde es el servidor
 * (`PaqueteContador`), este script sólo la refleja.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[envio-contador] jQuery no está disponible.');
        return;
    }

    const cfg = window.EnvioContadorConfig || {};
    const rutas = cfg.rutas || {};

    function toast(tipo, mensaje) {
        if (window.toastr && window.toastr[tipo]) { window.toastr[tipo](mensaje); } else { console.log('[envio-contador]', mensaje); }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    if (CSRF) { $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } }); }

    /**
     * Seguimiento del envío en curso.
     *
     * El envío corre en segundo plano y puede tardar minutos, así que la pantalla pregunta cada
     * pocos segundos cómo va. Antes no preguntaba nada: el modal decía "en proceso", se cerraba, y
     * si el envío fallaba nadie se enteraba (incidente del 28/08/2026, hubo que mirar la base por
     * SSH para descubrirlo).
     */
    const seguimiento = (function () {
        const INTERVALO_MS = 3000;
        // Un envío no debería tardar más que el timeout del job (300 s). Si el worker está caído el
        // registro se queda en `pendiente` sin que nadie lo toque, así que se corta el sondeo y se
        // avisa, en vez de dejar la barra girando para siempre.
        const LIMITE_MS = 360000;

        let timer = null;
        let arrancoEn = 0;

        const $panel = () => $('#panel-envio-progreso');

        function pintar(p) {
            const finalizado = p.finalizado;
            const fallo = p.estado === 'fallido';

            $panel().removeClass('d-none');
            $('#envio-progreso-rotulo').text(p.rotulo);
            $('#envio-progreso-barra')
                .css('width', p.porcentaje + '%')
                .toggleClass('progress-bar-animated progress-bar-striped', !finalizado)
                .toggleClass('bg-success', p.estado === 'enviado')
                .toggleClass('bg-danger', fallo);
            $('#envio-progreso-barra-cont').attr('aria-valuenow', p.porcentaje);
            $('#envio-progreso-icono')
                .removeClass('fa-paper-plane fa-circle-check fa-triangle-exclamation text-primary text-success text-danger')
                .addClass(fallo ? 'fa-triangle-exclamation text-danger'
                    : p.estado === 'enviado' ? 'fa-circle-check text-success' : 'fa-paper-plane text-primary');

            $('#envio-progreso-detalle').text(
                p.estado === 'enviado' ? 'a ' + p.destinatarios + (p.enviado_en ? ' · ' + p.enviado_en : '')
                    : fallo ? '' : 'a ' + p.destinatarios
            );

            $('#envio-progreso-error').toggleClass('d-none', !fallo).text(fallo ? (p.error || 'No se pudo completar el envío.') : '');
            // El panel sólo se puede cerrar cuando terminó: mientras corre, cerrarlo perdería de
            // vista lo único que informa del resultado.
            $('#envio-progreso-cerrar').toggleClass('d-none', !finalizado);
        }

        function detener() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function consultar(id) {
            $.getJSON(rutas.estado.replace('__ID__', id))
                .done(function (resp) {
                    const p = resp.progreso;
                    pintar(p);

                    if (p.finalizado) {
                        detener();
                        if (p.estado === 'enviado') { toast('success', 'El envío al contador se completó.'); }
                        else { toast('error', 'El envío al contador falló. Revisá el detalle en pantalla.'); }
                        return;
                    }

                    if (Date.now() - arrancoEn > LIMITE_MS) {
                        detener();
                        $('#envio-progreso-rotulo').text('Sin novedades del envío');
                        $('#envio-progreso-error').removeClass('d-none')
                            .text('El envío sigue sin responder. Recargá la página para ver si terminó; si no, revisá que el procesador de tareas esté corriendo.');
                        $('#envio-progreso-cerrar').removeClass('d-none');
                    }
                })
                .fail(function (xhr) {
                    // Un 404/403 no se va a arreglar reintentando; un error de red sí puede ser pasajero.
                    if (xhr.status === 404 || xhr.status === 403) { detener(); $panel().addClass('d-none'); }
                });
        }

        return {
            seguir(id, progresoInicial) {
                if (!rutas.estado || !id) { return; }
                detener();
                arrancoEn = Date.now();
                if (progresoInicial) { pintar(progresoInicial); }
                consultar(id);
                timer = setInterval(() => consultar(id), INTERVALO_MS);
            },
            /**
             * Al abrir la pantalla, retoma un envío que haya quedado corriendo y, si el último ya
             * terminó, igual muestra su resultado: si no, un fallo ocurrido con la pestaña cerrada
             * seguiría siendo invisible, que es justo el problema que esto viene a resolver.
             */
            retomar() {
                if (!rutas.envios) { return; }
                $.getJSON(rutas.envios).done(function (resp) {
                    const ultimo = (resp.envios || [])[0];
                    if (!ultimo) { return; }
                    if (ultimo.finalizado) { pintar(ultimo); } else { seguimiento.seguir(ultimo.id, ultimo); }
                });
            },
        };
    })();

    $(document).on('click', '#envio-progreso-cerrar', function () {
        $('#panel-envio-progreso').addClass('d-none');
    });

    $(function () {
        const $modal = $('#modal-envio-contador');
        if (!$modal.length) { return; }

        const $form = $('#form-envio-contador');
        const $anio = $('#ec-anio');
        const $mes = $('#ec-mes');
        const $electronicas = $('#ec-electronicas');
        const $manuales = $('#ec-manuales');
        const $pdfs = $('#ec-pdfs');
        const $cuerpo = $('#ec-cuerpo');
        const $panelVacio = $('#ec-panel-vacio');
        const $listaAdjuntos = $('#ec-lista-adjuntos');
        const $btnEnviar = $('#ec-btn-enviar');

        const hasSelect2 = !!($.fn && $.fn.select2);
        if (hasSelect2) {
            $anio.select2({ width: '100%', theme: 'default', dropdownParent: $modal, placeholder: 'Año' });
            $mes.select2({ width: '100%', theme: 'default', dropdownParent: $modal, placeholder: 'Mes' });
        }

        // Adjuntos propios del usuario (FR-006/T028): se acumulan en un DataTransfer para poder
        // quitarlos de a uno sin perder los ya elegidos (un <input type=file> no permite editar su
        // FileList directamente).
        let adjuntosPropios = new DataTransfer();

        $('#ec-btn-adjuntar').on('click', () => $('#ec-input-adjuntos').trigger('click'));

        $('#ec-input-adjuntos').on('change', function () {
            Array.from(this.files).forEach((f) => adjuntosPropios.items.add(f));
            this.value = '';
            renderizarAdjuntosPropios();
        });

        function renderizarAdjuntosPropios() {
            const $ul = $('#ec-lista-adjuntos-propios').empty();
            Array.from(adjuntosPropios.files).forEach((f, idx) => {
                $ul.append(
                    $('<li>').append(
                        $('<i class="fas fa-paperclip me-1 text-muted">'),
                        document.createTextNode(f.name + ' '),
                        $('<a href="#" class="text-danger" title="Quitar"><i class="fas fa-times"></i></a>').on('click', (e) => {
                            e.preventDefault();
                            const dt = new DataTransfer();
                            Array.from(adjuntosPropios.files).forEach((otro, i) => { if (i !== idx) { dt.items.add(otro); } });
                            adjuntosPropios = dt;
                            renderizarAdjuntosPropios();
                        })
                    )
                );
            });
        }

        // Bandera: el usuario tocó el textarea a mano — no se le pisa el texto en silencio (FR-013).
        let cuerpoEditadoManualmente = false;
        $cuerpo.on('input', () => { cuerpoEditadoManualmente = true; });

        function periodoElegido() {
            return { anio: $anio.val(), mes: $mes.val() };
        }

        function actualizarPanel() {
            const { anio, mes } = periodoElegido();

            if (!anio) {
                mostrarPanelVacio();
                actualizarBotonEnviar();

                return;
            }

            $.ajax({
                url: rutas.adjuntosPrevistos, method: 'POST',
                data: {
                    anio: anio, mes: mes || '',
                    incluye_electronicas: $electronicas.is(':checked'),
                    incluye_manuales: $manuales.is(':checked'),
                    incluye_pdfs: $pdfs.is(':checked'),
                },
            }).done(function (resp) {
                const archivos = (resp && resp.archivos) || [];
                pintarPanel(archivos);
                if (!cuerpoEditadoManualmente) { rearmarCuerpo(archivos); }
                actualizarBotonEnviar();
            }).fail(function () {
                toast('error', 'No se pudo actualizar el panel de adjuntos.');
            });
        }

        function mostrarPanelVacio() {
            $panelVacio.show();
            $listaAdjuntos.empty();
        }

        function pintarPanel(archivos) {
            if (archivos.length === 0) {
                mostrarPanelVacio();

                return;
            }

            $panelVacio.hide();
            $listaAdjuntos.empty();

            const icono = (nombre) => nombre.endsWith('.zip') ? 'fa-file-archive' : (nombre.endsWith('.xlsx') ? 'fa-file-excel' : 'fa-file');

            archivos.forEach((nombre) => {
                $listaAdjuntos.append(
                    $('<li class="mb-1">').append(
                        $('<i class="fas me-2 text-muted">').addClass(icono(nombre)),
                        document.createTextNode(nombre)
                    )
                );
            });
        }

        function rearmarCuerpo(archivos) {
            const anio = $anio.val();
            const mes = $mes.val();

            if (!anio) { return; }

            const textoPeriodo = mes ? 'del mes de ' + $mes.find(':selected').text() + ' de ' + anio : 'del año ' + anio;
            const lista = archivos.map((a) => '- ' + a).join('\n');

            $cuerpo.val('Hola, te enviamos la información ' + textoPeriodo + '.\n\nArchivos adjuntos:\n' + lista + '\n\nSaludos.');
        }

        // FR-020/T030: nunca ambas destildadas — se re-marca la que se acaba de destildar y se explica por qué.
        function impedirAmbasDestildadas($origen) {
            if (!$electronicas.is(':checked') && !$manuales.is(':checked')) {
                $origen.prop('checked', true);
                toast('warning', 'Al menos una de "Facturas Electrónicas" o "Facturas Manuales" debe quedar tildada.');

                return true;
            }

            return false;
        }

        function actualizarBotonEnviar() {
            const { anio } = periodoElegido();
            $btnEnviar.prop('disabled', !anio);
        }

        $anio.on('change', actualizarPanel);
        $mes.on('change', actualizarPanel);
        $pdfs.on('change', actualizarPanel);
        $electronicas.on('change', function () {
            if (impedirAmbasDestildadas($(this))) { return; }
            actualizarPanel();
        });
        $manuales.on('change', function () {
            if (impedirAmbasDestildadas($(this))) { return; }
            actualizarPanel();
        });

        $modal.on('show.bs.modal', function () {
            if (!$('#ec-destinatarios').val()) { $('#ec-destinatarios').val(cfg.mailContador || ''); }
            if (!$('#ec-asunto').val()) { $('#ec-asunto').val('Información de ' + (cfg.nombreNegocio || '')); }
            actualizarBotonEnviar();
        });

        function limpiarErrores() {
            $form.find('.is-invalid').removeClass('is-invalid');
            $form.find('.invalid-feedback').text('');
        }

        function mostrarErrores(errors) {
            Object.keys(errors).forEach((campo) => {
                const $campo = $form.find('[name="' + campo + '"]');
                $campo.addClass('is-invalid');
                $form.find('.invalid-feedback[data-field="' + campo + '"]').text(errors[campo][0]);
            });
        }

        let enviando = false;

        $form.on('submit', function (e) {
            e.preventDefault();

            if (enviando) { return; } // FR-023: doble clic.

            limpiarErrores();
            enviando = true;
            $btnEnviar.prop('disabled', true).find('i').removeClass('fa-envelope').addClass('fa-spinner fa-spin');

            const formData = new FormData($form[0]);
            formData.set('incluye_electronicas', $electronicas.is(':checked') ? '1' : '0');
            formData.set('incluye_manuales', $manuales.is(':checked') ? '1' : '0');
            formData.set('incluye_pdfs', $pdfs.is(':checked') ? '1' : '0');
            formData.set('copia_remitente', $('#ec-copia-remitente').is(':checked') ? '1' : '0');
            Array.from(adjuntosPropios.files).forEach((f) => formData.append('adjuntos_propios[]', f));

            $.ajax({
                url: rutas.enviar, method: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'json',
            }).done(function (resp) {
                toast('success', (resp && resp.mensaje) || 'Envío en proceso.');
                $modal.modal('hide');
                // El modal se cierra y el progreso pasa a la pantalla, así se puede seguir
                // trabajando mientras el envío corre.
                if (resp && resp.envio_id) { seguimiento.seguir(resp.envio_id, resp.progreso); }
                $form[0].reset();
                cuerpoEditadoManualmente = false;
                adjuntosPropios = new DataTransfer();
                renderizarAdjuntosPropios();
                mostrarPanelVacio();
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON) {
                    if (xhr.responseJSON.errors) { mostrarErrores(xhr.responseJSON.errors); }
                    toast('error', xhr.responseJSON.message || 'Revisá los datos del envío.');
                } else if (xhr.status === 409) {
                    toast('warning', (xhr.responseJSON && xhr.responseJSON.message) || 'Este envío ya se está procesando.');
                } else {
                    toast('error', (xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo enviar. Intentá de nuevo.');
                }
                // FR-019: el modal NO se cierra y conserva lo cargado.
            }).always(function () {
                enviando = false;
                $btnEnviar.prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-envelope');
            });
        });

        seguimiento.retomar();
    });
})();
