/**
 * Ingresos → Mercado Libre → Vinculaciones (spec 012, US2): DataTable
 * server-side + modal de alta/edición con Select2 (regla obligatoria de
 * diseño #5) + baja con advertencia. Todo por AJAX, sin recarga de página.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[mercadolibre-vinculaciones] jQuery no está disponible.');
        return;
    }

    const cfg = window.MercadoLibreVinculacionesConfig || {};
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
            console.log('[mercadolibre-vinculaciones][' + tipo + ']', mensaje);
        }
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    const hasSelect2 = !!($.fn && $.fn.select2);
    function initSelect2($el, opts) {
        if (!hasSelect2 || !$el || !$el.length) { return; }
        $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
    }

    let tabla = null;
    let idEnEdicion = null;
    // spec 115 (US3): ids marcados en la página visible. Se limpia en cada recarga de la tabla
    // (página, búsqueda, filtro u orden): la selección nunca abarca filas que no se están viendo.
    const seleccion = new Set();

    const escapar = (v) => $('<div>').text(v === null || v === undefined ? '' : String(v)).html();
    const dinero = (v) => v === null || v === undefined
        ? 'Desconocido'
        : '$ ' + Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $(function () {
        inicializarListado();
        inicializarModalAlta();
        inicializarEliminar();
        inicializarReactivar();
        inicializarVinculacionAutomatica();
        inicializarSincronizacionForzada();
        inicializarEliminarTodas();
        inicializarCambioTipo();
        inicializarCambioTipoLote();

        if (window.bootstrap && window.bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new window.bootstrap.Tooltip(el);
            });
        }
    });

    /**
     * "Sincronización forzada" (spec 035): recorre TODOS los vínculos, no sólo
     * pendientes. Corre encolada del lado del servidor (catálogos grandes
     * tardan más que el timeout del proxy si se corrieran en el propio
     * request — bug detectado 06/08/2026), así que el botón dispara el job y
     * hace polling del estado hasta que termina.
     */
    function inicializarSincronizacionForzada() {
        const $btn = $('#btn-sincronizacion-forzada');
        if (!$btn.length) { return; }

        const POLL_INTERVALO_MS = 4000;
        const POLL_MAXIMO = 90; // ~6 minutos

        function consultarEstado(intentosRestantes) {
            if (intentosRestantes <= 0) {
                toast('warning', 'La sincronización sigue en curso en segundo plano; recargá la tabla en un rato.');
                window.AppBtn.loading($btn, false);
                return;
            }

            $.ajax({ url: rutas.sincronizacionForzadaEstado, method: 'GET' })
                .done((estado) => {
                    if (estado.estado === 'en_curso') {
                        setTimeout(() => consultarEstado(intentosRestantes - 1), POLL_INTERVALO_MS);
                        return;
                    }

                    window.AppBtn.loading($btn, false);
                    toast(estado.estado === 'ok' ? 'success' : 'error', estado.mensaje || 'Sincronización forzada finalizada.');
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail(() => {
                    setTimeout(() => consultarEstado(intentosRestantes - 1), POLL_INTERVALO_MS);
                });
        }

        $btn.on('click', function () {
            window.AppBtn.loading($btn, true);

            $.ajax({ url: rutas.sincronizacionForzada, method: 'POST' })
                .done((resp) => {
                    toast('info', resp.mensaje || 'Sincronización forzada encolada.');
                    setTimeout(() => consultarEstado(POLL_MAXIMO), POLL_INTERVALO_MS);
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudo encolar la sincronización forzada.');
                    window.AppBtn.loading($btn, false);
                });
        });
    }

    /** "Eliminar todas las vinculaciones" (spec 035): borrado masivo, sólo lado CRM. */
    function inicializarEliminarTodas() {
        const $btn = $('#btn-eliminar-todas-vinculaciones');
        const modalEl = document.getElementById('modal-eliminar-todas-vinculaciones');
        if (!$btn.length || !modalEl) { return; }

        const modal = new bootstrap.Modal(modalEl);

        $btn.on('click', () => modal.show());

        $('#btn-confirmar-eliminar-todas-vinculaciones').on('click', function () {
            const $confirmar = $(this);
            window.AppBtn.loading($confirmar, true);

            $.ajax({ url: rutas.eliminarTodas, method: 'DELETE' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculaciones eliminadas.');
                    modal.hide();
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudieron eliminar las vinculaciones.');
                })
                .always(() => {
                    window.AppBtn.loading($confirmar, false);
                });
        });
    }

    const MOTIVOS_ML = {
        sin_sku: 'Sin SKU de vendedor cargado',
        producto_no_encontrado: 'El SKU no corresponde a ningún producto',
        ya_vinculado: 'El producto ya está vinculado a otra publicación',
    };

    function inicializarVinculacionAutomatica() {
        const $btn = $('#btn-vincular-automaticamente');
        if (!$btn.length) { return; }

        const modalEl = document.getElementById('modal-resultado-vinculacion-automatica');
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

        $btn.on('click', function () {
            window.AppBtn.loading($btn, true);

            $.ajax({ url: rutas.vincularAutomaticamente, method: 'POST' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculación automática ejecutada.');

                    if (resp.fallidas > 0 && modal) {
                        $('#resultado-vinculacion-automatica-body').html(renderResultadoVinculacionAutomatica(resp));
                        modal.show();
                    }

                    if (tabla && resp.vinculadas > 0) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudo ejecutar la vinculación automática.');
                })
                .always(() => {
                    window.AppBtn.loading($btn, false);
                });
        });
    }

    function renderResultadoVinculacionAutomatica(resp) {
        const filas = (resp.detalle_fallidas || []).map((f) => (
            '<tr><td>' + $('<div>').text(f.referencia).html() + '</td><td>'
            + $('<div>').text(MOTIVOS_ML[f.motivo] || f.motivo).html() + '</td></tr>'
        )).join('');

        return '<p>' + resp.vinculadas + ' vinculada(s), ' + resp.fallidas + ' sin vincular de ' + resp.total + ' publicaciones pendientes.</p>'
            + '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Publicación</th><th>Motivo</th></tr></thead><tbody>'
            + filas + '</tbody></table></div>';
    }

    function inicializarListado() {
        const $tabla = $('#tabla-ml-vinculaciones');
        if (!$tabla.length) { return; }

        tabla = $tabla.DataTable({
            processing: true, serverSide: true,
            language: {
                search: 'Buscar:', lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ vinculaciones', infoEmpty: 'Sin vinculaciones',
                infoFiltered: '(filtrado de _MAX_ en total)', zeroRecords: 'No se encontraron vinculaciones',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            // spec 065/FR-025: el filtro viaja al endpoint server-side en cada recarga.
            ajax: {
                url: rutas.datatable,
                data: (d) => {
                    d.logistic_type = $('#filtro-logistica').val() || '';
                    d.listing_type = $('#filtro-tipo').val() || '';
                },
            },
            columns: [
                {
                    // spec 115 (US3): casilla de selección para el cambio de tipo masivo.
                    data: 'id', name: 'seleccion', orderable: false, searchable: false, className: 'no-colvis',
                    render: (id) => '<input type="checkbox" class="form-check-input js-check-vinculacion" value="' + id + '">',
                },
                { data: 'acciones', name: 'acciones', orderable: false, searchable: false, className: 'no-colvis' },
                { data: 'ml_item_id', name: 'ml_item_id' },
                { data: 'titulo_ml', name: 'titulo_ml' },
                {
                    // spec 115/FR-018: el producto del CRM se identifica por ID + código + nombre; el
                    // endpoint busca por los tres (filterColumn sobre `producto.nombre`).
                    data: 'producto_nombre', name: 'producto.nombre',
                    render: (data, type, row) => {
                        if (!row.producto_id) { return '<span class="text-muted">—</span>'; }

                        return '<span class="text-muted">#' + row.producto_id + ' · ' + escapar(row.producto_codigo || 's/código') + ' —</span> '
                            + escapar(data);
                    },
                },
                {
                    // spec 115/FR-016: Premium destacada (cotiza por otra lista y cobra más comisión).
                    data: 'tipo_etiqueta', name: 'listing_type_id', searchable: false,
                    render: (data, type, row) => {
                        if (row.listing_type_id === 'gold_pro') {
                            return '<span class="badge bg-primary">Premium</span>';
                        }

                        return row.listing_type_id
                            ? '<span>' + escapar(data) + '</span>'
                            : '<span class="text-muted">' + escapar(data) + '</span>';
                    },
                },
                { data: 'created_at', name: 'created_at' },
                {
                    // spec 065/FR-024: Full va destacada porque cambia el comportamiento del
                    // sistema (no recibe stock); el resto es informativo y va como texto.
                    data: 'logistica_etiqueta', name: 'logistic_type', searchable: false,
                    render: (data, type, row) => {
                        if (row.es_full) {
                            return '<span class="badge bg-warning text-dark fw-bold">FULL</span>';
                        }

                        return row.logistic_type
                            ? '<span>' + data + '</span>'
                            : '<span class="text-muted">' + data + '</span>';
                    },
                },
                {
                    // Unidades del depósito que publica ML — es el número que tiene que
                    // coincidir con el de la publicación, no el stock total del producto.
                    data: 'stock_ml', name: 'stock_ml', orderable: false, searchable: false,
                    render: (data) => data === null || data === undefined
                        ? '—'
                        : '<span class="badge bg-' + (data > 0 ? 'light text-dark' : 'danger') + '">' + data + '</span>',
                },
                {
                    data: 'stock_estado', name: 'stock_estado', orderable: false, searchable: false,
                    render: (data, type, row) => renderEstadoStock(data, row),
                },
                {
                    // spec 063/FR-018: diferencia entre el stock del CRM y lo último publicado.
                    data: 'stock_diferencia', name: 'stock_diferencia', orderable: false, searchable: false,
                    render: (data) => data === null || data === undefined
                        ? '—'
                        : (data === 0 ? '<span class="text-muted">0</span>' : '<span class="badge bg-warning text-dark">' + (data > 0 ? '+' : '') + data + '</span>'),
                },
                {
                    data: 'precio_estado', name: 'precio_estado', orderable: false, searchable: false,
                    render: (data, type, row) => renderEstadoPrecio(data, row),
                },
            ],
            stateSave: true,
            // Los filtros viajan con el estado guardado (FR-017): al volver a la pantalla siguen puestos.
            stateSaveParams: (settings, data) => {
                data.filtroLogistica = $('#filtro-logistica').val() || '';
                data.filtroTipo = $('#filtro-tipo').val() || '';
            },
            stateLoadParams: (settings, data) => {
                if (data.filtroLogistica !== undefined) { $('#filtro-logistica').val(data.filtroLogistica); }
                if (data.filtroTipo !== undefined) { $('#filtro-tipo').val(data.filtroTipo); }
            },
            colReorder: { fixedColumnsLeft: 2 }, // Arrastrar encabezados reordena (se guarda con stateSave); Selección y Acciones quedan fijas.
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                    // Selección y Acciones no se pueden ocultar (por clase, no por índice: colReorder).
                    columns: ':not(.no-colvis)',
                },
            ],
        });

        $tabla.on('preXhr.dt', function () {
            seleccion.clear();
            $('#check-todas-vinculaciones').prop('checked', false);
            actualizarSeleccion();
        });

        $tabla.on('change', '.js-check-vinculacion', function () {
            const id = Number(this.value);
            if (this.checked) { seleccion.add(id); } else { seleccion.delete(id); }
            actualizarSeleccion();
        });

        // "Todas las de esta página" (FR-015b): sólo las filas visibles.
        $('#check-todas-vinculaciones').on('change', function () {
            const marcar = this.checked;
            $tabla.find('tbody .js-check-vinculacion').each(function () {
                this.checked = marcar;
                const id = Number(this.value);
                if (marcar) { seleccion.add(id); } else { seleccion.delete(id); }
            });
            actualizarSeleccion();
        });

        $tabla.one('init.dt', function () {
            tabla.buttons().container().appendTo('#dt-buttons-ml-vinculaciones');
        });

        // Regla obligatoria #5: select de datos dinámicos con Select2. Cambiar el filtro
        // recarga sólo la tabla (regla #2), nunca la página.
        $('#filtro-logistica').select2({ width: '100%', minimumResultsForSearch: -1 })
            .on('change', function () { tabla.ajax.reload(); });
        $('#filtro-tipo').select2({ width: '100%', minimumResultsForSearch: -1 })
            .on('change', function () { tabla.ajax.reload(); });
    }

    const MAXIMO_LOTE = 100;

    function actualizarSeleccion() {
        const n = seleccion.size;
        $('.js-cambiar-tipo-lote').prop('disabled', n === 0 || n > MAXIMO_LOTE);
        $('#conteo-seleccion-vinculaciones').text(
            n === 0 ? 'Ninguna publicación seleccionada' : n + (n === 1 ? ' publicación seleccionada' : ' publicaciones seleccionadas')
        );
    }

    const ETIQUETAS_TIPO = { gold_pro: 'Premium', gold_special: 'Clásica' };

    /**
     * Ajuste 16/10/2026 (FR-019): opciones de cuotas como radios —son 2 a 4 opciones fijas, no un
     * catálogo— con el cargo de ML de cada una cuando el servidor lo pudo consultar.
     */
    function renderOpcionesCuotas($contenedor, nombre, opciones, sugerida) {
        $contenedor.html((opciones || []).map((o, i) => {
            const id = nombre + '-' + i;
            const cargo = o.cargo
                ? ' <span class="text-muted">— cargo ' + escapar(String(o.cargo.porcentaje).replace('.', ',')) + '%'
                    + (o.cargo.importe !== null ? ' (' + escapar(dinero(o.cargo.importe)) + ')' : '') + '</span>'
                : '';
            return '<div class="form-check">'
                + '<input class="form-check-input" type="radio" name="' + nombre + '" id="' + id + '" value="' + escapar(o.id) + '"'
                + (o.id === sugerida ? ' checked' : '') + '>'
                + '<label class="form-check-label small" for="' + id + '">' + escapar(o.etiqueta) + cargo + '</label></div>';
        }).join(''));
    }

    /**
     * spec 115 (US1): cambio de tipo de una publicación. El modal muestra lo que va a pasar
     * (tipo y precio antes/después, lista de la que sale el precio) y sólo deja confirmar si el
     * servidor dice que se puede: la verificación contra ML y las reglas viven allá.
     */
    function inicializarCambioTipo() {
        const modalEl = document.getElementById('modal-cambio-tipo');
        if (!modalEl) { return; }
        const modal = new bootstrap.Modal(modalEl);
        const $confirmar = $('#btn-confirmar-cambio-tipo');
        let vinculoId = null;
        let destino = null;

        $(document).on('click', '.js-cambiar-tipo', function (e) {
            e.preventDefault();
            vinculoId = $(this).data('id');
            destino = $(this).data('destino') || null;

            $('#modal-cambio-tipo-titulo').text(destino ? 'Pasar a ' + ETIQUETAS_TIPO[destino] : 'Cambiar tipo de publicación');
            $('#cambio-tipo-cargando').removeClass('d-none');
            $('#cambio-tipo-contenido').addClass('d-none');
            $confirmar.prop('disabled', true).text('Confirmar');
            modal.show();

            const url = rutas.cambioTipoPrevisualizar.replace('__ID__', vinculoId) + (destino ? '?destino=' + destino : '');

            $.ajax({ url: url, method: 'GET' })
                .done((resp) => {
                    destino = resp.tipo_destino;
                    renderPrevisualizacion(resp);
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    modal.hide();
                    toast('error', resp.mensaje || resp.message || 'No se pudo consultar la publicación en Mercado Libre.');
                });
        });

        function renderPrevisualizacion(r) {
            const v = r.vinculo || {};
            const p = v.producto;

            $('#modal-cambio-tipo-titulo').text(r.tipo_destino_etiqueta ? 'Pasar a ' + r.tipo_destino_etiqueta : 'Cambiar tipo de publicación');
            $('#cambio-tipo-publicacion').html('<code>' + escapar(v.ml_item_id) + '</code>' + (v.titulo_ml ? ' — ' + escapar(v.titulo_ml) : ''));
            $('#cambio-tipo-producto').html(p ? '#' + p.id + ' · ' + escapar(p.codigo || 's/código') + ' — ' + escapar(p.nombre) : '—');
            $('#cambio-tipo-tipos').html(escapar(r.tipo_actual_etiqueta) + (r.tipo_destino_etiqueta ? ' <i class="fas fa-arrow-right mx-1"></i> <strong>' + escapar(r.tipo_destino_etiqueta) + '</strong>' : ''));
            $('#cambio-tipo-precios').html(
                r.precio_destino === null
                    ? escapar(dinero(r.precio_actual))
                    : escapar(dinero(r.precio_actual)) + ' <i class="fas fa-arrow-right mx-1"></i> <strong>' + escapar(dinero(r.precio_destino)) + '</strong>'
            );
            $('#cambio-tipo-lista').text(r.lista_destino ? (r.lista_destino.nombre || '#' + r.lista_destino.id) : '—');
            $('#cambio-tipo-cuotas-actual').text(r.cuotas_actual_etiqueta || '—');
            const opcionesCuotas = r.puede_confirmar ? (r.cuotas_opciones || []) : [];
            renderOpcionesCuotas($('#cambio-tipo-cuotas'), 'cambio-tipo-cuotas', opcionesCuotas, r.cuotas_sugerida);
            $('#cambio-tipo-cuotas-bloque').toggleClass('d-none', !opcionesCuotas.length);
            $('#cambio-tipo-cuotas-ayuda').toggleClass('d-none', !opcionesCuotas.some((o) => o.cargo));
            $('#cambio-tipo-aviso-comision').toggleClass('d-none', !r.aviso_comision || !!r.motivo_bloqueo || !!r.sin_cambios);
            // spec 116 (FR-016): al pasar a Clásica se pierden las cuotas sin interés de Premium.
            const avisoCuotas = r.aviso_pierde_cuotas && !r.motivo_bloqueo && !r.sin_cambios;
            $('#cambio-tipo-aviso-cuotas').toggleClass('d-none', !avisoCuotas)
                .text(avisoCuotas ? 'Al pasar a Clásica, la publicación pierde las ' + r.aviso_pierde_cuotas + '.' : '');
            // spec 116 (FR-011a): si ML ya lo tenía, confirmar publica el precio de la lista de ese tipo.
            $('#cambio-tipo-sin-cambios').toggleClass('d-none', !r.sin_cambios)
                .text(r.sin_cambios ? 'En Mercado Libre la publicación ya es ' + r.tipo_destino_etiqueta + '. Al confirmar se actualiza el CRM y se publica el precio de la lista de ese tipo.' : '');
            $('#cambio-tipo-bloqueo').toggleClass('d-none', !r.motivo_bloqueo).text(r.motivo_bloqueo || '');

            $('#cambio-tipo-cargando').addClass('d-none');
            $('#cambio-tipo-contenido').removeClass('d-none');
            $confirmar.prop('disabled', !(r.puede_confirmar || r.sin_cambios));
        }

        $confirmar.on('click', function () {
            if (!vinculoId || !destino) { return; }
            window.AppBtn.loading($confirmar, true);

            const datos = { destino: destino };
            const cuotas = $('input[name="cambio-tipo-cuotas"]:checked').val();
            if (cuotas) { datos.cuotas = cuotas; }

            $.ajax({ url: rutas.cambioTipoEjecutar.replace('__ID__', vinculoId), method: 'POST', data: datos })
                .done((resp) => {
                    const conAviso = resp.resultado === 'cambiado_precio_pendiente' || resp.resultado === 'cambiado_cuotas_pendientes';
                    toast(conAviso ? 'warning' : 'success', resp.mensaje);
                    modal.hide();
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast(resp.resultado === 'revertido' ? 'warning' : 'error', resp.mensaje || resp.message || 'No se pudo cambiar el tipo de la publicación.');
                    if (xhr.status !== 409) { modal.hide(); }
                })
                .always(() => {
                    window.AppBtn.loading($confirmar, false);
                    if (tabla) { tabla.ajax.reload(null, false); }
                });
        });
    }

    const ETIQUETAS_RESULTADO = {
        cambiado: 'Cambiadas',
        cambiado_precio_pendiente: 'Cambiadas con precio pendiente',
        cambiado_cuotas_pendientes: 'Cambiadas sin las cuotas elegidas',
        sin_cambios: 'Sin cambios (ya tenían ese tipo)',
        bloqueado: 'Bloqueadas',
        fallido: 'Fallidas',
        revertido: 'Revertidas (se restituyó el precio)',
    };

    /**
     * spec 115 (US3): cambio de tipo masivo. La confirmación anticipa las omitidas con datos del
     * CRM; el lote corre en segundo plano y la pantalla consulta su estado hasta que termina
     * (mismo patrón que la Sincronización forzada).
     */
    function inicializarCambioTipoLote() {
        const modalEl = document.getElementById('modal-cambio-tipo-lote');
        if (!modalEl) { return; }
        const modal = new bootstrap.Modal(modalEl);
        const $confirmar = $('#btn-confirmar-cambio-tipo-lote');
        const POLL_INTERVALO_MS = 2000;
        const POLL_MAXIMO = 300; // ~10 minutos, el timeout del job
        let destino = null;
        let idsAEnviar = [];

        function mostrar(seccion) {
            ['cargando', 'confirmacion', 'progreso', 'resumen'].forEach((s) => {
                $('#cambio-tipo-lote-' + s).toggleClass('d-none', s !== seccion);
            });
        }

        $('.js-cambiar-tipo-lote').on('click', function () {
            destino = $(this).data('destino');
            const ids = Array.from(seleccion);
            if (!ids.length) { return; }

            $('#modal-cambio-tipo-lote-titulo').text('Pasar selección a ' + ETIQUETAS_TIPO[destino]);
            $('#btn-cerrar-cambio-tipo-lote').text('Cancelar');
            $confirmar.removeClass('d-none').prop('disabled', true);
            mostrar('cargando');
            modal.show();

            $.ajax({ url: rutas.cambioTipoLotePrevisualizar, method: 'POST', data: { destino: destino, ids: ids } })
                .done(renderConfirmacion)
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    modal.hide();
                    toast('error', resp.mensaje || resp.message || 'No se pudo preparar el cambio de tipo.');
                });
        });

        function renderConfirmacion(r) {
            idsAEnviar = r.a_cambiar.map((f) => f.id);

            $('#cambio-tipo-lote-conteo-cambiar').text(r.a_cambiar.length);
            $('#cambio-tipo-lote-cambiar').html(r.a_cambiar.length
                ? r.a_cambiar.map((f) => '<tr><td><code>' + escapar(f.ml_item_id) + '</code></td><td>' + escapar(f.producto) + '</td>'
                    + '<td class="text-end">' + escapar(dinero(f.precio_actual)) + '</td>'
                    + '<td class="text-end fw-semibold">' + escapar(dinero(f.precio_destino)) + '</td></tr>').join('')
                : '<tr><td colspan="4" class="text-muted">Ninguna publicación de la selección se puede cambiar.</td></tr>');

            $('#cambio-tipo-lote-conteo-omitidas').text(r.omitidas.length);
            $('#cambio-tipo-lote-omitidas').html(r.omitidas.length
                ? r.omitidas.map((f) => '<tr><td><code>' + escapar(f.ml_item_id) + '</code></td><td>' + escapar(f.motivo) + '</td></tr>').join('')
                : '<tr><td colspan="2" class="text-muted">Ninguna.</td></tr>');

            $('#cambio-tipo-lote-aviso-comision').toggleClass('d-none', !r.aviso_comision);
            $('#cambio-tipo-lote-aviso-cuotas').toggleClass('d-none', !r.aviso_pierde_cuotas_lote);
            renderOpcionesCuotas($('#cambio-tipo-lote-cuotas'), 'cambio-tipo-lote-cuotas', r.cuotas_opciones, r.cuotas_sugerida);
            $confirmar.prop('disabled', !r.puede_confirmar);
            mostrar('confirmacion');
        }

        $confirmar.on('click', function () {
            if (!idsAEnviar.length) { return; }
            window.AppBtn.loading($confirmar, true);

            const datos = { destino: destino, ids: idsAEnviar };
            const cuotas = $('input[name="cambio-tipo-lote-cuotas"]:checked').val();
            if (cuotas) { datos.cuotas = cuotas; }

            $.ajax({ url: rutas.cambioTipoLoteEjecutar, method: 'POST', data: datos })
                .done((resp) => {
                    toast('info', resp.mensaje || 'Cambio de tipo encolado.');
                    $confirmar.addClass('d-none');
                    $('#btn-cerrar-cambio-tipo-lote').text('Cerrar');
                    actualizarProgreso({ procesadas: 0, total: idsAEnviar.length });
                    mostrar('progreso');
                    setTimeout(() => consultarEstado(POLL_MAXIMO), POLL_INTERVALO_MS);
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudo iniciar el cambio de tipo.');
                })
                .always(() => window.AppBtn.loading($confirmar, false));
        });

        function actualizarProgreso(estado) {
            const total = estado.total || 1;
            const hechas = estado.procesadas || 0;
            $('#cambio-tipo-lote-progreso-texto').text('Procesando ' + hechas + ' de ' + total + '…');
            $('#cambio-tipo-lote-barra').css('width', Math.round(hechas * 100 / total) + '%');
        }

        function consultarEstado(intentosRestantes) {
            if (intentosRestantes <= 0) {
                toast('warning', 'El cambio de tipo sigue en curso en segundo plano; recargá la tabla en un rato.');
                return;
            }

            $.ajax({ url: rutas.cambioTipoLoteEstado, method: 'GET' })
                .done((estado) => {
                    if (estado.estado === 'en_curso') {
                        actualizarProgreso(estado);
                        setTimeout(() => consultarEstado(intentosRestantes - 1), POLL_INTERVALO_MS);
                        return;
                    }

                    renderResumen(estado);
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail(() => setTimeout(() => consultarEstado(intentosRestantes - 1), POLL_INTERVALO_MS));
        }

        function renderResumen(estado) {
            const resumen = estado.resumen || {};
            const lineas = Object.keys(ETIQUETAS_RESULTADO)
                .filter((k) => resumen[k] > 0)
                .map((k) => '<li>' + ETIQUETAS_RESULTADO[k] + ': <strong>' + resumen[k] + '</strong></li>')
                .join('');
            const detalle = (estado.detalle || []).map((f) => '<tr><td><code>' + escapar(f.ml_item_id) + '</code></td><td>'
                + escapar(ETIQUETAS_RESULTADO[f.resultado] || f.resultado) + '</td><td>' + escapar(f.mensaje) + '</td></tr>').join('');

            let html = estado.estado === 'error'
                ? '<div class="alert alert-danger small">' + escapar(estado.mensaje || 'El cambio de tipo se interrumpió.') + '</div>'
                : '';
            html += '<ul class="mb-3">' + (lineas || '<li>No se procesó ninguna publicación.</li>') + '</ul>';
            if (detalle) {
                html += '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Publicación</th><th>Resultado</th><th>Motivo</th></tr></thead><tbody>'
                    + detalle + '</tbody></table></div>';
            }

            $('#cambio-tipo-lote-resumen').html(html);
            mostrar('resumen');

            const cambiadas = (resumen.cambiado || 0) + (resumen.cambiado_precio_pendiente || 0) + (resumen.cambiado_cuotas_pendientes || 0);
            toast(estado.estado === 'error' ? 'error' : (detalle ? 'warning' : 'success'),
                cambiadas + ' publicación(es) cambiada(s) a ' + (ETIQUETAS_TIPO[destino] || destino) + '.');
        }
    }

    const ESTADOS_STOCK = {
        sincronizado: { color: 'success', etiqueta: 'Sincronizado' },
        pendiente: { color: 'warning', etiqueta: 'Pendiente' },
        error: { color: 'danger', etiqueta: 'Error' },
        // spec 063/FR-014/FR-015: dejó de reintentarse tras 5 fallas consecutivas con el mismo error.
        requiere_intervencion: { color: 'dark', etiqueta: 'Requiere intervención' },
    };

    function renderEstadoStock(estado, row) {
        const info = ESTADOS_STOCK[estado] || ESTADOS_STOCK.pendiente;
        let html = '<span class="badge bg-' + info.color + '">' + info.etiqueta + '</span>';

        if (estado === 'sincronizado' && row.stock_sincronizado_en) {
            html += ' <span class="text-muted small">' + row.stock_sincronizado_en + '</span>';
        }

        if (estado === 'error' && row.stock_error) {
            html += ' <i class="fas fa-circle-info text-danger ms-1" title="'
                + $('<div>').text(row.stock_error + (row.stock_error_en ? ' (' + row.stock_error_en + ')' : '')).html()
                + '"></i>';
        }

        if (estado === 'requiere_intervencion') {
            const detalle = (row.stock_error || 'Error permanente')
                + (row.stock_error_desde ? ' — desde el ' + row.stock_error_desde : '')
                + (row.stock_intentos_fallidos ? ' — ' + row.stock_intentos_fallidos + ' intentos' : '');
            html += ' <i class="fas fa-circle-info text-dark ms-1" title="' + $('<div>').text(detalle).html() + '"></i>'
                + ' <button type="button" class="btn btn-sm btn-outline-dark ms-1 js-reactivar-vinculacion" data-id="' + row.id + '">'
                + 'Reactivar</button>';
        }

        return html;
    }

    const ESTADOS_PRECIO = {
        sincronizado: { color: 'success', etiqueta: 'Sincronizado' },
        pendiente: { color: 'warning', etiqueta: 'Pendiente' },
        error: { color: 'danger', etiqueta: 'Error' },
    };

    function renderEstadoPrecio(estado, row) {
        const info = ESTADOS_PRECIO[estado] || ESTADOS_PRECIO.pendiente;
        let html = '<span class="badge bg-' + info.color + '">' + info.etiqueta + '</span>';

        if (estado === 'sincronizado' && row.precio_sincronizado_en) {
            html += ' <span class="text-muted small">' + row.precio_sincronizado_en + '</span>';
        }

        if (estado === 'error' && row.precio_error) {
            html += ' <i class="fas fa-circle-info text-danger ms-1" title="'
                + $('<div>').text(row.precio_error + (row.precio_error_en ? ' (' + row.precio_error_en + ')' : '')).html()
                + '"></i>';
        }

        return html;
    }

    function limpiarErrores() {
        $('#form-vinculacion .is-invalid').removeClass('is-invalid');
        $('#form-vinculacion .invalid-feedback').text('');
    }

    function mostrarSelectPublicacion(seleccionado, deshabilitado) {
        const $select = $('#vinculacion-ml-item-id');
        $select.empty();

        if (seleccionado && seleccionado.id) {
            const opcion = new Option(seleccionado.text, seleccionado.id, true, true);
            $select.append(opcion);
        }

        $select.prop('disabled', !!deshabilitado);

        initSelect2($select, {
            dropdownParent: $('#modal-vinculacion'),
            placeholder: 'Buscar publicación…',
            allowClear: true,
            ajax: {
                url: rutas.pendientes,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({
                    results: data.data.map((p) => ({ id: p.id, text: p.text, titulo: p.titulo })),
                }),
            },
        });

        if (seleccionado && seleccionado.id) {
            $select.trigger('change.select2');
        }

        $select.off('select2:select').on('select2:select', function (e) {
            const data = e.params.data;
            $('#vinculacion-titulo-ml').val(data.titulo || '');
        });
    }

    function mostrarSelectProducto(seleccionado) {
        const $select = $('#vinculacion-producto-id');
        $select.empty();

        if (seleccionado && seleccionado.id) {
            const opcion = new Option(seleccionado.nombre, seleccionado.id, true, true);
            $select.append(opcion);
        }

        initSelect2($select, {
            dropdownParent: $('#modal-vinculacion'),
            placeholder: 'Buscar producto…',
            allowClear: true,
            ajax: {
                url: rutas.productosOpciones,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({ results: data.data.map((p) => ({ id: p.id, text: p.nombre + (p.codigo ? ' (' + p.codigo + ')' : '') })) }),
            },
        });

        if (seleccionado && seleccionado.id) {
            $select.trigger('change.select2');
        }
    }

    function inicializarModalAlta() {
        const modalEl = document.getElementById('modal-vinculacion');
        if (!modalEl) { return; }
        const modal = new bootstrap.Modal(modalEl);

        $(document).on('click', '.js-editar-vinculacion', function (e) {
            e.preventDefault();
            const $btn = $(this);
            idEnEdicion = $btn.data('id');
            limpiarErrores();
            $('#modal-vinculacion-titulo').text('Editar vinculación');
            $('#vinculacion-id').val(idEnEdicion);
            $('#vinculacion-titulo-ml').val($btn.data('titulo-ml'));
            // La publicación vinculada no se puede cambiar en una edición — sólo
            // el producto del CRM y el título.
            const tituloMl = $btn.data('titulo-ml');
            mostrarSelectPublicacion({
                id: $btn.data('ml-item-id'),
                text: $btn.data('ml-item-id') + (tituloMl ? ' — ' + tituloMl : ''),
            }, true);
            mostrarSelectProducto({ id: $btn.data('producto-id'), nombre: $btn.data('producto-nombre') });
            modal.show();
        });

        $('#form-vinculacion').on('submit', function (e) {
            e.preventDefault();
            limpiarErrores();

            const payload = {
                ml_item_id: $('#vinculacion-ml-item-id').val(),
                titulo_ml: $('#vinculacion-titulo-ml').val(),
                producto_id: $('#vinculacion-producto-id').val(),
            };

            // Sólo edición: el alta manual se reemplazó por la vinculación automática.
            $.ajax({ url: rutas.base + '/' + idEnEdicion, method: 'PATCH', data: payload })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculación guardada.');
                    modal.hide();
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    if (xhr.status === 422 && resp.errors) {
                        Object.keys(resp.errors).forEach((campo) => {
                            $('#vinculacion-' + campo.replace(/_/g, '-')).addClass('is-invalid');
                            $('#error-' + campo.replace(/_/g, '-')).text(resp.errors[campo][0]);
                        });
                    }
                    toast('error', resp.message || resp.mensaje || 'No se pudo guardar la vinculación.');
                });
        });
    }

    /** spec 063 (T025): reactiva una publicación bloqueada por error permanente, sin recargar la página. */
    function inicializarReactivar() {
        $(document).on('click', '.js-reactivar-vinculacion', function () {
            const id = $(this).data('id');
            if (!id) { return; }
            const $btn = $(this);
            window.AppBtn.loading($btn, true);

            $.ajax({ url: rutas.base + '/' + id + '/reactivar', method: 'POST' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Publicación reactivada.');
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || resp.message || 'No se pudo reactivar la publicación.');
                })
                .always(() => {
                    window.AppBtn.loading($btn, false);
                });
        });
    }

    function inicializarEliminar() {
        let idAEliminar = null;
        const modalEl = document.getElementById('modal-eliminar-vinculacion');
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

        $(document).on('click', '.js-eliminar-vinculacion', function (e) {
            e.preventDefault();
            idAEliminar = $(this).data('id');
            if (modal) { modal.show(); }
        });

        $('#btn-confirmar-eliminar-vinculacion').on('click', () => {
            if (!idAEliminar) { return; }

            $.ajax({ url: rutas.base + '/' + idAEliminar, method: 'DELETE' })
                .done((resp) => {
                    toast('success', resp.mensaje || 'Vinculación eliminada.');
                    if (resp.advertencia) { toast('warning', resp.advertencia); }
                    if (modal) { modal.hide(); }
                    if (tabla) { tabla.ajax.reload(null, false); }
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};
                    toast('error', resp.mensaje || 'No se pudo eliminar la vinculación.');
                });
        });
    }
    // ====================== PRECIOS RETENIDOS POR EL CORTE (spec 084, US1) ======================
    //
    // Cada fila es una bajada de precio que NO se publicó. En Mercado Libre sigue vigente el precio
    // anterior, así que mientras esto no se resuelva no se está vendiendo barato — pero tampoco se
    // está aplicando el precio nuevo, y por eso el panel se muestra arriba de todo cuando hay algo.
    function montarRetenciones() {
        if (!$('#tabla-retenciones-precio').length || !rutas.retenciones) { return null; }

        const money = (v) => v === null || v === undefined
            ? '—'
            : '$ ' + Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        const tabla = $('#tabla-retenciones-precio').DataTable({
            processing: true,
            serverSide: true,
            // Ni `responsive` ni `scrollX`: son 8 columnas que entran en pantalla si se las deja
            // partir en varias líneas. `responsive` colapsaba "Motivo" —la columna que explica por
            // qué se frenó ese precio, o sea lo único que hace falta leer para decidir— y `scrollX`
            // con textos largos estiraba la tabla tanto que el scroll no llegaba a las Acciones.
            // Los anchos de abajo evitan que Producto y Motivo se coman todo el espacio.
            autoWidth: false,
            searching: false,
            lengthChange: false,
            pageLength: 10,
            ajax: { url: rutas.retenciones },
            language: { url: '/vendor/datatables/es-AR.json' },
            columns: [
                { data: 'ml_item_id', width: '9%', render: (v) => '<code>' + v + '</code>' },
                { data: 'producto', width: '26%' },
                { data: 'tipo_publicacion', width: '7%' },
                { data: 'precio_publicado', width: '11%', className: 'text-end', render: money },
                {
                    data: null,
                    width: '13%',
                    className: 'text-end',
                    // Si el precio de la lista cambió desde que se retuvo, se muestran los dos: al
                    // aprobar se publica el VIGENTE, y ver un número y que salga otro sería peor
                    // que cualquier bug.
                    render: (fila) => {
                        const propuesto = money(fila.precio_propuesto);
                        if (fila.precio_vigente_lista === null
                            || Math.abs(fila.precio_vigente_lista - fila.precio_propuesto) < 0.005) {
                            return propuesto;
                        }

                        return '<span class="text-decoration-line-through text-muted">' + propuesto + '</span><br>' +
                            '<span class="fw-semibold">' + money(fila.precio_vigente_lista) + '</span>' +
                            '<br><small class="text-warning">la lista cambió</small>';
                    },
                },
                {
                    data: 'caida_pct',
                    width: '8%',
                    className: 'text-end fw-semibold text-danger',
                    render: (v) => v === null ? '—' : Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %',
                },
                { data: 'motivo_legible', width: '16%' },
                {
                    data: 'id',
                    width: '10%',
                    orderable: false,
                    className: 'text-end text-nowrap',
                    render: (id) => '<button type="button" class="btn btn-sm btn-success js-aprobar-retencion" data-id="' + id + '">Publicar</button> ' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary js-rechazar-retencion" data-id="' + id + '">Descartar</button>',
                },
            ],
            drawCallback: function (ajuste) {
                const total = (ajuste.json && ajuste.json.recordsTotal) || 0;
                $('#card-retenciones-precio').toggleClass('d-none', total === 0);
                $('#conteo-retenciones-precio').text(total);
            },
        });

        const resolver = function (id, accion, cuerpo) {
            return $.post(rutas.retencionesBase + '/' + id + '/' + accion, cuerpo || {})
                .done((resp) => {
                    toast('success', resp.mensaje);
                    tabla.ajax.reload(null, false);
                })
                .fail((xhr) => {
                    const resp = xhr.responseJSON || {};

                    // FR-014: el precio de la lista cambió desde que se retuvo. Se muestran los dos
                    // importes y se pide una confirmación explícita antes de publicar el vigente.
                    if (resp.requiere_confirmacion) {
                        const ok = window.confirm(
                            'El precio de la lista cambió desde que se retuvo.\n\n' +
                            'Se había frenado: ' + money(resp.precio_propuesto) + '\n' +
                            'Se publicaría ahora: ' + money(resp.precio_vigente_lista) + '\n\n' +
                            '¿Publicar el precio actual?'
                        );

                        if (ok) { resolver(id, accion, { confirma_precio_distinto: 1 }); }

                        return;
                    }

                    toast('error', resp.mensaje || 'No se pudo resolver la retención.');

                    if (xhr.status === 409) { tabla.ajax.reload(null, false); }
                });
        };

        $('#tabla-retenciones-precio').on('click', '.js-aprobar-retencion', function () {
            resolver($(this).data('id'), 'aprobar');
        });

        $('#tabla-retenciones-precio').on('click', '.js-rechazar-retencion', function () {
            resolver($(this).data('id'), 'rechazar');
        });

        return tabla;
    }

    montarRetenciones();
})();
