/**
 * Test de las conversiones puras de `resources/js/fecha-ar.js`.
 *
 * Corre con `node --test tests/js/fecha-ar.test.mjs` (test runner nativo de Node, sin agregar
 * dependencias al proyecto).
 *
 * Lo que se prueba acá es la única parte del helper donde un error invierte día y mes en
 * silencio. El resto (datepicker, DOM, modales) se verifica en el navegador y en los tests
 * de ida y vuelta de PHP.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// El helper es un IIFE que se cuelga de `window`, no un módulo ESM: se evalúa con un `window`
// y un `jQuery` mínimos, ya que las funciones puras que probamos no tocan el DOM.
const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const fuente = readFileSync(resolve(raiz, 'resources/js/fecha-ar.js'), 'utf8');

const window = { jQuery: Object.assign(() => ({}), { fn: {} }) };
new Function('window', fuente)(window);

const { isoAvisible, visibleAiso } = window.AppFecha;

test('ISO -> visible no invierte día y mes', () => {
    // El caso del bug original: 5 de agosto. Si algo invierte, sale 08/05/2026.
    assert.equal(isoAvisible('2026-08-05'), '05/08/2026');
    assert.equal(isoAvisible('2026-01-31'), '31/01/2026');
    assert.equal(isoAvisible('2026-12-01'), '01/12/2026');
});

test('visible -> ISO no invierte día y mes', () => {
    assert.equal(visibleAiso('05/08/2026'), '2026-08-05');
    assert.equal(visibleAiso('31/01/2026'), '2026-01-31');
    assert.equal(visibleAiso('01/12/2026'), '2026-12-01');
});

test('ida y vuelta estable en todas las fechas ambiguas del año', () => {
    // Sólo los días <= 12 pueden invertirse sin que la fecha resulte imposible: son los
    // peligrosos, porque el bug no explota, sólo guarda mal. Se recorren todos.
    for (let mes = 1; mes <= 12; mes++) {
        for (let dia = 1; dia <= 12; dia++) {
            const iso = `2026-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
            const visible = isoAvisible(iso);

            assert.equal(visible, `${String(dia).padStart(2, '0')}/${String(mes).padStart(2, '0')}/2026`);
            assert.equal(visibleAiso(visible), iso, `ida y vuelta rota en ${iso}`);
        }
    }
});

test('acepta datetime serializado por Eloquent', () => {
    assert.equal(isoAvisible('2026-08-05T00:00:00.000000Z'), '05/08/2026');
    assert.equal(isoAvisible('2026-08-05 14:30:00'), '05/08/2026');
});

test('acepta día y mes de un solo dígito al tipear', () => {
    assert.equal(visibleAiso('5/8/2026'), '2026-08-05');
    assert.equal(visibleAiso(' 5/8/2026 '), '2026-08-05');
});

test('rechaza fechas que no existen en vez de adivinar', () => {
    assert.equal(visibleAiso('31/02/2026'), null);
    assert.equal(visibleAiso('30/02/2026'), null);
    assert.equal(visibleAiso('00/01/2026'), null);
    assert.equal(visibleAiso('01/13/2026'), null);
    assert.equal(isoAvisible('2026-02-31'), '');
});

test('respeta los años bisiestos', () => {
    assert.equal(visibleAiso('29/02/2024'), '2024-02-29'); // bisiesto
    assert.equal(visibleAiso('29/02/2026'), null);         // no bisiesto
});

test('rechaza formato ISO tipeado en el campo visible', () => {
    // Si alguien pega `2026-08-05` en el input, no se acepta: devolver algo acá sería
    // adivinar el formato, y adivinar es justamente lo que produce el bug.
    assert.equal(visibleAiso('2026-08-05'), null);
});

test('entradas vacías o basura no producen fechas', () => {
    for (const v of ['', null, undefined, 'ayer', '05-08-2026', '05/08/26']) {
        assert.equal(visibleAiso(v), null, `no debería parsear ${JSON.stringify(v)}`);
    }
    for (const v of ['', null, undefined, 'hoy', '05/08/2026']) {
        assert.equal(isoAvisible(v), '', `no debería formatear ${JSON.stringify(v)}`);
    }
});
