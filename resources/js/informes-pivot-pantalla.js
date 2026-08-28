/**
 * Cableado de las pestañas Rankings / "Arma tu Informe" en la pantalla del informe (spec 069).
 *
 * Vive aparte de `informes-pivot.js` a propósito: aquel es el motor —envuelve PivotTable.js y no
 * sabe nada de esta pantalla—, y este es el pegamento con el DOM y con los endpoints. Lo comparten
 * Ventas y Compras: la única diferencia entre los dos son las rutas y los rankings, que llegan por
 * parámetro.
 *
 * El cambio de pestaña NO recarga (FR-002): el rango y los filtros del informe valen igual para el
 * detalle y para el cruce, así que recargar obligaría a re-elegirlos. La URL sí cambia, con
 * `history.pushState`, para que el enlace de un ranking se pueda compartir (FR-004).
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) { return; }

    function iniciar(opciones) {
        const rutas = opciones.rutas || {};
        const informe = opciones.informe;
        // Se ocultan los KPIs y la tabla, NO la barra de filtros: el cruce se calcula con esos
        // mismos filtros, así que esconderlos dejaba al usuario dentro de un ranking sin forma de
        // cambiar el rango de fechas. En Contagram la barra también queda visible en las tres
        // pestañas.
        const $soloDetalle = $('.js-solo-detalle');
        const $panelPivot = $('#panel-pivot');

        if (!$panelPivot.length) { return; }

        let dataset = null;      // se pide una sola vez por combinación de filtros
        let configVigente = null;
        let tituloVigente = '';
        let visiblesVigentes = null;
        let vistasGuardadas = [];

        const toast = (tipo, msg) => (window.toastr ? window.toastr[tipo](msg) : console.log(msg));

        /**
         * Preloader del cruce (pedido del cliente, 28/08/2026).
         *
         * Cubre las DOS etapas lentas, que el usuario percibe como una sola: el `getJSON` del
         * dataset y el dibujado de PivotTable.js, que con muchas filas bloquea el hilo varios
         * segundos. Por eso se apaga recién después de `montar()` y no al volver el AJAX.
         */
        const cargando = (activo) => $('#pivot-cargando').toggleClass('d-none', !activo);

        // "Dato"/"Accion" en Contagram real son un combo con buscador (fondo celeste, caja de
        // texto arriba de la lista) y no un <select> nativo pelado — regla #5 de CLAUDE.md. Se
        // inicializa una sola vez acá; `poblarSelectores()`/`refrescarAcciones()` sólo reescriben
        // las <option> y disparan `change.select2` para que Select2 las vuelva a leer.
        const hasSelect2 = !!($.fn && $.fn.select2);
        if (hasSelect2) {
            $('#pivot-dato, #pivot-accion').select2({ width: '100%', theme: 'default', minimumResultsForSearch: 0 });
        }

        /** Los filtros vigentes de la pantalla: el cruce usa EXACTAMENTE los mismos (FR-017). */
        function filtrosActuales() {
            return typeof opciones.filtros === 'function' ? opciones.filtros() : {};
        }

        function mostrarDetalle() {
            $soloDetalle.removeClass('d-none');
            $panelPivot.addClass('d-none');
            $('#pestanas-informe .nav-link').removeClass('active');
            $('#pestanas-informe .nav-link[data-panel="detalle"]').addClass('active');
        }

        function mostrarPivot() {
            $soloDetalle.addClass('d-none');
            $panelPivot.removeClass('d-none');
        }

        /** Trae el dataset una vez y lo cachea hasta que cambien los filtros. */
        function conDataset(alListo) {
            if (dataset) { return alListo(dataset); }

            cargando(true);

            $.getJSON(rutas.pivotDataset, filtrosActuales())
                .done(function (respuesta) {
                    dataset = respuesta;
                    alListo(dataset);
                })
                .fail(function (xhr) {
                    cargando(false);
                    // El 422 del tope de filas trae un mensaje pensado para el usuario: se muestra
                    // tal cual en vez de un "error al cargar" genérico.
                    toast('error', (xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo armar el cruce.');
                });
        }

        /**
         * Qué dimensiones quedan a mano en el pool.
         *
         * En un ranking, sólo la del ranking más el desglose de fecha — así lo muestra Contagram.
         * En "Arma tu Informe" se devuelve `null` y quedan las 13.
         */
        function visiblesDeRanking(dimension) {
            return dimension ? [dimension, 'fecha_emision', 'fecha_emision.anio', 'fecha_emision.mes'] : null;
        }

        function render(config, titulo, dimensionesVisibles) {
            conDataset(function (d) {
                configVigente = config;
                tituloVigente = titulo;
                visiblesVigentes = dimensionesVisibles;

                if (!d.filas.length) {
                    $('#pivot-vacio').removeClass('d-none');
                    $('#pivot-contenedor').empty();
                    cargando(false);

                    return;
                }

                $('#pivot-vacio').addClass('d-none');
                poblarSelectores(d, config);
                cargando(true);

                // `montar()` es SÍNCRONO y con muchas filas bloquea el hilo varios segundos: si se
                // lo llamara acá derecho, el navegador no llegaría a pintar el spinner que se
                // acaba de mostrar y el preloader no se vería nunca. El `setTimeout` le cede un
                // ciclo para pintar antes de arrancar el cálculo.
                setTimeout(function () {
                    try {
                        window.InformesPivot.montar({
                            $contenedor: $('#pivot-contenedor'),
                            filas: d.filas,
                            dimensiones: d.dimensiones,
                            datos: d.datos,
                            config: config,
                            dimensionesVisibles: dimensionesVisibles,
                            alCambiar: function (nueva) { configVigente = nueva; },
                        });
                    } finally {
                        // En `finally` y no al final del `try`: si el dibujado falla, el overlay
                        // tiene que irse igual — si no, la pantalla queda tapada para siempre.
                        cargando(false);
                    }
                }, 0);
            });
        }

        /** "Accion" se recalcula cada vez que cambia "Dato": sobre un conteo sólo vale Suma. */
        function poblarSelectores(d, config) {
            const $dato = $('#pivot-dato').empty();
            d.datos.forEach((m) => $dato.append(new Option(m.rotulo, m.clave, false, m.clave === config.dato)));
            if (hasSelect2) { $dato.trigger('change.select2'); }

            refrescarAcciones(d, config.accion);
        }

        function refrescarAcciones(d, accionElegida) {
            const medida = d.datos.find((m) => m.clave === $('#pivot-dato').val()) || d.datos[0];
            const todas = {
                suma: 'Suma',
                promedio: 'Promedio',
                minimo: 'Mínimo',
                maximo: 'Máximo',
                fraccion_total: 'Suma como Fracción del Total',
                fraccion_fila: 'Suma como Fracción por Línea',
                fraccion_columna: 'Suma como Fracción por Columna',
            };

            const disponibles = medida.es_conteo ? { suma: 'Suma' } : todas;
            const $accion = $('#pivot-accion').empty();

            Object.keys(disponibles).forEach(function (clave) {
                // Si la acción vigente dejó de aplicar, cae a Suma en vez de quedar en un valor
                // que el servidor rechazaría al guardar.
                $accion.append(new Option(disponibles[clave], clave, false, clave === accionElegida));
            });

            if (!disponibles[accionElegida]) { $accion.val('suma'); }
            if (hasSelect2) { $accion.trigger('change.select2'); }
        }

        // ---- Pestañas ----

        $(document).on('click', '.js-abrir-ranking', function (evento) {
            evento.preventDefault();

            const dimension = $(this).data('dimension');
            const rotulo = $(this).text().trim();

            $('#pestanas-informe .nav-link').removeClass('active');
            mostrarPivot();
            history.pushState({ pivot: dimension }, '', $(this).attr('href'));

            // Cada ranking abre con su dimensión en filas y año › mes en columnas (FR-019).
            render({
                filas: [dimension],
                columnas: ['fecha_emision.anio', 'fecha_emision.mes'],
                dato: opciones.datoPorDefecto,
                accion: 'suma',
                exclusiones: {},
            }, 'Ranking de ' + rotulo, visiblesDeRanking(dimension));
        });

        $(document).on('click', '.js-crear-informe', function (evento) {
            evento.preventDefault();

            $('#pestanas-informe .nav-link').removeClass('active');
            mostrarPivot();

            // Arranca vacío: el usuario arrastra las dimensiones que quiera (FR-011).
            render({ filas: [], columnas: [], dato: opciones.datoPorDefecto, accion: 'suma', exclusiones: {} },
                'Informe');
        });

        $(document).on('click', '#pestanas-informe .nav-link[data-panel="detalle"]', function (evento) {
            evento.preventDefault();
            mostrarDetalle();
            history.pushState({}, '', $(this).attr('href'));
        });

        // Atrás/adelante del navegador: sin esto, volver desde un ranking dejaba la URL vieja con
        // el panel del cruce todavía visible.
        $(window).on('popstate', function () { location.reload(); });

        $(document).on('change', '#pivot-dato', function () {
            conDataset(function (d) {
                refrescarAcciones(d, $('#pivot-accion').val());
                render(Object.assign({}, configVigente, {
                    dato: $('#pivot-dato').val(),
                    accion: $('#pivot-accion').val(),
                }), tituloVigente, visiblesVigentes);
            });
        });

        $(document).on('change', '#pivot-accion', function () {
            render(Object.assign({}, configVigente, { accion: $(this).val() }), tituloVigente, visiblesVigentes);
        });

        // ---- Guardar / exportar ----

        $(document).on('click', '#btn-pivot-guardar', function () {
            $('#pivot-descripcion').val('').removeClass('is-invalid');
            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-guardar-informe')).show();
        });

        $(document).on('click', '#btn-pivot-guardar-confirmar', function () {
            const descripcion = $('#pivot-descripcion').val();

            $.ajax({
                url: rutas.pivotVistas,
                method: 'POST',
                data: JSON.stringify({ descripcion: descripcion, config: configVigente }),
                contentType: 'application/json',
                // El token va explícito: al pasar `headers` propios se pisa el `ajaxSetup` global
                // del proyecto, y el POST volvía con "CSRF token mismatch".
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                },
            })
                .done(function (respuesta) {
                    window.bootstrap.Modal.getInstance(document.getElementById('modal-guardar-informe')).hide();
                    // El aviso de nombre repetido no bloquea el guardado, sólo informa.
                    if (respuesta.aviso) { toast('warning', respuesta.aviso); }
                    toast('success', 'Informe guardado.');
                    cargarVistasGuardadas();
                })
                .fail(function (xhr) {
                    const errores = (xhr.responseJSON && xhr.responseJSON.errors) || {};
                    $('#pivot-descripcion').addClass('is-invalid');
                    $('#pivot-descripcion-error').text(
                        (errores.descripcion && errores.descripcion[0]) ||
                        (xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo guardar.'
                    );
                });
        });

        $(document).on('click', '#btn-pivot-exportar', function () {
            const matriz = window.InformesPivot.matrizVisible($('#pivot-contenedor'), tituloVigente || 'Informe');

            // Sin dimensión de Filas (el caso normal de este cruce: sólo Categorías/Clientes/
            // Vendedores/Proveedores en Columnas) `matriz.filas` queda vacío a propósito — el
            // único dato es la fila de totales (`totales_columna`), así que también cuenta como
            // "hay algo para exportar".
            if (!matriz || (!matriz.filas.length && !matriz.totales_columna.length)) {
                return toast('warning', 'No hay nada que exportar todavía.');
            }

            // Se manda por POST y se descarga con un form, porque el cuerpo es la matriz entera y
            // no entra en una query string.
            const $form = $('<form method="POST" target="_blank">').attr('action', rutas.pivotExportar);
            $form.append($('<input type="hidden" name="_token">').val($('meta[name="csrf-token"]').attr('content')));
            $form.append($('<input type="hidden" name="payload">').val(JSON.stringify(matriz)));
            $('body').append($form);
            $form[0].submit();
            $form.remove();
        });

        /** Las vistas guardadas se agregan al desplegable y como pestaña suelta (FR-001). */
        function cargarVistasGuardadas() {
            $.getJSON(rutas.pivotVistas).done(function (respuesta) {
                const $menu = $('#menu-vistas-guardadas');
                $menu.find('.js-vista-guardada').parent().remove();

                vistasGuardadas = respuesta.data || [];

                vistasGuardadas.forEach(function (vista) {
                    // El nombre abre la vista; la papelera la borra. Van en la misma fila para no
                    // duplicar la lista en un submenú aparte.
                    $menu.append(
                        $('<li class="d-flex align-items-center">').append(
                            $('<a class="dropdown-item js-vista-guardada flex-grow-1" href="#">')
                                .attr('data-id', vista.id).text(vista.descripcion),
                            $('<button type="button" class="btn btn-sm btn-link text-danger js-borrar-vista" title="Eliminar">')
                                .attr('data-id', vista.id)
                                .attr('data-descripcion', vista.descripcion)
                                .html('<i class="fas fa-trash-alt"></i>')
                        )
                    );
                });
            });
        }

        $(document).on('click', '.js-vista-guardada', function (evento) {
            evento.preventDefault();

            const id = $(this).data('id');
            const vista = vistasGuardadas.find((v) => v.id === id);
            if (!vista) { return; }

            $('#pestanas-informe .nav-link').removeClass('active');
            mostrarPivot();
            history.pushState({ vista: id }, '', rutas.pivotVistaBase.replace('/pivot/vistas', '/vista/') + id);

            // Sin `dimensionesVisibles`: una vista armada a mano conserva las 13 dimensiones a
            // mano, porque el usuario la puede seguir reacomodando.
            render(vista.config, vista.descripcion, null);
        });

        // Si el usuario cambia el rango o los filtros estando en un ranking, el cruce tiene que
        // rehacerse: el dataset cacheado ya no corresponde a lo que pidió.
        //
        // Se engancha al `xhr.dt` de la tabla de detalle y NO a los eventos del daterangepicker.
        // Dos motivos: escuchar el picker con un handler delegado no llegaba a dispararse (probado
        // en el navegador: cero llamadas al dataset), y además el rango es sólo UNO de los filtros
        // — el panel tiene otros 19. La tabla se recarga ante cualquiera de ellos, así que su
        // `xhr` es la señal exacta de "los filtros cambiaron", sin tener que enumerarlos.
        if (opciones.tablaDetalle) {
            $(opciones.tablaDetalle).on('xhr.dt', invalidarYRedibujar);
        }

        function invalidarYRedibujar() {
            dataset = null;

            if (!$panelPivot.hasClass('d-none') && configVigente) {
                // El spinner se prende YA, no dentro del `setTimeout`: es justamente el caso que
                // el cliente señaló —cambiar un filtro y no ver nada— y esos 300ms de espera
                // sumados al fetch son el hueco más largo sin señal de toda la pantalla.
                cargando(true);
                setTimeout(() => render(configVigente, tituloVigente, visiblesVigentes), 300);
            }
        }

        $(document).on('click', '.js-borrar-vista', function (evento) {
            evento.preventDefault();
            evento.stopPropagation();   // si no, el clic abre la vista además de borrarla

            const id = $(this).data('id');
            const descripcion = $(this).data('descripcion');

            if (!window.confirm('¿Eliminar el informe "' + descripcion + '"?')) { return; }

            $.ajax({
                url: rutas.pivotVistaBase + '/' + id,
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            })
                .done(function () {
                    toast('success', 'Informe eliminado.');
                    cargarVistasGuardadas();

                    // Si la vista abierta era justo la que se borró, se vuelve al detalle para no
                    // dejar en pantalla un cruce que ya no existe.
                    if (location.pathname.endsWith('/vista/' + id)) {
                        mostrarDetalle();
                        history.pushState({}, '', rutas.pivotVistaBase.replace('/pivot/vistas', ''));
                    }
                })
                .fail(() => toast('error', 'No se pudo eliminar el informe.'));
        });

        cargarVistasGuardadas();

        // Entrada directa por URL: /informes/ventas/ranking/clientes abre ese ranking (research R6).
        const enRanking = location.pathname.match(/\/ranking\/([a-z_.]+)$/);
        if (enRanking) {
            $('.js-abrir-ranking[data-dimension="' + enRanking[1] + '"]').first().trigger('click');
        }
    }

    window.InformesPivotPantalla = { iniciar };
})();
