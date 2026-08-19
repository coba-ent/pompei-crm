/**
 * Buscador de catálogo con foco persistente: input de texto + panel de sugerencias propio.
 *
 * POR QUÉ EXISTE
 * ---------------
 * El campo `#f-producto` de Venta/Compra/Presupuesto usaba Select2 (`initSelect2` + evento
 * `select2:select`). El pedido del cliente es cargar varios productos seguidos sin tocar el
 * mouse: tipear, elegir, tipear el siguiente. Con Select2 eso es imposible por arquitectura del
 * componente: el campo de búsqueda (`.select2-search__field`) sólo existe en el DOM mientras el
 * desplegable está ABIERTO; al cerrarse (por ejemplo, al elegir una opción), Select2 lo destruye
 * y devuelve el foco al `<span>` contenedor, no al input. La solución vigente (`reabrirBuscador()`
 * en ventas.js/compras.js/presupuestos.js) tenía que reabrir el desplegable ENTERO sólo para
 * recuperar el foco — exactamente el efecto lateral que el cliente pide eliminar.
 *
 * Por eso este widget usa un `<input type="text">` SIEMPRE visible (nunca se destruye) y un panel
 * de sugerencias aparte, posicionado debajo, que se abre/cierra sin tocar el foco del input en
 * ningún momento. Ver `specs/071-buscador-productos-detalle/research.md` Decisión 1.
 *
 * QUÉ NO SABE ESTE MÓDULO
 * ------------------------
 * Nada de productos, ventas, compras ni IVA. Es genérico: cada pantalla le pasa `buscar` (cómo
 * consultar), `formatear` (cómo mostrar cada fila) y `onElegir` (qué hacer al elegir). Ver
 * `specs/071-buscador-productos-detalle/contracts/buscador-catalogo-api.md`.
 *
 * Se expone en `window.BuscadorCatalogo` (no ESM) por el mismo motivo que `fecha-ar.js`: los
 * bundles de cada pantalla los carga Vite sueltos por pagelevel.
 */
