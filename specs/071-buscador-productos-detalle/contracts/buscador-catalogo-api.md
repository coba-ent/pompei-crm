# Contrato: API del widget `BuscadorCatalogo`

Interfaz pública del módulo `resources/js/buscador-catalogo.js`. Es el único contrato que esta
feature introduce: **no hay contratos HTTP nuevos** (el endpoint de catálogo no se toca).

## Montaje

```js
const instancia = window.BuscadorCatalogo.montar(elemento, opciones);
```

- `elemento`: el `<input type="text">` (nodo DOM o selector). Debe existir en el DOM al montar.
- Devuelve una instancia con los métodos de control listados más abajo.
- Montar dos veces sobre el mismo elemento es idempotente: la segunda llamada devuelve la instancia
  ya existente sin duplicar listeners ni paneles.

## Opciones

| Opción | Tipo | Obligatoria | Descripción |
|---|---|---|---|
| `buscar` | `(termino) => Promise<Array>` | Sí | Ejecuta la consulta y resuelve con el array crudo de resultados. El widget nunca sabe a qué URL le pega. Si la promesa rechaza, el panel entra en estado *error* (FR-011). |
| `formatear` | `(item) => string` | Sí | Texto visible de la fila. Se inserta como **texto plano**, nunca como HTML (ver Seguridad). |
| `onElegir` | `(item) => void` | Sí | Se dispara al confirmar una opción (clic o Enter). Recibe el objeto crudo. El widget **no** espera nada del retorno: cierra el panel, vacía el input y devuelve el foco por su cuenta. |
| `placeholder` | `string` | No | Texto del input vacío. Default: `'Buscar...'`. |
| `debounceMs` | `number` | No | Espera antes de consultar. Default: **250** (paridad con Select2, research.md Decisión 4). |
| `minimoCaracteres` | `number` | No | Largo mínimo del término para consultar. Default: **0** (igual que hoy: el buscador actual consulta desde el primer carácter). |
| `textoSinResultados` | `string` | No | Default: `'Sin coincidencias'`. |
| `textoBuscando` | `string` | No | Default: `'Buscando...'`. |
| `textoError` | `string` | No | Default: `'No se pudo buscar. Reintentá.'`. |

## Métodos de la instancia

| Método | Descripción |
|---|---|
| `enfocar()` | Pone el foco en el input sin abrir el panel. |
| `limpiar()` | Vacía el término y cierra el panel, sin disparar `onElegir`. |
| `cerrar()` | Cierra el panel conservando el término y el foco. |
| `destruir()` | Quita listeners y el panel del DOM; deja el `<input>` intacto. |

## Comportamiento garantizado por el contrato

Estas son las garantías de las que dependen los requisitos de la spec; cualquier implementación del
módulo debe cumplirlas:

1. **Foco independiente del panel** (FR-001): abrir o cerrar el panel nunca mueve el foco fuera del
   `<input>`. El panel no contiene ningún elemento focusable por tabulación.
2. **Ciclo de elección** (FR-003): al confirmar una opción, en este orden: se llama `onElegir(item)`,
   se cierra el panel, se vacía el input, el input conserva el foco.
3. **Reapertura por tipeo** (FR-004): con el input enfocado y vacío, escribir vuelve a abrir el panel
   sin ninguna acción intermedia.
4. **Una sola consulta vigente** (FR-012): las respuestas de consultas anteriores a la última
   disparada se descartan; el panel siempre refleja el término vigente.
5. **Teclado** (FR-007): `↓`/`↑` mueven el resaltado (con tope en los extremos, sin dar la vuelta);
   `Enter` confirma **sólo si hay una opción resaltada**; `Escape` cierra conservando término y foco.
   Al abrirse el panel **no hay nada resaltado** (research.md Decisión 5).
6. **Cierre pasivo** (FR-008): clic fuera del widget o pérdida de foco cierran el panel sin elegir
   nada y sin borrar el término.
7. **Estados visibles** (FR-009/FR-010/SC-007): *buscando*, *con resultados*, *sin coincidencias* y
   *error* son cuatro estados distinguibles; el panel nunca queda vacío sin explicación.
8. **Accesibilidad** (FR-016): el input expone `role="combobox"`, `aria-expanded`, `aria-controls` y
   `aria-activedescendant`; el panel expone `role="listbox"` y cada fila `role="option"`.

## Seguridad

`formatear` devuelve **texto**, no HTML: el widget lo inserta con `textContent`. Los nombres de
producto son datos cargados por el usuario y podrían contener `<`, `>` o comillas; insertarlos como
HTML sería una inyección. Esta es la misma razón por la que el código actual usa `$('<span></span>').text(...)`
en el `templateResult` del selector de Cliente.

## Uso previsto por pantalla

```js
// Venta (resources/js/ventas.js) — Presupuesto es análogo
window.BuscadorCatalogo.montar('#f-producto', {
    placeholder: 'Buscar producto...',
    buscar: (termino) => $.get(rutas.productosOpciones, {
        q: termino,
        incluir_servicios: 1,
        lista_precio_id: $('#f-lista-precio').val() || null,
    }).then((resp) => resp.data),
    formatear: (p) => '(' + p.id + ') ' + p.nombre + (p.codigo ? ' (' + p.codigo + ')' : ''),
    onElegir: (producto) => {
        items.unshift({ /* … exactamente la línea que arma hoy el handler select2:select … */ });
        renderItems();
    },
});
```

Compra difiere sólo en que **no** manda `lista_precio_id` y en la línea que arma (`costo` /
`iva_compra_pct` condicionado a tipo A). Ver `data-model.md`.
