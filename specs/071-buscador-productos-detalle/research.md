# Research: Buscador de productos del detalle con foco persistente

No quedaron `NEEDS CLARIFICATION` en el Technical Context: el stack, el endpoint y el patrón de
módulo JS ya existen en el proyecto. Este documento registra las decisiones de diseño y los hallazgos
del relevamiento del código actual.

## Hallazgo previo (corrige un supuesto inicial de la spec)

Al relevar el código se verificó que **el buscador de productos NO usa el mismo componente que el de
Cliente**:

| Campo | Función que lo inicializa | Tiene "Crear X" | Tiene lápiz de editar |
|---|---|---|---|
| `#f-cliente` (Venta, Presupuesto) | `iniciarSelect2Catalogo()` | Sí (`textoCrear: 'Crear Cliente'`) | Sí |
| Categoría / Vendedor (Presupuesto) | `iniciarSelect2Catalogo()` | Sí | Sí |
| **`#f-producto` (Venta, Compra, Presupuesto)** | **`initSelect2()` plano** | **No** | **No** |

La primera redacción de la spec asumía que `#f-producto` compartía el componente "catálogo editable"
y por lo tanto listaba "conservar Crear Producto" y "conservar lápiz de editar" como requisitos de
no-regresión. **Es falso**: esas capacidades nunca existieron en el buscador de productos. La spec se
corrigió antes de este plan; el alcance real del widget es más chico.

Queda además detectada una **inconsistencia preexistente**: la etiqueta del campo dice *"Seleccionar o
Crear Producto/Servicio"* en las 3 vistas, pero no hay ninguna forma de crear desde ahí. Se documenta
como brecha pendiente (no se resuelve en esta feature, ver spec §Fuera de alcance).

## Decisión 1: Widget propio en lugar de Select2 (excepción a CLAUDE.md §5)

**Decisión**: escribir un widget propio (`resources/js/buscador-catalogo.js`) y usarlo únicamente en
el `#f-producto` de las 3 pantallas. Select2 sigue siendo la regla para todo el resto del sistema,
incluidos los demás selects de esas mismas pantallas.

**Rationale**: el requisito del cliente es que el **foco del input** sea independiente del **estado
del panel**. En Select2 eso es imposible por diseño: el campo de búsqueda (`.select2-search__field`)
sólo se inserta en el DOM mientras el desplegable está abierto; al cerrarse, el control renderiza un
`<span>` y el foco vuelve al contenedor. Por eso la solución vigente (`reabrirBuscador()`) tiene que
**reabrir el desplegable entero** sólo para recuperar el foco — que es exactamente el efecto lateral
que el cliente pide eliminar. No es un problema de configuración: es la arquitectura del componente.

**Alternativas consideradas**:
- *Forzar Select2 con CSS/JS* (mantener el dropdown "abierto pero invisible", o mover el
  `search__field` fuera del dropdown): descartado por frágil — depende de la estructura interna del
  componente y se rompe ante cualquier actualización, además de dejar el estado ARIA inconsistente.
- *Cambiar a otra librería* (Tom Select, Choices.js, autoComplete.js): descartado — introducir una
  dependencia nueva al proyecto para 3 campos, con su propio peso, estilos y curva, cuando lo que se
  necesita es un input + una lista. Además obligaría a convivir con dos librerías de select.
- *Reemplazar Select2 en todo el proyecto*: descartado de plano — alcance enorme, riesgo alto y
  ningún beneficio para los ~40 selects que no tienen el problema del foco.

## Decisión 2: Un solo módulo genérico, la lógica de negocio se inyecta

**Decisión**: el widget no sabe nada de productos ni de comprobantes. Expone `montar($el, opciones)`
donde el llamador pasa: cómo buscar (`buscar(termino) → Promise<items[]>`), cómo mostrar cada item
(`formatear(item) → string`) y qué hacer al elegir (`onElegir(item)`). Cada pantalla conserva su
propia lógica de armado de línea:

- **Venta**: `precio_unitario = producto.precio`, `iva_pct = producto.iva_venta_pct || '21'`, manda
  `lista_precio_id` en la consulta.
- **Presupuesto**: igual que Venta (mismo `precio` y `lista_precio_id`).
- **Compra**: `precio_unitario = producto.costo`, `iva_pct = producto.iva_compra_pct` **sólo si el
  comprobante es tipo A**, y no manda `lista_precio_id`.

**Rationale**: es lo que difiere legítimamente entre pantallas y lo que FR-006/SC-004 exigen no tocar.
Mantenerlo en cada JS de pantalla deja el diff mínimo y evita meter reglas fiscales dentro de un
componente de UI.