(function () {
    'use strict';

    /**
     * ---------------------------------------------------------------------
     * Lógica pura, sin DOM — exportada aparte para poder testearla con el
     * runner de Node (`tests/js/buscador-catalogo.test.mjs`) sin necesitar
     * jsdom ni ningún entorno de navegador.
     * ---------------------------------------------------------------------
     */

    /**
     * Crea un debouncer: cada llamada a `disparar(fn)` reinicia el timer y sólo la última
     * pulsación dentro de la ventana de `esperaMs` termina ejecutando `fn`.
     *
     * Se implementa con `setTimeout`/`clearTimeout` reales (no un mock), así que sirve tanto para
     * el widget en el navegador como para el test, que usa los timers falsos de Node.
     */
    function crearDebouncer(esperaMs) {
        let timer = null;

        function disparar(fn) {
            if (timer !== null) { clearTimeout(timer); }
            timer = setTimeout(function () {
                timer = null;
                fn();
            }, esperaMs);
        }

        function cancelar() {
            if (timer !== null) { clearTimeout(timer); timer = null; }
        }

        return { disparar, cancelar };
    }

    /**
     * Mueve el índice resaltado con tope en los extremos (nunca da la vuelta).
     *
     * `total === 0` siempre devuelve -1: no hay nada para resaltar. `direccion` es +1 (↓) o -1 (↑).
     * Partir de -1 y bajar una vez lleva al índice 0 (primer elemento) — así es como el usuario
     * "entra" a la lista.
     */
    function moverResaltado(actual, direccion, total) {
        if (total <= 0) { return -1; }

        const siguiente = actual + direccion;

        if (siguiente < 0) { return 0; }
        if (siguiente >= total) { return total - 1; }

        return siguiente;
    }

    /**
     * Crea el controlador de secuencia para descartar respuestas fuera de orden (FR-012).
     *
     * `siguiente()` se llama al DISPARAR una consulta y devuelve el número que la identifica;
     * `esVigente(n)` se usa cuando la respuesta llega, para saber si sigue siendo la última
     * disparada. Tipear rápido puede hacer que una respuesta vieja llegue después que una nueva
     * (la red no garantiza orden de llegada); sin este chequeo el panel podría quedar mostrando
     * resultados de un término que el usuario ya borró.
     */
    function crearSecuenciador() {
        let actual = 0;

        return {
            siguiente: function () { actual += 1; return actual; },
            esVigente: function (n) { return n === actual; },
        };
    }

    /**
     * ---------------------------------------------------------------------
     * Widget con DOM
     * ---------------------------------------------------------------------
     */

    // Guarda la instancia montada en cada elemento para que `montar()` sea idempotente.
    const CLAVE_INSTANCIA = 'buscadorCatalogoInstancia';
    let contadorId = 0;

    function crearPanel($input) {
        const $ = window.jQuery;
        const id = 'buscador-catalogo-panel-' + (contadorId += 1);

        const $panel = $('<div class="buscador-catalogo-panel" role="listbox"></div>')
            .attr('id', id)
            // El panel nunca debe ser alcanzable por Tab (contrato §1: "el panel no contiene
            // ningún elemento focusable por tabulación"); tabindex="-1" en el propio panel refuerza
            // que ni el contenedor entra en el orden de tabulación.
            .attr('tabindex', '-1')
            .hide();

        // Se agrega como hermano del input, dentro de un wrapper posicionado relativo, para que el
        // `position:absolute` del panel quede anclado al input y no al card/modal completo (T008).
        $input.wrap('<div class="buscador-catalogo-wrapper"></div>');
        $input.after($panel);

        return $panel;
    }

    function montar(elemento, opciones) {
        const $ = window.jQuery;
        const $input = elemento && elemento.jquery ? elemento : $(elemento);

        if (!$input.length) {
            throw new Error('BuscadorCatalogo.montar: el elemento no existe en el DOM.');
        }

        // Idempotencia: montar dos veces sobre el mismo input devuelve la instancia existente sin
        // duplicar listeners ni paneles (contrato §Montaje).
        const existente = $input.data(CLAVE_INSTANCIA);
        if (existente) { return existente; }

        const opts = Object.assign({
            placeholder: 'Buscar...',
            debounceMs: 250,
            minimoCaracteres: 0,
            textoSinResultados: 'Sin coincidencias',
            textoBuscando: 'Buscando...',
            textoError: 'No se pudo buscar. Reintentá.',
        }, opciones || {});

        if (typeof opts.buscar !== 'function') { throw new Error('BuscadorCatalogo.montar: falta la opción "buscar".'); }
        if (typeof opts.formatear !== 'function') { throw new Error('BuscadorCatalogo.montar: falta la opción "formatear".'); }
        if (typeof opts.onElegir !== 'function') { throw new Error('BuscadorCatalogo.montar: falta la opción "onElegir".'); }

        if (opts.placeholder) { $input.attr('placeholder', opts.placeholder); }

        const $panel = crearPanel($input);
        const debouncer = crearDebouncer(opts.debounceMs);
        const secuenciador = crearSecuenciador();

        const estado = {
            termino: '',
            abierto: false,
            cargando: false,
            error: false,
            items: [],       // [{ id, texto, datos }]
            resaltado: -1,
        };

        // Accesibilidad (FR-016, contrato §8).
        $input.attr({
            role: 'combobox',
            'aria-expanded': 'false',
            'aria-controls': $panel.attr('id'),
            autocomplete: 'off',
        });

        function idFilaDom(indice) {
            return $panel.attr('id') + '-opt-' + indice;
        }

        function actualizarAriaActivo() {
            if (estado.resaltado >= 0) {
                $input.attr('aria-activedescendant', idFilaDom(estado.resaltado));
            } else {
                $input.removeAttr('aria-activedescendant');
            }
        }

        function cerrarPanel() {
            estado.abierto = false;
            estado.resaltado = -1;
            $panel.hide();
            $input.attr('aria-expanded', 'false');
            actualizarAriaActivo();
        }

        function abrirPanel() {
            estado.abierto = true;
            $panel.show();
            $input.attr('aria-expanded', 'true');
        }

        /**
         * Redibuja el panel según el estado actual: 4 estados posibles (FR-009/010/011).
         *
         * El texto de cada fila SIEMPRE se inserta con `.text()` (jQuery ⇒ `textContent`), nunca
         * como HTML: `formatear()` devuelve datos cargados por el usuario (nombre de producto) que
         * podrían traer `<`, `>` o comillas — insertarlos como HTML sería una inyección (contrato
         * §Seguridad).
         */
        function render() {
            $panel.empty();

            if (estado.cargando) {
                $('<div class="buscador-catalogo-estado buscador-catalogo-buscando"></div>')
                    .text(opts.textoBuscando)
                    .appendTo($panel);
                return;
            }

            if (estado.error) {
                $('<div class="buscador-catalogo-estado buscador-catalogo-error"></div>')
                    .text(opts.textoError)
                    .appendTo($panel);
                return;
            }

            if (!estado.items.length) {
                $('<div class="buscador-catalogo-estado buscador-catalogo-sin-resultados"></div>')
                    .text(opts.textoSinResultados)
                    .appendTo($panel);
                return;
            }

            estado.items.forEach(function (item, indice) {
                const $fila = $('<div class="buscador-catalogo-opcion"></div>')
                    .attr({
                        id: idFilaDom(indice),
                        role: 'option',
                        'aria-selected': indice === estado.resaltado ? 'true' : 'false',
                    })
                    .toggleClass('buscador-catalogo-opcion--resaltada', indice === estado.resaltado)
                    .text(item.texto)
                    .on('mousedown', function (e) {
                        // mousedown (no click): dispara ANTES que el blur del input, así el clic en
                        // una fila no cierra el panel por blur antes de poder elegirla.
                        e.preventDefault();
                        elegir(item);
                    })
                    // Resaltar también al pasar el mouse, para que teclado y mouse queden
                    // consistentes (mismo criterio visual sin importar cómo se llegó a la fila).
                    .on('mouseenter', function () {
                        estado.resaltado = indice;
                        actualizarAriaActivo();
                        $panel.find('.buscador-catalogo-opcion').removeClass('buscador-catalogo-opcion--resaltada').attr('aria-selected', 'false');
                        $fila.addClass('buscador-catalogo-opcion--resaltada').attr('aria-selected', 'true');
                    });

                $panel.append($fila);
            });
        }

        function scrollAResaltado() {
            if (estado.resaltado < 0) { return; }
            const $fila = $panel.find('#' + idFilaDom(estado.resaltado));
            if (!$fila.length) { return; }

            const panelEl = $panel.get(0);
            const filaEl = $fila.get(0);
            if (filaEl.offsetTop < panelEl.scrollTop) {
                panelEl.scrollTop = filaEl.offsetTop;
            } else if (filaEl.offsetTop + filaEl.offsetHeight > panelEl.scrollTop + panelEl.clientHeight) {
                panelEl.scrollTop = filaEl.offsetTop + filaEl.offsetHeight - panelEl.clientHeight;
            }
        }

        /**
         * Ciclo de elección (FR-003, contrato §2): onElegir → cerrar panel → vaciar input →
         * conservar foco. El orden importa: `onElegir` puede necesitar leer el input todavía, y el
         * foco tiene que sobrevivir a todo el ciclo sin pasar por el panel (que no es focusable).
         */
        function elegir(item) {
            opts.onElegir(item.datos);
            cerrarPanel();
            estado.termino = '';
            $input.val('');
            $input.trigger('focus');
        }

        function ejecutarBusqueda(termino) {
            estado.cargando = true;
            estado.error = false;
            abrirPanel();
            render();

            const miSecuencia = secuenciador.siguiente();

            Promise.resolve(opts.buscar(termino))
                .then(function (resultados) {
                    if (!secuenciador.esVigente(miSecuencia)) { return; } // FR-012

                    const crudos = Array.isArray(resultados) ? resultados : [];
                    estado.items = crudos.map(function (dato) {
                        return { id: dato.id, texto: opts.formatear(dato), datos: dato };
                    });
                    estado.cargando = false;
                    estado.error = false;
                    estado.resaltado = -1; // research.md Decisión 5: nunca auto-resaltar
                    render();
                    actualizarAriaActivo();
                })
                .catch(function () {
                    if (!secuenciador.esVigente(miSecuencia)) { return; }

                    estado.cargando = false;
                    estado.error = true;
                    estado.items = [];
                    estado.resaltado = -1;
                    render();
                    actualizarAriaActivo();
                });
        }

        function alTipear() {
            estado.termino = $input.val() || '';

            if (estado.termino.length < opts.minimoCaracteres) {
                debouncer.cancelar();
                cerrarPanel();
                render();
                return;
            }

            debouncer.disparar(function () { ejecutarBusqueda(estado.termino); });
        }

        function alTeclaAbajo(e) {
            if (!estado.abierto) { return; }
            e.preventDefault();
            estado.resaltado = moverResaltado(estado.resaltado, 1, estado.items.length);
            render();
            actualizarAriaActivo();
            scrollAResaltado();
        }

        function alTeclaArriba(e) {
            if (!estado.abierto) { return; }
            e.preventDefault();
            estado.resaltado = moverResaltado(estado.resaltado, -1, estado.items.length);
            render();
            actualizarAriaActivo();
            scrollAResaltado();
        }

        function alEnter(e) {
            if (!estado.abierto) { return; }
            e.preventDefault();
            // Enter sin nada resaltado no hace nada (research.md Decisión 5): el costo de una línea
            // equivocada en un comprobante fiscal es alto como para cargar "lo primero que apareció".
            if (estado.resaltado < 0 || estado.resaltado >= estado.items.length) { return; }
            elegir(estado.items[estado.resaltado]);
        }

        function alEscape(e) {
            if (!estado.abierto) { return; }
            e.preventDefault();
            cerrarPanel(); // conserva término y foco (FR-007)
        }

        function alKeydown(e) {
            switch (e.key) {
                case 'ArrowDown': alTeclaAbajo(e); break;
                case 'ArrowUp': alTeclaArriba(e); break;
                case 'Enter': alEnter(e); break;
                case 'Escape': alEscape(e); break;
                default: break;
            }
        }

        function alFocus() {
            // Reapertura por tipeo (FR-004): si ya hay término y el panel estaba cerrado, no hace
            // falta esperar otra pulsación para volver a verlo.
            if (estado.termino && !estado.abierto && estado.items.length) {
                abrirPanel();
                render();
            }
        }

        // Cierre pasivo por blur (FR-008): sin elegir nada y sin borrar el término. Se demora un
        // tick porque el `mousedown` de una fila (que ya hace `preventDefault`) puede convivir con
        // un blur del navegador en algunos casos límite; el `preventDefault` de la fila alcanza en
        // los navegadores modernos, pero el timeout es una red de seguridad barata.
        function alBlur() {
            setTimeout(function () {
                if (!$input.is(':focus')) { cerrarPanel(); render(); }
            }, 0);
        }

        // Clic fuera del widget (input + panel): cierra sin elegir, sin borrar el término.
        function alClicDocumento(e) {
            if ($input.is(e.target) || $panel.is(e.target) || $panel.has(e.target).length) { return; }
            cerrarPanel();
            render();
        }

        $input.on('input.buscadorCatalogo', alTipear);
        $input.on('keydown.buscadorCatalogo', alKeydown);
        $input.on('focus.buscadorCatalogo', alFocus);
        $input.on('blur.buscadorCatalogo', alBlur);
        $(document).on('mousedown.buscadorCatalogo' + contadorId, alClicDocumento);

        const instancia = {
            enfocar: function () { $input.trigger('focus'); },
            limpiar: function () {
                estado.termino = '';
                $input.val('');
                cerrarPanel();
                render();
            },
            cerrar: function () { cerrarPanel(); render(); },
            destruir: function () {
                $input.off('.buscadorCatalogo');
                $(document).off('mousedown.buscadorCatalogo' + contadorId);
                $panel.remove();
                $input.unwrap();
                $input.removeData(CLAVE_INSTANCIA);
            },
        };

        $input.data(CLAVE_INSTANCIA, instancia);
        render();

        return instancia;
    }

    window.BuscadorCatalogo = {
        montar,
        // Se exponen para el test de lógica pura (tests/js/buscador-catalogo.test.mjs), que corre
        // sin DOM y necesita llamar estas funciones directamente.
        _internas: { crearDebouncer, moverResaltado, crearSecuenciador },
    };
})();
