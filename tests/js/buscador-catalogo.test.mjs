/**
 * Test de la lógica pura de `resources/js/buscador-catalogo.js`.
 *
 * Corre con `node --test tests/js/buscador-catalogo.test.mjs` (test runner nativo de Node, mismo
 * patrón que `tests/js/fecha-ar.test.mjs`).
 *
 * Se prueba sólo la lógica extraída sin DOM (debounce, descarte de respuestas fuera de orden,
 * navegación del índice resaltado): es la parte donde un error se traduce en una línea de
 * comprobante fiscal equivocada en silencio (Principio IV de la constitución). La interacción
 * completa con el DOM (foco, apertura/cierre del panel) se valida a mano con quickstart.md.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// El widget es un IIFE colgado de `window`, no un módulo ESM: se evalúa con un `window` y un
// `jQuery` mínimos (las funciones puras que probamos no tocan el DOM).
const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const fuente = readFileSync(resolve(raiz, 'resources/js/buscador-catalogo.js'), 'utf8');

const window = { jQuery: Object.assign(() => ({}), { fn: {} }) };
new Function('window', fuente)(window);

const { crearDebouncer, moverResaltado, crearSecuenciador } = window.BuscadorCatalogo._internas;

test('el debounce agrupa varias pulsaciones en una sola consulta', () => {
    let llamadas = 0;
    const debouncer = crearDebouncer(50);

    debouncer.disparar(() => { llamadas += 1; });
    debouncer.disparar(() => { llamadas += 1; });
    debouncer.disparar(() => { llamadas += 1; });

    // Todavía no pasó el tiempo de espera: ninguna ejecución.
    assert.equal(llamadas, 0);
});

test('el debounce ejecuta sólo la última función encolada tras la espera', async () => {
    const orden = [];
    const debouncer = crearDebouncer(10);

    debouncer.disparar(() => orden.push('a'));
    debouncer.disparar(() => orden.push('b'));
    debouncer.disparar(() => orden.push('c'));

    await new Promise((r) => setTimeout(r, 30));

    assert.deepEqual(orden, ['c']);
});

test('una respuesta de secuencia vieja no pisa a la vigente', () => {
    const sec = crearSecuenciador();

    const primera = sec.siguiente(); // 1
    const segunda = sec.siguiente(); // 2 (la vigente)

    // La respuesta de la consulta 1 llega tarde, después de que ya se disparó la 2.
    assert.equal(sec.esVigente(primera), false);
    assert.equal(sec.esVigente(segunda), true);
});

test('secuencia: cada llamada a siguiente() vuelve vigente sólo la última', () => {
    const sec = crearSecuenciador();
    const a = sec.siguiente();
    assert.equal(sec.esVigente(a), true);

    const b = sec.siguiente();
    assert.equal(sec.esVigente(a), false);
    assert.equal(sec.esVigente(b), true);
});

test('el resaltado avanza y hace tope en el último elemento (sin dar la vuelta)', () => {
    let resaltado = -1;
    const total = 3;

    resaltado = moverResaltado(resaltado, 1, total); // -1 -> 0
    assert.equal(resaltado, 0);
    resaltado = moverResaltado(resaltado, 1, total); // 0 -> 1
    assert.equal(resaltado, 1);
    resaltado = moverResaltado(resaltado, 1, total); // 1 -> 2
    assert.equal(resaltado, 2);
    resaltado = moverResaltado(resaltado, 1, total); // 2 -> tope en 2
    assert.equal(resaltado, 2);
});

test('el resaltado retrocede y hace tope en 0 (nunca vuelve a -1)', () => {
    let resaltado = 2;
    const total = 3;

    resaltado = moverResaltado(resaltado, -1, total); // 2 -> 1
    assert.equal(resaltado, 1);
    resaltado = moverResaltado(resaltado, -1, total); // 1 -> 0
    assert.equal(resaltado, 0);
    resaltado = moverResaltado(resaltado, -1, total); // 0 -> tope en 0
    assert.equal(resaltado, 0);
});

test('sin elementos, el resaltado siempre es -1', () => {
    assert.equal(moverResaltado(-1, 1, 0), -1);
    assert.equal(moverResaltado(-1, -1, 0), -1);
});

test('Enter con resaltado -1 no dispara onElegir', () => {
    // Simula la guarda que usa el widget en `alEnter`: resaltado < 0 => no se llama a elegir().
    let elegido = null;
    const items = [{ id: 1 }, { id: 2 }];

    function simularEnter(resaltado) {
        if (resaltado < 0 || resaltado >= items.length) { return; }
        elegido = items[resaltado];
    }

    simularEnter(-1);
    assert.equal(elegido, null);

    simularEnter(0);
    assert.deepEqual(elegido, { id: 1 });
});
