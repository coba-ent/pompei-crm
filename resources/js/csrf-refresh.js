/**
 * Reintento automático ante "CSRF token mismatch" (419).
 *
 * ## Por qué existe
 *
 * Cada bundle de pantalla lee el token CSRF UNA sola vez al cargar la página
 * (`$('meta[name="csrf-token"]').attr('content')`) y lo fija con `$.ajaxSetup`. Laravel rota el
 * token de sesión periódicamente sin cerrar la sesión de auth — con una pantalla abierta mucho
 * tiempo (ej. el modal de Pago de una Compra con muchos campos), el token en memoria queda viejo
 * y el submit falla con 419 aunque el usuario siga logueado. Antes esto no tenía ningún manejo:
 * el usuario veía el error crudo (toast "No se pudo actualizar...") y perdía lo que había
 * cargado en el formulario.
 *
 * ## Cómo lo resuelve
 *
 * Se envuelve `$.ajax` (no alcanza con el evento global `ajaxError`: ese dispara DESPUÉS de que
 * el `.fail()` de quien llamó ya corrió y mostró su propio toast de error — para el usuario el
 * daño ya estaría hecho aunque el reintento después funcionara). El wrapper detecta un 419,
 * refresca el token contra `csrf-token` (GET no requiere CSRF, sirve para refrescarlo sin más
 * efecto que leer la sesión) y reintenta la request original UNA sola vez con el token nuevo,
 * **antes** de que el `.fail()`/`.done()` de quien llamó vea nada — así es transparente para
 * todas las pantallas sin tocar una por una. Si el refresco también falla (sesión realmente
 * vencida, no sólo el token rotado), se deja pasar el 419 original tal cual como hasta ahora.
 *
 * Va en el layout (carga siempre, antes de los bundles de pantalla) para cubrir todos los módulos
 * sin duplicar este manejo en cada `*.js` — mismo criterio que `errores-validacion.js`.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        return;
    }

    const ajaxOriginal = $.ajax;
    let refrescoEnCurso = null;

    function actualizarTokenEnPagina(token) {
        $('meta[name="csrf-token"]').attr('content', token);
        $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': token } });
    }

    /**
     * Un único refresco en vuelo aunque varias requests choquen con 419 al mismo tiempo. Usa
     * `ajaxOriginal` directamente (no `$.getJSON`, que pasaría por el wrapper de abajo) — GET no
     * lleva token CSRF, así que este pedido no puede disparar el reintento a sí mismo, pero
     * evita la indirección de todos modos.
     */
    function refrescarToken() {
        if (!refrescoEnCurso) {
            refrescoEnCurso = ajaxOriginal.call($, '/csrf-token', { method: 'GET', dataType: 'json' })
                .then((resp) => {
                    actualizarTokenEnPagina(resp.token);
                    return resp.token;
                })
                .always(() => { refrescoEnCurso = null; });
        }
        return refrescoEnCurso;
    }

    $.ajax = function (url, opciones) {
        // $.ajax(url, opciones) y $.ajax(opciones) son las dos firmas válidas de jQuery.
        if (typeof url === 'object') {
            opciones = url;
            url = undefined;
        }
        opciones = opciones || {};

        if (opciones.__csrfReintentado) {
            return ajaxOriginal.call($, url, opciones);
        }

        const deferred = $.Deferred();
        const requestOriginal = url === undefined ? ajaxOriginal.call($, opciones) : ajaxOriginal.call($, url, opciones);

        requestOriginal.done(function () {
            deferred.resolveWith(this, arguments);
        });

        requestOriginal.fail(function (jqXHR) {
            const contexto = this;
            const argumentosOriginales = arguments;

            if (jqXHR.status !== 419) {
                deferred.rejectWith(contexto, argumentosOriginales);
                return;
            }

            refrescarToken().then(
                function (token) {
                    const opcionesReintento = $.extend({}, opciones, {
                        headers: $.extend({}, opciones.headers, { 'X-CSRF-TOKEN': token }),
                        __csrfReintentado: true,
                    });
                    const requestReintento = url === undefined
                        ? ajaxOriginal.call($, opcionesReintento)
                        : ajaxOriginal.call($, url, opcionesReintento);

                    requestReintento.done(function () { deferred.resolveWith(this, arguments); });
                    requestReintento.fail(function () { deferred.rejectWith(this, arguments); });
                },
                // El refresco de token también falló (sesión realmente vencida): se deja pasar
                // el 419 original, mismo comportamiento que sin este archivo.
                function () { deferred.rejectWith(contexto, argumentosOriginales); }
            );
        });

        return deferred.promise(requestOriginal);
    };
})();
