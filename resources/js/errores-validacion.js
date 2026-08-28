/**
 * Helper global para mostrar los errores de validación que devuelve el backend (422).
 *
 * ## Por qué existe
 *
 * Los FormRequest responden `{ ok:false, message:'…', errors:{ campo:['detalle'] } }`, pero
 * ventas.js, compras.js y presupuestos.js mostraban **sólo** `message` ("No se salvó la Venta,
 * revise el formulario.") y descartaban `errors`, que es justamente el detalle que dice qué campo
 * está mal. El usuario veía que fallaba sin saber por qué. NC/ND sí mostraba el detalle, pero con
 * su propia línea suelta — de ahí que esto viva en un solo lugar.
 *
 * ## Qué muestra
 *
 * - Un solo campo con problema → el mensaje tal cual ("El campo Cliente es obligatorio.").
 * - Varios → los lista, para no obligar a corregir de a uno y reenviar.
 * - Un error de un renglón del detalle → antepone el número de renglón, porque `items.2.cantidad`
 *   no le dice nada a nadie: "Renglón 3: El campo Cantidad es obligatorio."
 * - Sin `errors` (500, 403, red caída) → cae al `message` del backend y, si tampoco hay, al texto
 *   por defecto que le pase quien llama.
 *
 * Uso: `.fail((xhr) => window.AppErrores.toast(xhr, 'No se pudo guardar.'))`
 */
(function () {
    'use strict';

    /** `items.2.cantidad` → "Renglón 3: …" (base 1, como los ve el usuario en la tabla). */
    function conRenglon(campo, mensaje) {
        const m = /^items\.(\d+)\./.exec(campo);
        if (!m) { return mensaje; }
        return 'Renglón ' + (Number(m[1]) + 1) + ': ' + mensaje;
    }

    /**
     * Lista de mensajes legibles a partir del cuerpo de un 422.
     * @returns {string[]} vacío si la respuesta no trae `errors`.
     */
    function mensajes(respuesta) {
        const errores = respuesta && respuesta.errors;
        if (!errores || typeof errores !== 'object') { return []; }

        const salida = [];
        Object.keys(errores).forEach(function (campo) {
            const lista = Array.isArray(errores[campo]) ? errores[campo] : [errores[campo]];
            lista.forEach(function (msg) {
                if (msg) { salida.push(conRenglon(campo, String(msg))); }
            });
        });
        return salida;
    }

    /**
     * Texto final a mostrar: el detalle si lo hay, si no el `message`/`mensaje` del backend, y como
     * último recurso el genérico de quien llama.
     */
    function texto(xhr, porDefecto) {
        const r = (xhr && xhr.responseJSON) || {};
        const lista = mensajes(r);

        if (lista.length === 1) { return lista[0]; }
        if (lista.length > 1) { return lista.join('\n'); }

        return r.message || r.mensaje || porDefecto || 'No se pudo completar la operación.';
    }

    /** Muestra el error con Toastr (o `console` si no está disponible). */
    function toast(xhr, porDefecto) {
        const mensaje = texto(xhr, porDefecto);
        const lista = mensajes((xhr && xhr.responseJSON) || {});

        if (window.toastr && window.toastr.error) {
            // Varios errores se muestran como lista y con más tiempo en pantalla: leer cinco
            // renglones en los 4 segundos del default no alcanza.
            if (lista.length > 1) {
                window.toastr.error(
                    '<ul class="mb-0 ps-3">' + lista.map((m) => '<li>' + escapar(m) + '</li>').join('') + '</ul>',
                    'Revisá estos campos',
                    { enableHtml: true, timeOut: 9000, extendedTimeOut: 3000 }
                );
            } else {
                window.toastr.error(mensaje);
            }
        } else {
            console.error('[validacion]', mensaje);
        }

        return mensaje;
    }

    /** El mensaje viene del backend, así que se escapa antes de inyectarlo como HTML en el toast. */
    function escapar(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    window.AppErrores = { toast: toast, texto: texto, mensajes: mensajes };
})();
