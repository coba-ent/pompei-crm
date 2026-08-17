/**
 * Motor de tablas dinámicas de Rankings y "Arma tu Informe" (spec 069).
 *
 * Envuelve PivotTable.js. El cruce se calcula **en el navegador** sobre el dataset que devuelve
 * el servidor: por eso hay un tope de filas del lado del backend, y por eso el export manda la
 * matriz ya calculada en vez de pedirle al servidor que la rehaga.
 *
 * ## "Mostrar Como" no existe acá
 *
 * El cliente decidió (15/08/2026) que la única presentación es la **tabla**: se descartaron mapa
 * de calor, gráficos e histograma. Por eso se registra **un solo renderer** y el selector no se
 * dibuja. No es sólo cosmético: aunque alguien inyecte `rendererName` desde afuera, el wrapper
 * fuerza `Table` — con un único renderer registrado no hay otro al que caer.
 *
 * Se expone en `window.InformesPivot` (no como import ESM) por el mismo motivo que
 * `rango-emision.js` y `fecha-ar.js`: los bundles de pantalla se cargan sueltos por Vite, y
 * jQuery, jQuery UI y PivotTable.js los provee el pagelevel de NexaDash.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$ || !$.pivotUtilities) { return; }

    const U = $.pivotUtilities;

    /** Más de esto y no se renderiza: el navegador se arrastra y la tabla es ilegible (FR-019b). */
    const TOPE_COLUMNAS = 1000;

    /**
     * Textos de la librería, en español.
     *
     * PivotTable.js viene en inglés: sin esto la tabla decía "Totals" y el embudo "Select All /
     * Apply". Contagram los tiene traducidos —verificado el 16/08/2026 sobre su propio filtro, que
     * muestra "Seleccionar todo / Deseleccionar todo / Aceptar"—, así que se usan sus mismos
     * literales y no una traducción propia.
     */
    const TEXTOS = {
        renderError: 'Hubo un error al dibujar el cruce.',
        computeError: 'Hubo un error al calcular el cruce.',
        uiRenderError: 'Hubo un error al dibujar la pantalla del cruce.',
        selectAll: 'Seleccionar todo',
        selectNone: 'Deseleccionar todo',
        tooMany: '(demasiados valores para listar)',
        filterResults: 'Filtrar valores',
        apply: 'Aceptar',
        cancel: 'Cancelar',
        totals: 'Totales',
        vs: 'vs',
        by: 'por',
    };

    /**
     * Registra el locale `es` clonando el inglés.
     *
     * Pasar `localeStrings` en las opciones de `pivotUI` NO alcanza: el renderer de tabla recibe
     * sus propias opciones y se quedaba con "Totals" en inglés. El mecanismo que la librería sí
     * propaga hasta el renderer es el locale registrado, que se elige con el 4º argumento.
     */
    function registrarLocaleEs() {
        if (U.locales.es) { return; }

        U.locales.es = $.extend(true, {}, U.locales.en);

        // Se REEMPLAZA el mapa de renderers, no se le agrega: el `extend` profundo dejaba también
        // los de la librería (Heatmap, Table Barchart, Row/Col Heatmap). Hoy no se ven porque
        // `pivotUI` recibe su propia lista, pero si alguien sacara esa opción reaparecerían — y
        // los modos de gráfico están descartados por decisión de negocio (FR-020/021).
        U.locales.es.renderers = { Tabla: U.renderers.Table };

        // Aparte y no dentro del `extend`: el merge profundo sobre los textos del inglés dejaba
        // los originales.
        U.locales.es.localeStrings = $.extend({}, U.locales.en.localeStrings, TEXTOS);
    }

    /**
     * Le pone las clases del template a los controles del embudo de exclusión.
     *
     * La librería los dibuja como `<button>` e `<input>` pelados, y quedaban fuera de estilo
     * respecto del resto del CRM. Contagram hace lo mismo: sus botones llevan `btn btn-primary`,
     * `btn btn-danger` y `btn btn-success`. Se aplica al abrirse el embudo porque la caja se crea
     * recién en ese momento.
     */
    function estilarEmbudo($caja) {
        $caja.find('input[type=text]').addClass('form-control form-control-sm mb-2');

        $caja.find('button').each(function () {
            const $b = $(this);
            if ($b.hasClass('btn')) { return; }

            const texto = $b.text().trim();
            const clase = texto === TEXTOS.selectAll ? 'btn-primary'
                : texto === TEXTOS.selectNone ? 'btn-danger'
                    : texto === TEXTOS.apply ? 'btn-success' : 'btn-light';

            $b.addClass('btn btn-sm ' + clase).css('margin', '2px');
        });
    }

    // El embudo se crea al hacer clic en el triangulito del chip, así que se estila ahí y no al
    // montar el pivot.
    $(document).on('click', '.pvtTriangle', function () {
        setTimeout(() => estilarEmbudo($('.pvtFilterBox:visible')), 60);
    });

    /**
     * Agregadores de la spec mapeados a los de PivotTable.js.
     *
     * Las tres fracciones se muestran como porcentaje y suman 100% sobre el conjunto visible
     * (FR-016), que es lo que hacen los `*Fraction` de la librería.
     */
    function agregadores(columnaMedida) {
        const t = U.aggregatorTemplates;
        const fmt = U.numberFormat({ thousandsSep: '.', decimalSep: ',' });
        const pct = U.numberFormat({ digitsAfterDecimal: 1, scaler: 100, suffix: '%', thousandsSep: '.', decimalSep: ',' });
        const campo = [columnaMedida];

        return {
            suma: t.sum(fmt)(campo),
            promedio: t.average(fmt)(campo),
            minimo: t.min(fmt)(campo),
            maximo: t.max(fmt)(campo),
            fraccion_total: t.fractionOf(t.sum(), 'total', pct)(campo),
            fraccion_fila: t.fractionOf(t.sum(), 'row', pct)(campo),
            fraccion_columna: t.fractionOf(t.sum(), 'col', pct)(campo),
        };
    }

    /**
     * "Cantidad de Ventas/Compras" cuenta comprobantes DISTINTOS, no filas.
     *
     * El dataset es una fila por ítem, así que `count()` contaría una venta de 3 líneas como 3.
     * `countUnique` sobre `comprobante_id` es la única forma de que el KPI del cruce coincida con
     * el de las tarjetas del informe.
     */
    function agregadorConteo(columna, esComprobante) {
        const t = U.aggregatorTemplates;
        const fmt = U.numberFormat({ digitsAfterDecimal: 0, thousandsSep: '.', decimalSep: ',' });

        return { suma: (esComprobante ? t.countUnique(fmt) : t.sum(fmt))([columna]) };
    }

    /**
     * Monta el pivot en un contenedor.
     *
     * @param {object} cfg
     * @param {jQuery} cfg.$contenedor  dónde dibujar
     * @param {Array}  cfg.filas        dataset (una fila por ítem)
     * @param {Array}  cfg.dimensiones  [{clave, rotulo, columna}]
     * @param {Array}  cfg.datos        [{clave, rotulo, columna, es_conteo}]
     * @param {object} cfg.config       {filas, columnas, dato, accion, exclusiones}
     * @param {string[]} [cfg.dimensionesVisibles] claves que quedan disponibles en el pool; si no se
     *        pasa, quedan todas (modo "Arma tu Informe")
     * @param {function} [cfg.alCambiar] se llama con la config vigente cada vez que el usuario toca algo
     */
    function montar(cfg) {
        const { $contenedor, filas, dimensiones, datos, config } = cfg;

        const porClave = {};
        dimensiones.forEach((d) => { porClave[d.clave] = d; });

        const medida = datos.find((d) => d.clave === config.dato) || datos[0];
        const agg = medida.es_conteo
            ? agregadorConteo(medida.columna, medida.clave.startsWith('cantidad_ventas') || medida.clave.startsWith('cantidad_compras'))
            : agregadores(medida.columna);

        const nombreAccion = agg[config.accion] ? config.accion : 'suma';

        // Las claves de dimensión se traducen a los rótulos visibles: PivotTable.js trabaja con
        // los nombres de columna del dataset, y lo que el usuario arrastra son los rótulos.
        const aRotulo = (clave) => (porClave[clave] ? porClave[clave].rotulo : clave);

        // El dataset viaja con nombres de columna; se renombra a rótulos para que la tabla y el
        // pool de dimensiones muestren "Clientes" y no "cliente".
        const datosParaPivot = filas.map((fila) => {
            const salida = {};
            dimensiones.forEach((d) => { salida[d.rotulo] = fila[d.columna]; });
            datos.forEach((m) => { salida[m.columna] = fila[m.columna]; });

            return salida;
        });

        // Se cuenta ANTES de dibujar. El aviso posterior no servía de nada: para cuando el
        // usuario lo leía, el navegador ya había armado las 5.000 columnas y estaba clavado.
        const columnasPrevistas = combinacionesDe(datosParaPivot, (config.columnas || []).map(aRotulo));

        if (columnasPrevistas > TOPE_COLUMNAS) {
            $contenedor.empty().append(
                $('<div class="alert alert-warning mb-0">').text(
                    'El cruce daría ' + columnasPrevistas.toLocaleString('es-AR') + ' columnas y no se puede ' +
                    'mostrar. Acotá el rango de fechas o mové una dimensión de columnas a filas — las filas ' +
                    'scrollean sin problema.'
                )
            );

            return;
        }

        registrarLocaleEs();

        $contenedor.pivotUI(datosParaPivot, {
            rows: (config.filas || []).map(aRotulo),
            cols: (config.columnas || []).map(aRotulo),

            // UN SOLO renderer, a propósito. Ver el comentario de cabecera.
            renderers: { Tabla: U.renderers.Table },
            rendererName: 'Tabla',

            aggregators: { [nombreAccion]: () => agg[nombreAccion] },
            aggregatorName: nombreAccion,

            // Las columnas que no son dimensión no tienen por qué aparecer en el pool. Y en un
            // RANKING se ocultan además las dimensiones ajenas: Contagram deja en el pool sólo
            // "fecha de emision" (relevado 16/08/2026 sobre su Ranking de Categorías), no las 13.
            // El pool completo es exclusivo de "Arma tu Informe".
            hiddenAttributes: datos.map((m) => m.columna).concat(
                cfg.dimensionesVisibles
                    ? dimensiones.filter((d) => cfg.dimensionesVisibles.indexOf(d.clave) === -1).map((d) => d.rotulo)
                    : []
            ),

            // Pool horizontal arriba y no vertical a la izquierda, como lo tiene Contagram
            // (su contenedor usa `pvtHorizList`).
            unusedAttrsVertical: false,


            exclusions: traducirExclusiones(config.exclusiones, porClave),

            // Con un solo renderer el selector "Mostrar Como" no aporta nada, y la spec pide que
            // directamente no se dibuje (FR-021). Se oculta en vez de no generarlo porque la
            // librería lo crea siempre como parte de su UI.
            onRefresh: function (estado) {
                $contenedor.find('.pvtRenderer').closest('td').hide();

                if (typeof cfg.alCambiar === 'function') {
                    cfg.alCambiar(leerConfig(estado, dimensiones, medida.clave, nombreAccion));
                }
            },
        }, true, 'es');
    }

    /**
     * Cuántas columnas distintas produciría cruzar por esas dimensiones.
     *
     * Se cuentan las COMBINACIONES realmente presentes en los datos y no el producto de los
     * cardinales: cruzar Año × Mes sobre un rango de dos años da 20 columnas reales, no 2 × 12 = 24.
     */
    function combinacionesDe(filas, rotulos) {
        if (!rotulos.length) { return 1; }

        const vistas = new Set();

        for (let i = 0; i < filas.length; i++) {
            // JSON y no un separador: dos valores como 'A|B' y 'A','B' colisionarían.
            vistas.add(JSON.stringify(rotulos.map((r) => filas[i][r])));

            // Con el tope ya superado no hace falta seguir recorriendo miles de filas.
            if (vistas.size > TOPE_COLUMNAS) { return vistas.size; }
        }

        return vistas.size;
    }

    /** Las exclusiones se guardan por clave de dimensión y la librería las quiere por rótulo. */
    function traducirExclusiones(exclusiones, porClave) {
        const salida = {};

        Object.keys(exclusiones || {}).forEach((clave) => {
            const d = porClave[clave];
            if (d) { salida[d.rotulo] = exclusiones[clave]; }
        });

        return salida;
    }

    /** Estado vigente del cruce, en la forma que persiste una vista guardada. */
    function leerConfig(estado, dimensiones, dato, accion) {
        const porRotulo = {};
        dimensiones.forEach((d) => { porRotulo[d.rotulo] = d.clave; });

        const aClave = (rotulo) => porRotulo[rotulo] || rotulo;
        const exclusiones = {};

        Object.keys(estado.exclusions || {}).forEach((rotulo) => {
            exclusiones[aClave(rotulo)] = estado.exclusions[rotulo];
        });

        return {
            filas: (estado.rows || []).map(aClave),
            columnas: (estado.cols || []).map(aClave),
            dato: dato,
            accion: accion,
            exclusiones: exclusiones,
        };
    }

    /**
     * Encabezados de columna del cruce, ya combinados por nivel.
     *
     * PivotTable.js arma el `thead` en varias filas —una por dimensión de columna— usando
     * `colspan` para el anidado. Tomar sólo la última fila daba vacío, porque esa contiene el
     * rótulo del eje de FILAS, no las columnas. Se recorren las filas que tienen `pvtColLabel`,
     * se expande cada rótulo tantas veces como diga su `colspan`, y se combinan por posición:
     * "2026" + "08 · Ago" queda "2026 › 08 · Ago".
     *
     * @return {string[]}
     */
    function encabezadosDeColumna($tabla) {
        const niveles = [];

        $tabla.find('thead tr').each(function () {
            const $celdas = $(this).find('th.pvtColLabel');
            if (!$celdas.length) { return; }

            const nivel = [];
            $celdas.each(function () {
                const texto = $(this).text().trim();
                const veces = parseInt($(this).attr('colspan'), 10) || 1;

                for (let i = 0; i < veces; i++) { nivel.push(texto); }
            });

            niveles.push(nivel);
        });

        if (!niveles.length) { return []; }

        return niveles[niveles.length - 1].map((_, i) =>
            niveles.map((n) => n[i]).filter(Boolean).join(' › '));
    }

    /** La matriz visible, tal cual, para que el Excel sea lo que el usuario está viendo. */
    function matrizVisible($contenedor, titulo) {
        const $tabla = $contenedor.find('table.pvtTable');
        if (!$tabla.length) { return null; }

        const filas = [];
        $tabla.find('tbody tr').each(function () {
            const $fila = $(this);
            const etiqueta = $fila.find('th.pvtRowLabel').map(function () { return $(this).text(); }).get();
            if (!etiqueta.length) { return; }

            filas.push({
                etiqueta: etiqueta,
                valores: $fila.find('td.pvtVal').map(function () { return $(this).text(); }).get(),
                total: $fila.find('td.pvtTotal').first().text(),
            });
        });

        return {
            titulo: titulo,
            // El rótulo del eje de filas vive en la ÚLTIMA fila del thead, separado de los de
            // columna que están más arriba.
            encabezados_fila: $tabla.find('thead tr').last().find('th.pvtAxisLabel')
                .map(function () { return $(this).text(); }).get(),
            encabezados_columna: encabezadosDeColumna($tabla),
            filas: filas,
            // La fila de totales no tiene clase propia en el `tr`: se la reconoce por su primera
            // celda (`pvtColTotalLabel`), y sus valores son los `pvtTotal.colTotal`.
            totales_columna: $tabla.find('td.pvtTotal.colTotal').map(function () { return $(this).text(); }).get(),
            total_general: $tabla.find('td.pvtGrandTotal').first().text(),
        };
    }

    window.InformesPivot = { montar, matrizVisible, TOPE_COLUMNAS };
})();
