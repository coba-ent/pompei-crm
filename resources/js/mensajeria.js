/**
 * Mensajería de Mercado Libre (spec 032): bandeja server-side (DataTables,
 * estilizada como lista de conversaciones), detalle por AJAX, polling de
 * actualizaciones y envío de respuesta. Todo por AJAX, sin recarga de página.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[mensajeria] jQuery no está disponible.');
        return;
    }

    const cfg = window.MensajeriaConfig || {};
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
            console.log('[mensajeria][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const POLL_MS = 20000;
    const MAX_CARACTERES = 2000;
    let tabla = null;
    let conversacionActualId = null;
    let ultimaActualizacion = null;

    // Spec 033, US2/US3: sugerencias de IA por mensaje (ml_mensaje_id -> {id, estado,
    // texto_sugerido, error_mensaje}), alimentado por el mismo polling de actualizaciones.
    const sugerenciasPorMensaje = {};
    let ultimoMensajeCompradorId = null;
    let sugerenciaSeleccionadaId = null;

    function escapeHtml(texto) {
        return $('<div>').text(texto == null ? '' : String(texto)).html();
    }

    function iniciales(nombre) {
        const limpio = (nombre || '').trim();
        if (!limpio) { return '?'; }
        const partes = limpio.split(/\s+/);
        return (partes[0][0] + (partes[1] ? partes[1][0] : '')).toUpperCase();
    }

    $(function () {
        inicializarListado();
        inicializarBuscador();
        inicializarFormularioRespuesta();
        inicializarSugerencia();
        iniciarPolling();
    });

    function inicializarListado() {
        const $tabla = $('#tabla-mensajeria');
        if (!$tabla.length) { return; }

        tabla = $tabla.DataTable({
            processing: true, serverSide: true, responsive: false, dom: 'rtip',
            language: {
                info: 'Mostrando _START_ a _END_ de _TOTAL_ conversaciones', infoEmpty: 'Sin conversaciones',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron conversaciones',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            order: [[2, 'desc']],
            ajax: { url: rutas.datatable },
            columns: [
                { data: null, name: 'comprador', orderable: false, searchable: true, render: (data, type, row) => renderFilaConversacion(row) },
                { data: 'estado', name: 'estado', orderable: false, searchable: false, visible: false },
                { data: 'ultimo_mensaje_en', name: 'ultimo_mensaje_en', orderable: true, searchable: false, visible: false },
            ],
        });

        $('#tabla-mensajeria tbody').on('click', 'tr', function () {
            const fila = tabla.row(this).data();
            if (!fila) { return; }

            $('#tabla-mensajeria tbody tr').removeClass('mensajeria-fila-activa');
            $(this).addClass('mensajeria-fila-activa');

            abrirConversacion(fila.id);
        });
    }

    function inicializarBuscador() {
        let temporizador = null;
        $('#mensajeria-buscador').on('input', function () {
            const valor = $(this).val();
            clearTimeout(temporizador);
            temporizador = setTimeout(function () {
                if (tabla) { tabla.search(valor).draw(); }
            }, 300);
        });
    }

    function renderFilaConversacion(row) {
        const nombre = row.comprador || 'Comprador';
        const preview = row.ultimo_mensaje || row.referencia || '';

        return '' +
            '<div class="mensajeria-fila">' +
                '<div class="mensajeria-avatar">' + escapeHtml(iniciales(nombre)) + '</div>' +
                '<div class="mensajeria-fila-texto">' +
                    '<div class="mensajeria-fila-nombre">' + escapeHtml(nombre) + '</div>' +
                    '<div class="mensajeria-fila-preview">' + escapeHtml(preview) + '</div>' +
                '</div>' +
                '<div class="mensajeria-fila-meta">' +
                    '<span class="badge ' + claseBadgeEstado(row.estado) + ' mensajeria-badge-estado">' + etiquetaEstado(row.estado) + '</span>' +
                    '<span class="mensajeria-fila-fecha">' + escapeHtml(row.ultimo_mensaje_en || '') + '</span>' +
                '</div>' +
            '</div>';
    }

    function claseBadgeEstado(estado) {
        return { pendiente: 'mensajeria-badge-pendiente', respondida: 'mensajeria-badge-respondida', cerrada: 'mensajeria-badge-cerrada' }[estado] || 'bg-light';
    }
    function etiquetaEstado(estado) {
        return { pendiente: 'Pendiente', respondida: 'Respondida', cerrada: 'Cerrada' }[estado] || estado || '';
    }

    function abrirConversacion(id) {
        conversacionActualId = id;

        $.get(rutas.show + '/' + id).done(function (resp) {
            if (!resp.ok) { return; }
            renderizarConversacion(resp.conversacion);
        }).fail(function () {
            toast('error', 'No se pudo cargar la conversación.');
        });
    }

    function renderizarConversacion(conversacion) {
        $('#mensajeria-sin-seleccion').addClass('d-none');
        $('#mensajeria-conversacion').removeClass('d-none').addClass('d-flex');

        const referencia = conversacion.tipo === 'pregunta'
            ? (conversacion.publicacion_producto && conversacion.publicacion_producto.producto
                ? conversacion.publicacion_producto.producto.nombre
                : conversacion.publicacion_id_ml)
            : (conversacion.orden ? 'Orden ' + conversacion.orden.ml_order_id : (conversacion.pack_id_ml ? 'Pack ' + conversacion.pack_id_ml + ' (sin sincronizar)' : '—'));

        const nombre = conversacion.comprador_nickname || conversacion.comprador_ml_id;

        $('#mensajeria-conversacion-avatar').text(iniciales(nombre));
        $('#mensajeria-conversacion-titulo').text(nombre);
        $('#mensajeria-conversacion-subtitulo').text(referencia || '');
        $('#mensajeria-conversacion-estado')
            .attr('class', 'badge mensajeria-badge-estado ' + claseBadgeEstado(conversacion.estado))
            .text(etiquetaEstado(conversacion.estado));

        pintarMensajes(conversacion.mensajes || []);

        const yaRespondida = conversacion.estado === 'respondida' || conversacion.estado === 'cerrada';
        $('#mensajeria-texto-respuesta').prop('disabled', yaRespondida);
        $('#form-responder-mensajeria button[type="submit"]').prop('disabled', yaRespondida);

        const mensajesComprador = (conversacion.mensajes || []).filter((m) => m.origen === 'comprador');
        ultimoMensajeCompradorId = mensajesComprador.length ? mensajesComprador[mensajesComprador.length - 1].id : null;
        sugerenciaSeleccionadaId = null;
        actualizarPanelSugerencia();
    }

    function actualizarPanelSugerencia() {
        const $panel = $('#mensajeria-sugerencia');
        const estados = ['generando', 'error', 'lista', 'pedir'];
        estados.forEach((e) => $('#mensajeria-sugerencia-' + e).addClass('d-none'));

        const sugerencia = ultimoMensajeCompradorId != null ? sugerenciasPorMensaje[ultimoMensajeCompradorId] : null;

        if (!ultimoMensajeCompradorId) {
            $panel.addClass('d-none');
            return;
        }
        $panel.removeClass('d-none');

        if (!sugerencia) {
            $('#mensajeria-sugerencia-pedir').removeClass('d-none');
            return;
        }

        if (sugerencia.estado === 'generando') {
            $('#mensajeria-sugerencia-generando').removeClass('d-none');
        } else if (sugerencia.estado === 'error') {
            $('#mensajeria-sugerencia-error-texto').text(sugerencia.error_mensaje || 'No se pudo generar la sugerencia.');
            $('#mensajeria-sugerencia-error').removeClass('d-none');
        } else if (sugerencia.estado === 'lista') {
            $('#mensajeria-sugerencia-texto').text(sugerencia.texto_sugerido || '');
            $('#mensajeria-sugerencia-lista').removeClass('d-none');
        } else {
            $('#mensajeria-sugerencia-pedir').removeClass('d-none');
        }
    }

    function pedirSugerencia() {
        if (!conversacionActualId) { return; }

        $.post(rutas.sugerencia + '/' + conversacionActualId + '/sugerencia')
            .done(function () {
                if (ultimoMensajeCompradorId) {
                    sugerenciasPorMensaje[ultimoMensajeCompradorId] = { estado: 'generando' };
                }
                actualizarPanelSugerencia();
            })
            .fail(function (xhr) {
                const mensaje = (xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo pedir la sugerencia.';
                toast('error', mensaje);
            });
    }

    function inicializarSugerencia() {
        $('#btn-pedir-sugerencia, #btn-reintentar-sugerencia').on('click', pedirSugerencia);

        $('#btn-usar-sugerencia').on('click', function () {
            const sugerencia = ultimoMensajeCompradorId != null ? sugerenciasPorMensaje[ultimoMensajeCompradorId] : null;
            if (!sugerencia || sugerencia.estado !== 'lista') { return; }

            sugerenciaSeleccionadaId = sugerencia.id;
            const $textarea = $('#mensajeria-texto-respuesta');
            $textarea.val(sugerencia.texto_sugerido || '').trigger('input').focus();
        });
    }

    function pintarMensajes(mensajes) {
        const $cont = $('#mensajeria-mensajes').empty();
        let fechaAnterior = null;

        mensajes.forEach(function (m) {
            const fechaDia = formatearDia(m.enviado_en);
            if (fechaDia !== fechaAnterior) {
                $cont.append($('<div>').addClass('mensajeria-separador-fecha').text(fechaDia));
                fechaAnterior = fechaDia;
            }

            const esNegocio = m.origen === 'negocio';
            const $fila = $('<div>').addClass('mensajeria-burbuja-fila ' + (esNegocio ? 'es-negocio' : 'es-comprador'));
            const $contenido = $('<div>').addClass('mensajeria-burbuja-contenido');
            const $texto = $('<p>').text(m.texto);
            const $hora = $('<span>').addClass('mensajeria-burbuja-hora').text(formatearHora(m.enviado_en));

            $contenido.addClass(esNegocio ? 'message-sent' : 'message-received');
            $contenido.append($texto).append($hora);
            $fila.append($contenido);
            $cont.append($fila);
        });

        $cont.scrollTop($cont[0] ? $cont[0].scrollHeight : 0);
    }

    function formatearDia(iso) {
        if (!iso) { return ''; }
        return new Date(iso).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }
    function formatearHora(iso) {
        if (!iso) { return ''; }
        return new Date(iso).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
    }

    function inicializarFormularioRespuesta() {
        const $textarea = $('#mensajeria-texto-respuesta');
        const $contador = $('.mensajeria-contador-caracteres');

        $textarea.on('input', function () {
            const $el = $(this);
            $el.css('height', 'auto').css('height', Math.min($el[0].scrollHeight, 96) + 'px');
            $contador.text($el.val().length + ' / ' + MAX_CARACTERES);
        });

        $('#form-responder-mensajeria').on('submit', function (e) {
            e.preventDefault();

            if (!conversacionActualId) { return; }

            const texto = $textarea.val();
            const $boton = $(this).find('button[type="submit"]');
            $boton.prop('disabled', true);

            const datos = { texto: texto };
            if (sugerenciaSeleccionadaId) { datos.sugerencia_id = sugerenciaSeleccionadaId; }

            $.post(rutas.responder + '/' + conversacionActualId + '/responder', datos)
                .done(function () {
                    toast('success', 'Respuesta enviada.');
                    $textarea.val('').css('height', 'auto');
                    $contador.text('0 / ' + MAX_CARACTERES);
                    sugerenciaSeleccionadaId = null;
                    abrirConversacion(conversacionActualId);
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail(function (xhr) {
                    const mensaje = (xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo enviar la respuesta.';
                    toast('error', mensaje);
                })
                .always(function () {
                    $boton.prop('disabled', false);
                });
        });
    }

    function iniciarPolling() {
        setInterval(function () {
            $.get(rutas.actualizaciones, ultimaActualizacion ? { desde: ultimaActualizacion } : {})
                .done(function (resp) {
                    if (!resp.ok) { return; }
                    ultimaActualizacion = resp.ahora;

                    const huboCambios = (resp.conversaciones && resp.conversaciones.length)
                        || (resp.mensajes && resp.mensajes.length);

                    if (huboCambios && tabla) {
                        tabla.ajax.reload(null, false);
                    }

                    (resp.sugerencias || []).forEach(function (s) {
                        sugerenciasPorMensaje[s.ml_mensaje_id] = s;
                    });
                    if ((resp.sugerencias || []).length) {
                        actualizarPanelSugerencia();
                    }

                    if (conversacionActualId && resp.mensajes && resp.mensajes.some((m) => m.ml_conversacion_id === conversacionActualId)) {
                        abrirConversacion(conversacionActualId);
                    }
                });
        }, POLL_MS);
    }
})();
