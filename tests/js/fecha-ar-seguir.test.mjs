/**
 * Test de `AppFecha.seguir()` — el autocompletado de "Servicio Desde/Hasta" desde la Emisión.
 *
 * Corre con `node --test tests/js/fecha-ar-seguir.test.mjs`.
 *
 * Lo que importa fijar acá es **cuándo deja de copiar**. Que precargue es la parte fácil; el
 * riesgo real es el opuesto: que le pise al vendedor un valor que él escribió a mano, o que se
 * desactive solo con su propia escritura y nunca llegue a precargar nada.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const fuente = readFileSync(resolve(raiz, 'resources/js/fecha-ar.js'), 'utf8');

/**
 * jQuery mínimo: sólo `val`, `data`, `on` y `trigger` sobre objetos sueltos.
 *
 * Alcanza porque `seguir()` no toca el DOM — coordina campos a través de `fecha:cambio`, que es
 * el mismo evento que dispara `init()` tanto con el calendario como con el tipeo manual.
 */
function crearJQuery() {
    const jq = (obj) => obj;
    jq.fn = {};

    return jq;
}

function campo(valorInicial = '') {
    const estado = { valor: valorInicial, datos: {}, handlers: {} };

    const el = {
        jquery: '3.0.0-fake',
        val(v) {
            if (v === undefined) { return estado.valor; }
            estado.valor = v === null ? '' : v;

            return el;
        },
        data(k, v) {
            if (v === undefined) { return estado.datos[k]; }
            estado.datos[k] = v;

            return el;
        },
        removeData(k) { delete estado.datos[k]; return el; },
        removeAttr() { return el; },
        on(evento, fn) {
            (estado.handlers[evento] = estado.handlers[evento] || []).push(fn);

            return el;
        },
        trigger(evento) {
            (estado.handlers[evento] || []).forEach((fn) => fn.call(el));

            return el;
        },
        // Simula lo que hace el usuario: escribe y el `init()` real dispara `fecha:cambio`.
        escribir(visible) {
            estado.valor = visible;
            el.trigger('fecha:cambio');

            return el;
        },
    };

    return el;
}

function armar(emisionVisible = '05/08/2026') {
    const window = { jQuery: crearJQuery() };
    new Function('window', fuente)(window);

    const emision = campo(emisionVisible);
    const desde = campo();
    const hasta = campo();

    window.AppFecha.seguir(emision, [desde, hasta]);

    return { emision, desde, hasta };
}

test('precarga los dos campos con la fecha de emisión', () => {
    const { desde, hasta } = armar('05/08/2026');

    assert.equal(desde.val(), '05/08/2026');
    assert.equal(hasta.val(), '05/08/2026');
});

test('no invierte día y mes al precargar', () => {
    // El caso peligroso: día ≤ 12, donde invertir da una fecha que igual existe.
    const { desde } = armar('05/08/2026');

    assert.equal(desde.val(), '05/08/2026');
    assert.equal(desde.data('fecha'), '2026-08-05');
});

test('sigue a la emisión mientras nadie toque los campos', () => {
    const { emision, desde, hasta } = armar('05/08/2026');

    emision.escribir('20/09/2026');

    assert.equal(desde.val(), '20/09/2026');
    assert.equal(hasta.val(), '20/09/2026');
});

test('deja de seguirla en cuanto el usuario escribe uno de los dos', () => {
    // Lo que NO puede pasar: que el vendedor ponga un período de servicio a mano y al corregir
    // la emisión se lo pisemos.
    const { emision, desde, hasta } = armar('05/08/2026');

    desde.escribir('01/07/2026');
    emision.escribir('20/09/2026');

    assert.equal(desde.val(), '01/07/2026', 'lo que escribió el usuario queda intacto');
    // El compañero deja de seguir a la emisión y conserva lo último que se le había precargado.
    // Es deliberado: si el vendedor está definiendo un período de servicio propio, mover "Hasta"
    // por detrás al corregir la emisión sería un cambio que él no pidió y que no vería.
    assert.equal(hasta.val(), '05/08/2026');
});

test('vaciar un campo a mano también cuenta como tomar el control', () => {
    // Un servicio vacío es un dato legítimo: si lo borró, no se lo volvemos a llenar.
    const { emision, desde } = armar('05/08/2026');

    desde.escribir('');
    emision.escribir('20/09/2026');

    assert.equal(desde.val(), '');
});

test('una emisión vacía no borra lo que ya se había precargado', () => {
    const { emision, desde } = armar('05/08/2026');

    emision.escribir('');

    assert.equal(desde.val(), '05/08/2026');
});

test('no se autodesactiva con su propia escritura', () => {
    // `set()` termina disparando el mismo `fecha:cambio` que el tipeo. Sin el flag `propio`, la
    // precarga inicial se tomaría por una edición del usuario y el campo nunca volvería a seguir
    // a la emisión.
    const { emision, desde } = armar('05/08/2026');

    emision.escribir('20/09/2026');
    emision.escribir('21/09/2026');

    assert.equal(desde.val(), '21/09/2026');
});
