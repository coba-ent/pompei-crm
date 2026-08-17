/**
 * Helper compartido de inputs de fecha en formato argentino (dd/mm/aaaa).
 *
 * POR QUÉ EXISTE
 * --------------
 * `<input type="date">` se dibuja con el locale del NAVEGADOR, no con el de la app: el mismo
 * campo mostraba `08/05/2026` para el 5 de agosto. En un proyecto cuyo origen de datos ya venía
 * con día y mes invertidos (ver los exports de Contagram), eso es indistinguible de un dato malo.
 * La primera corrección se hizo a mano en `tesoreria/saldos.blade.php`; esto generaliza ese patrón
 * para que no haya 29 copias distintas del mismo parche.
 *
 * EL CONTRATO
 * -----------
 * Hacia afuera el valor SIEMPRE es ISO (`YYYY-MM-DD`), igual que con `type="date"`. Lo único que
 * cambia es lo que ve el usuario. Por eso las pantallas migradas siguen mandando ISO al backend
 * sin tocar validaciones ni controladores.
 *
 * Se accede con `AppFecha.get($el)` / `AppFecha.set($el, iso)` en lugar de `.val()`, porque el
 * `.val()` crudo de un input de texto devuelve `05/08/2026` y mandar eso al backend es exactamente
 * el bug que este helper viene a evitar.
 *
 * POR QUÉ NO SE USA `new Date(...)` EN NINGÚN LADO
 * -----------------------------------------------
 * `new Date('05/08/2026')` lo interpreta el motor JS como mes/día (convención de EE.UU.) y
 * devuelve el 8 de mayo. Todo el parseo y formateo de acá es manipulación de strings pura: no hay
 * ningún punto donde el locale del entorno pueda invertir día y mes.
 *
 * Se expone en `window.AppFecha` (y no como import ESM) por el mismo motivo que
 * `rango-emision.js`: los bundles de las pantallas se cargan sueltos por Vite, y
 * `bootstrap-datepicker` lo provee el template NexaDash globalmente por pagelevel.
 */
