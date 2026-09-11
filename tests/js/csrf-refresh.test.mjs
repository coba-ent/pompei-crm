/**
 * Test del reintento automático de `resources/js/csrf-refresh.js` ante un 419
 * "CSRF token mismatch" — sin depender de un navegador real ni de la base de datos.
 *
 * Corre con `node --test tests/js/csrf-refresh.test.mjs`.
 *
 * Se simula un `$` mínimo (suficiente `$.ajax`/`$.Deferred`/`$.extend`/`$.fn`) que responde 419
 * la primera vez y 200 la segunda — el mismo patrón que produce el bug real en producción
 * (token viejo en memoria, token nuevo después de refrescar).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const fuente = readFileSync(resolve(raiz, 'resources/js/csrf-refresh.js'), 'utf8');

/** Deferred mínimo, sólo lo que usa csrf-refresh.js (done/fail/then/always/promise). */
function crearDeferred() {
    const callbacksDone = [];
    const callbacksFail = [];
    let estado = 'pending';
    let contextoFinal;
    let argumentosFinal;

    const deferred = {
        resolveWith(contexto, args) {
            estado = 'resolved';
            contextoFinal = contexto;
            argumentosFinal = args;
            callbacksDone.forEach((cb) => cb.apply(contexto, args));
        },
        rejectWith(contexto, args) {
            estado = 'rejected';
            contextoFinal = contexto;
            argumentosFinal = args;
            callbacksFail.forEach((cb) => cb.apply(contexto, args));
        },
        promise(target) {
            const p = target || {};
            p.done = (cb) => { if (estado === 'resolved') { cb.apply(contextoFinal, argumentosFinal); } else { callbacksDone.push(cb); } return p; };
            p.fail = (cb) => { if (estado === 'rejected') { cb.apply(contextoFinal, argumentosFinal); } else { callbacksFail.push(cb); } return p; };
            p.always = (cb) => { p.done(cb); p.fail(cb); return p; };
            p.then = (onDone, onFail) => { if (onDone) { p.done(onDone); } if (onFail) { p.fail(onFail); } return p; };
            return p;
        },
    };
    return deferred;
}

function crearJQueryFalso(respuestasPorUrl) {
    const metaToken = { valor: 'token-viejo' };
    const headersDefault = {};

    function fakeAjax(url, opciones) {
        if (typeof url === 'object') {
            opciones = url;
            url = opciones.url;
        }
        const deferred = crearDeferred();
        const promise = deferred.promise({});

        const cola = respuestasPorUrl[url] || [];
        const respuesta = cola.shift() || { status: 200, body: {} };

        // Simula el ciclo async real de jQuery: el callback se resuelve después de armar el
        // objeto promise, igual que una petición de red real.
        setTimeout(() => {
            if (respuesta.status >= 200 && respuesta.status < 300) {
                deferred.resolveWith(promise, [respuesta.body, 'success', { status: respuesta.status }]);
            } else {
                deferred.rejectWith(promise, [{ status: respuesta.status, responseJSON: respuesta.body }, 'error', '']);
            }
        }, 0);

        return promise;
    }

    const $ = fakeAjax;
    $.ajax = fakeAjax;
    $.extend = Object.assign;
    $.Deferred = crearDeferred;
    $.fn = {};
    $.ajaxSetup = (opts) => Object.assign(headersDefault, opts.headers || {});
    $.__metaToken = metaToken;
    // `$('meta[name="csrf-token"]').attr('content', token)` — sólo lo que usa el archivo.
    $.__call = (selector) => {
        if (selector === 'meta[name="csrf-token"]') {
            return { attr: (name, valor) => { if (valor !== undefined) { metaToken.valor = valor; } return metaToken.valor; } };
        }
        return { attr: () => undefined };
    };

    return $;
}

test('reintenta una petición que falló con 419 después de refrescar el token, transparente para el llamador', async () => {
    const respuestasPorUrl = {
        '/csrf-token': [{ status: 200, body: { token: 'token-nuevo' } }],
        '/guardar-algo': [
            { status: 419, body: { message: 'CSRF token mismatch.' } },
            { status: 200, body: { ok: true } },
        ],
    };

    const $fake = crearJQueryFalso(respuestasPorUrl);
    // El archivo hace `$('meta...')` como llamada de función además de método — se resuelve
    // reasignando la función misma para que actúe como selector.
    const $ = Object.assign((selector) => $fake.__call(selector), $fake);

    const window = { jQuery: $ };
    new Function('window', fuente)(window);

    let resultado;
    let fallo;
    await new Promise((resolve) => {
        $.ajax('/guardar-algo', { method: 'POST' })
            .done((body) => { resultado = body; resolve(); })
            .fail((xhr) => { fallo = xhr; resolve(); });
    });

    assert.equal(fallo, undefined, 'no tiene que propagar el 419 original al llamador');
    assert.deepEqual(resultado, { ok: true }, 'tiene que devolver la respuesta del reintento exitoso');
});

test('si el refresco de token también falla, propaga el 419 original', async () => {
    const respuestasPorUrl = {
        '/csrf-token': [{ status: 419, body: {} }],
        '/guardar-algo': [{ status: 419, body: { message: 'CSRF token mismatch.' } }],
    };

    const $fake = crearJQueryFalso(respuestasPorUrl);
    const $ = Object.assign((selector) => $fake.__call(selector), $fake);

    const window = { jQuery: $ };
    new Function('window', fuente)(window);

    let fallo;
    await new Promise((resolve) => {
        $.ajax('/guardar-algo', { method: 'POST' })
            .done(() => resolve())
            .fail((xhr) => { fallo = xhr; resolve(); });
    });

    assert.equal(fallo.status, 419, 'sesión realmente vencida: se deja pasar el 419 tal cual');
});

test('una petición que no falla con 419 no dispara ningún refresco de token', async () => {
    const respuestasPorUrl = {
        '/guardar-algo': [{ status: 200, body: { ok: true } }],
    };

    const $fake = crearJQueryFalso(respuestasPorUrl);
    const $ = Object.assign((selector) => $fake.__call(selector), $fake);

    const window = { jQuery: $ };
    new Function('window', fuente)(window);

    let resultado;
    await new Promise((resolve) => {
        $.ajax('/guardar-algo', { method: 'POST' }).done((body) => { resultado = body; resolve(); });
    });

    assert.deepEqual(resultado, { ok: true });
    assert.equal(respuestasPorUrl['/csrf-token'], undefined, 'csrf-token no debería estar en el mapa de respuestas si nunca se llamó');
});