**Alternativas consideradas**: un widget "de productos" que supiera armar la línea solo — descartado:
concentraría en un componente de interacción tres variantes de reglas de precio/IVA, justo lo que la
constitución quiere mantener explícito y testeable.

## Decisión 3: Reemplazar el `<select>` por un `<input type="text">` en el Blade

**Decisión**: en las 3 vistas, `<select id="f-producto" class="form-select">` pasa a
`<input type="text" id="f-producto" class="form-control" autocomplete="off" placeholder="Buscar producto...">`.

**Rationale**: el requisito es un campo de texto siempre visible. Mantener un `<select>` oculto
"por las dudas" no aporta nada: el valor elegido no se envía en el submit (el producto se agrega al
array `items` de JavaScript, que es lo que se serializa), así que el `<select>` no cumple ningún rol
de formulario. `autocomplete="off"` evita que el navegador superponga su propio autocompletado al
panel de sugerencias.

**Verificado**: ninguna de las 3 pantallas lee `$('#f-producto').val()` para el guardado; el único uso
es como origen del evento de selección.

## Decisión 4: Paridad de búsqueda — misma consulta, mismo debounce

**Decisión**: el widget replica exactamente los parámetros que hoy manda Select2 al endpoint
`productos.opciones`, con debounce de **250 ms** (el default de `ajax.delay` de Select2) y descarte de
respuestas obsoletas por contador de secuencia.

Parámetros por pantalla (relevados del código actual):

| Pantalla | Parámetros |
|---|---|
| Venta | `q`, `incluir_servicios: 1`, `lista_precio_id` (de `#f-lista-precio`, o `null`) |
| Presupuesto | `q`, `incluir_servicios: 1`, `lista_precio_id` (de `#f-lista-precio`, o `null`) |
| Compra | `q`, `incluir_servicios: 1` |

Formato de fila (idéntico en las 3 hoy): `'(' + id + ') ' + nombre + (codigo ? ' (' + codigo + ')' : '')`.

**Rationale**: es la condición de no-regresión explícita (FR-005/SC-003). Como el backend no se toca y
los parámetros son los mismos, la equivalencia de resultados es estructural, no algo a "probar por
muestreo". El descarte por secuencia cubre FR-012: sin él, tipear rápido puede dejar en pantalla los
resultados de un término anterior (Select2 ya lo maneja internamente y hay que reproducirlo).

**Alternativas consideradas**: cachear resultados en el cliente — descartado, agrega una fuente de
divergencia con el catálogo real (un producto recién editado mostraría datos viejos) para un beneficio
marginal.

## Decisión 5: Sin auto-resaltado de la primera opción

**Decisión**: al abrirse el panel no hay ninguna opción resaltada. El resaltado aparece recién cuando
el usuario baja con la flecha. Enter sin resaltado no hace nada.

**Rationale**: el campo carga líneas de comprobantes fiscales. Un Enter reflejo (por ejemplo, al
terminar de tipear un código) no debe cargar "lo primero que apareció": el costo de una línea
equivocada en una factura es alto y el usuario podría no notarlo. Es un caso donde conviene pedir una
confirmación explícita (flecha + Enter, o clic).

**Alternativas consideradas**: auto-resaltar la primera opción (como hace Select2) — descartado por el
riesgo de carga accidental descrito arriba; se documenta en spec §Edge Cases para que sea una decisión
visible y no un olvido.

## Decisión 6: Estados del panel

**Decisión**: el panel tiene 4 estados visibles y mutuamente distinguibles: *buscando*, *con
resultados*, *sin coincidencias*, *error de consulta* (SC-007). Nunca se queda vacío ni se cierra
solo ante un resultado vacío.

**Rationale**: hoy Select2 muestra "Searching…" y "No results found" por su cuenta; perder eso sería
una regresión de usabilidad. Distinguir *sin coincidencias* de *error* importa porque llevan a
acciones distintas del usuario (crear/corregir el término vs. reintentar).

## Decisión 7: Testing

**Decisión**: tests con el runner de Node (`tests/js/buscador-catalogo.test.mjs`, mismo patrón que
`tests/js/fecha-ar.test.mjs`) sobre la lógica pura extraíble: (a) el debounce agrupa pulsaciones,
(b) una respuesta vieja que llega tarde no pisa a una nueva, (c) el índice resaltado se mueve y hace
tope correctamente en los extremos. La interacción completa (foco, apertura/cierre, paridad de línea
agregada) se valida a mano con el guion de `quickstart.md`.

**Rationale**: cumple el Principio IV concentrando el esfuerzo donde hay riesgo real de regresión
silenciosa. No se monta un entorno de DOM headless (jsdom/Playwright) sólo para esta feature: el
proyecto no lo tiene y sería infraestructura nueva desproporcionada frente a 3 campos.