(function () {
    'use strict';

    const RE_ISO = /^(\d{4})-(\d{2})-(\d{2})$/;
    const RE_AR = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/;

    /**
     * Valida que el trío sea una fecha REAL, no sólo tres números con la forma correcta.
     *
     * Sin esto `31/02/2026` pasaría el regex y se guardaría como `2026-02-31`, que MySQL acepta
     * en modo no estricto y deja un registro con una fecha que no existe.
     */
    function esFechaValida(anio, mes, dia) {
        if (mes < 1 || mes > 12 || dia < 1) { return false; }

        // Día 0 del mes siguiente = último día de este mes. Cubre bisiestos sin tabla aparte.
        const ultimoDia = new Date(anio, mes, 0).getDate();

        return dia <= ultimoDia;
    }

    /**
     * `'2026-08-05'` -> `'05/08/2026'`. Devuelve `''` si la entrada no es ISO válida.
     *
     * Acepta un datetime completo (`2026-08-05T00:00:00Z`) quedándose con los primeros 10
     * caracteres, porque varios endpoints devuelven la fecha serializada por Eloquent así.
     */
    function isoAvisible(iso) {
        if (!iso) { return ''; }

        const m = RE_ISO.exec(String(iso).slice(0, 10));
        if (!m) { return ''; }

        const [, anio, mes, dia] = m;
        if (!esFechaValida(+anio, +mes, +dia)) { return ''; }

        return dia + '/' + mes + '/' + anio;
    }

    /**
     * `'5/8/2026'` -> `'2026-08-05'`. Devuelve `null` si no es una fecha argentina válida.
     *
     * Devolver `null` y no una fecha "adivinada" es deliberado: ante una entrada dudosa preferimos
     * que el campo quede vacío y el backend rechace, antes que escribir una fecha equivocada.
     */
    function visibleAiso(texto) {
        if (!texto) { return null; }

        const m = RE_AR.exec(String(texto).trim());
        if (!m) { return null; }

        const [, dia, mes, anio] = m;
        if (!esFechaValida(+anio, +mes, +dia)) { return null; }

        return anio + '-' + mes.padStart(2, '0') + '-' + dia.padStart(2, '0');
    }

    /**
     * Lee el valor de un campo como ISO (`YYYY-MM-DD`), o `null` si está vacío/mal escrito.
     *
     * El texto visible manda sobre `data-fecha` porque el usuario puede tipear a mano sin abrir
     * el calendario. `data-fecha` sólo entra como respaldo cuando el campo quedó ilegible, y aun
     * así se revalida: nunca se devuelve un ISO que no haya pasado por `esFechaValida`.
     */
    function get($el) {
        const el = $el.jquery ? $el : window.jQuery($el);
        const iso = visibleAiso(el.val());
        if (iso) { return iso; }

        // Campo vacío: es un null legítimo (vencimiento opcional), no un error de tipeo.
        if (!String(el.val() || '').trim()) { return null; }

        const respaldo = el.data('fecha');

        return respaldo && isoAvisible(respaldo) ? String(respaldo).slice(0, 10) : null;
    }

    /**
     * Escribe una fecha ISO en el campo, dejando visible el dd/mm/aaaa.
     *
     * Un `iso` vacío o inválido limpia el campo en vez de dejar basura a la vista.
     */
    function set($el, iso) {
        const el = $el.jquery ? $el : window.jQuery($el);
        const visible = isoAvisible(iso);

        el.val(visible);
        if (visible) {
            el.data('fecha', String(iso).slice(0, 10));
        } else {
            el.removeData('fecha').removeAttr('data-fecha');
        }

        // El datepicker cachea la fecha internamente; sin esto el calendario se sigue abriendo
        // en la fecha anterior aunque el input ya muestre la nueva.
        if (window.jQuery.fn.datepicker && el.data('datepicker')) {
            el.datepicker('update', visible);
        }

        return el;
    }

    /**
     * Convierte un input en campo de fecha argentino.
     *
     * El input tiene que venir como `type="text"` con `data-fecha="{ISO}"` si trae valor inicial.
     * Es idempotente: reinicializar un campo ya inicializado no duplica handlers.
     */
    function init($el, opciones) {
        const $ = window.jQuery;
        const el = $el.jquery ? $el : $($el);

        el.each(function () {
            const campo = $(this);
            if (campo.data('fechaArInicializado')) { return; }
            campo.data('fechaArInicializado', true);

            campo.attr('placeholder', campo.attr('placeholder') || 'dd/mm/aaaa');
            campo.attr('autocomplete', 'off');
            campo.attr('inputmode', 'numeric');

            // Valor inicial: la vista manda ISO en `data-fecha`, acá se traduce a lo visible.
            const inicial = campo.data('fecha');
            if (inicial) { campo.val(isoAvisible(inicial)); }

            if ($.fn.datepicker) {
                const cfg = $.extend({
                    format: 'dd/mm/yyyy',
                    autoclose: true,
                    todayHighlight: true,
                    weekStart: 1,
                    // El locale lo agrega `public/vendor/bootstrap-datepicker-master/locales/`,
                    // que no venía en el template. Si faltara, la librería cae a `en` en silencio.
                    language: 'es',
                    // `auto` a secas elegía abrir hacia ARRIBA en los modales altos (el de Cuenta de
                    // Tesorería), y el calendario quedaba cortado 15px contra el borde de la ventana
                    // aunque hubiera lugar de sobra abajo. Preferir abajo y recurrir a arriba sólo
                    // si realmente no entra.
                    orientation: 'bottom auto',
                }, opciones || {});

                // Dentro de un modal el calendario se recorta contra el `overflow` del diálogo,
                // igual que le pasa a Select2 sin `dropdownParent`.
                const modal = campo.closest('.modal');
                if (modal.length && !cfg.container) { cfg.container = modal; }

                campo.datepicker(cfg);
            }

            // `changeDate` cubre el calendario; `change`/`blur`, el tipeo manual. Los tres
            // terminan en el mismo lugar para que `data-fecha` nunca quede desincronizado.
            campo.on('changeDate change blur', function () {
                const iso = visibleAiso(campo.val());

                if (iso) {
                    campo.data('fecha', iso);
                } else if (!String(campo.val() || '').trim()) {
                    campo.removeData('fecha').removeAttr('data-fecha');
                }

                campo.trigger('fecha:cambio', [iso]);
            });
        });

        return el;
    }

    /**
     * Inicializa todos los `[data-fecha-ar]` de un ámbito.
     *
     * El ámbito importa para los modales: su contenido puede renderizarse después de que corrió
     * el init de la página, así que se vuelve a llamar con el modal ya en el DOM.
     */
    function initAll(scope, opciones) {
        const $ = window.jQuery;

        return init($(scope || document).find('[data-fecha-ar]').addBack('[data-fecha-ar]'), opciones);
    }

    /**
     * Hoy en ISO según el reloj LOCAL.
     *
     * Reemplaza al `new Date().toISOString().slice(0, 10)` que había repetido en varios modales:
     * `toISOString()` convierte a UTC, y como Argentina es UTC-3, después de las 21:00 devolvía
     * el día siguiente. Un cobro cargado a las 22:00 quedaba con fecha de mañana.
     */
    function hoy() {
        const d = new Date();

        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    /**
     * `serializeArray()` de un formulario, con los campos de fecha traducidos a ISO.
     *
     * IMPRESCINDIBLE en los formularios que se envían enteros (Cliente, Proveedor). El
     * `serializeArray()` de jQuery lee el `value` crudo del input, que ahora es `05/08/2026`;
     * mandarlo así al backend es exactamente el bug que este helper evita en los formularios
     * que arman el payload campo por campo. Acá el arreglo tiene que ser en la serialización,
     * porque no hay un lugar donde se nombre cada fecha.
     *
     * Un campo mal escrito viaja vacío (no se adivina), y el backend lo rechaza.
     */
    function serializeArray($form) {
        const $ = window.jQuery;
        const form = $form.jquery ? $form : $($form);

        const esFecha = {};
        form.find('[data-fecha-ar]').each(function () {
            if (this.name) { esFecha[this.name] = true; }
        });

        return form.serializeArray().map(function (item) {
            if (!esFecha[item.name]) { return item; }

            const iso = visibleAiso(item.value);

            return { name: item.name, value: iso === null ? '' : iso };
        });
    }

    /**
     * Hace que uno o más campos de fecha copien a otro **mientras nadie los toque a mano**.
     *
     * Existe para "Servicio Desde/Hasta" de Venta y Compra: en la práctica el comprobante es del
     * día, así que arrancan en la fecha de emisión y la siguen si el vendedor la corrige. Apenas
     * escribe uno de los dos, ese campo y su compañero dejan de seguirla — a partir de ahí manda
     * lo que puso él, incluso si lo deja vacío.
     *
     * Sólo tiene sentido en un ALTA. En edición el comprobante ya tiene sus fechas, y una fecha
     * vacía también es un dato: pisarla sería cambiar algo que nadie pidió cambiar.
     *
     * El flag `propio` distingue nuestra escritura de la del usuario. Hace falta porque `set()`
     * llama a `datepicker('update')`, que dispara `changeDate` y termina en el mismo
     * `fecha:cambio` que el tipeo manual — sin el flag, el primer autocompletado se tomaría a sí
     * mismo por una edición y se desactivaría solo.
     */
    function seguir($origen, destinos) {
        const $ = window.jQuery;
        const origen = $origen.jquery ? $origen : $($origen);
        const campos = destinos.map((d) => (d.jquery ? d : $(d)));

        let propio = false;
        let manual = false;

        function copiar() {
            if (manual) { return; }

            const iso = get(origen);
            if (!iso) { return; }

            propio = true;
            campos.forEach((campo) => set(campo, iso));
            propio = false;
        }

        campos.forEach((campo) => campo.on('fecha:cambio', function () {
            if (!propio) { manual = true; }
        }));
        origen.on('fecha:cambio', copiar);

        copiar();
    }

    window.AppFecha = { init, initAll, get, set, hoy, seguir, serializeArray, isoAvisible, visibleAiso };

    // Auto-inicialización: cada pantalla sólo tiene que marcar sus inputs con `data-fecha-ar`.
    //
    // `shown.bs.modal` no es redundante con el ready: hay modales cuyo contenido se inyecta por
    // AJAX después de que cargó la página, y sus campos todavía no existían en el primer barrido.
    // `init()` es idempotente, así que volver a pasar por campos ya inicializados no duplica nada.
    window.jQuery(function () {
        initAll(document);

        window.jQuery(document).on('shown.bs.modal', '.modal', function () {
            initAll(this);
        });
    });
})();
