# Feature Specification: Cada línea del comprobante es un ajuste independiente en la NC/ND

**Feature Branch**: `096-lineas-independientes-ncnd`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "Corregir el agrupado por producto en la precarga de ítems de NC/ND:
cuando la Venta/Compra de origen tiene el mismo producto en varias líneas distintas (mismo
producto_id, distinto precio y/o bonificación), AjustesPendientesNotaCreditoDebito::itemsDisponibles()
las funde en una sola fila vía groupBy('producto_id'), quedándose con el precio y descuento de la
PRIMERA línea pero sumando la cantidad pendiente de TODAS. Verificado en producción (venta 24854,
0001-00000347): 3 líneas del mismo producto a $13.000, $25.000 (10% bonif.) y $50.000 (15% bonif.)
— total real $94.380 — precargan como 1 sola línea a $13.000 con pendiente=3, proponiendo un total de
$47.190, la mitad del real. El cálculo de 'pendiente de ajuste' también agrega por producto_id, no
por línea, así que no identifica qué línea específica se está ajustando. El fix debe tratar cada
línea del comprobante de origen como una unidad de ajuste independiente, de punta a punta."

## Clarifications

### Session 2026-09-03

- Q: Hay 41 comprobantes (3 ventas + 38 compras) que ya tienen una NC/ND creada sobre un producto
  repetido, calculada con el método agregado viejo (sin referencia a la línea específica). Al pasar
  al cálculo por línea, ¿cómo debe comportarse el "pendiente" para esos 41 casos puntuales? → A:
  Fallback agregado — para un producto de un comprobante donde ninguna NC/ND existente tiene
  referencia de línea, el pendiente sigue calculándose "a lo bruto" (agregado por producto, método
  actual) igual que hoy.
- Q: Si sobre un producto ya existe una nota VIEJA (sin referencia de línea) y se crea una nota
  NUEVA (con referencia) mientras la vieja sigue existiendo, ¿el producto pasa a modo por línea de
  inmediato o hay que esperar a que la vieja ya no esté? → A: Agregado mientras la nota vieja
  exista — el producto vuelve a modo por línea recién cuando YA NO queda ninguna NC/ND (no
  eliminada) sin referencia de línea sobre ese producto en ese comprobante. Mientras coexistan una
  nota vieja y una nueva, no hay forma de saber qué línea puntual consumió la vieja, así que
  mezclar modos ahí contaría mal el pendiente — se prioriza no equivocarse por encima de migrar
  rápido a modo por línea.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Anular una venta con el mismo producto en varias líneas (Priority: P1)

Quien crea una Nota de Crédito sobre una Venta o Compra cuyo comprobante tiene el mismo producto
cargado en más de una línea (con precio y/o bonificación distintos entre líneas — típicamente
service items genéricos como "99999" cargados a mano con montos distintos) necesita que la nota se
precargue con esas líneas **tal como están en el comprobante**, no fundidas en una.

**Why this priority**: Es el bug reportado y verificado en producción: hoy la nota nace por la mitad
(o menos) del importe real del comprobante cuando esto ocurre, sin ningún aviso. Es el mismo tipo de
riesgo fiscal que motivó la spec 095 (nota por un importe equivocado), pero más severo porque además
pierde información — no sólo un total mal calculado, sino líneas enteras que desaparecen de la vista
del usuario.

**Independent Test**: Abrir el alta de NC/ND con "afecta stock = Sí" sobre un comprobante que tiene el
mismo producto en 3 líneas con precios distintos (reproduce la venta 24854) y verificar que aparecen
3 líneas separadas, cada una con su propio precio, bonificación y cantidad — no 1 línea fundida.

**Acceptance Scenarios**:

1. **Given** una Venta con 3 líneas del mismo producto a $13.000, $25.000 (10% bonif.) y $50.000
   (15% bonif.), sin notas previas, **When** se abre el alta de NC/ND con "afecta stock = Sí",
   **Then** el formulario precarga 3 líneas independientes, cada una con su propio precio,
   porcentaje de bonificación y cantidad de esa línea (no una fundida con cantidad sumada).
2. **Given** el mismo comprobante, **When** no se toca nada y se mira el total propuesto,
   **Then** el total coincide con el total del comprobante ($94.380 en el caso reportado), no con
   la mitad.
3. **Given** una Venta con un producto en una sola línea (caso normal, sin repetición),
   **When** se abre el alta de NC/ND, **Then** el comportamiento es idéntico al actual: una línea,
   sin cambios visibles para el caso que ya funcionaba bien.

---

### User Story 2 - Ajustar sólo una de las líneas repetidas (Priority: P1)

Sobre un comprobante con el mismo producto en varias líneas, quien crea la nota necesita poder
anular o ajustar sólo una de esas líneas (por ejemplo, sólo la de $50.000 con 15% de bonificación),
sin verse obligado a tocar las otras ni a que el sistema le proponga ajustar una cantidad que
combina líneas distintas.

**Why this priority**: Es el motivo real por el que estas líneas están separadas en el comprobante
de origen — cada una es una operación comercial distinta (mismo producto, pero vendido en
condiciones distintas). Tratarlas por separado en la nota es correcto también para no-P1 pero es
igual de crítico que la Historia 1, porque sin esto la Historia 1 sólo resuelve la precarga inicial
y no la posibilidad real de uso (borrar una línea y dejar las otras).

**Independent Test**: Sobre el comprobante de la Historia 1, borrar la línea de $50.000 antes de
guardar y confirmar que la nota se crea con las otras 2 líneas intactas, sin alterar sus cantidades,
precios ni bonificaciones.

**Acceptance Scenarios**:

1. **Given** el formulario precargado con las 3 líneas separadas, **When** el usuario borra la
   línea de $50.000, **Then** la nota se guarda con exactamente las 2 líneas restantes, cada una
   con su cantidad, precio y bonificación originales.
2. **Given** el mismo comprobante, **When** el usuario crea una segunda NC/ND después de haber
   ajustado ya una de las 3 líneas en una nota anterior, **Then** el alta de la segunda nota
   precarga únicamente las líneas (o la porción de cantidad) que sigue **pendiente de ajuste**,
   sin volver a ofrecer lo ya ajustado ni mezclarlo con las líneas que nunca se tocaron.

---

### User Story 3 - No romper lo que hoy funciona bien (Priority: P2)

Quien usa NC/ND sobre comprobantes "normales" (cada producto aparece una sola vez) no debe notar
ningún cambio de comportamiento: la spec 095 (espejo de cabecera y descuento general) y todo el
flujo existente de precarga, edición y guardado deben seguir funcionando exactamente igual.

**Why this priority**: Es una historia de no-regresión, no de valor nuevo — pero es la que evita que
arreglar este bug rompa la spec 095 recién implementada o las 860+ notas ya existentes.

**Independent Test**: Correr la suite de tests de NC/ND existente (`NotaCreditoDebitoPrecargaTest`,
`NotaCreditoDebitoTest`, etc.) y verificar 0 regresiones; abrir el alta sobre alguno de los
comprobantes usados como referencia en la spec 095 (venta 24740, 24741, compra 2442) y confirmar
que el total propuesto no cambia.

**Acceptance Scenarios**:

1. **Given** una Venta con cada producto en una única línea, **When** se abre el alta de NC/ND,
   **Then** el total propuesto es idéntico al que proponía antes de este fix.
2. **Given** una nota de crédito ya existente y guardada antes de este fix, **When** se abre para
   editarla, **Then** se sigue viendo y editando exactamente igual (la edición precarga desde la
   nota, no desde el comprobante — sin cambios, spec 095 FR-011).

### Edge Cases

- **Mismo producto, mismo precio y misma bonificación en 2+ líneas** (indistinguibles salvo por el
  orden de carga): cada línea del comprobante sigue siendo una unidad de ajuste independiente —
  no se fusionan aunque sean "iguales" a simple vista, porque siguen siendo dos hechos económicos
  distintos (dos ventas de una unidad, no una venta de dos unidades).
- **Una NC/ND ya existente, creada ANTES de este fix, sobre un comprobante con líneas repetidas**:
  no se recalcula ni se migra. Sigue mostrando lo que tiene guardado (la línea fundida con la
  cantidad sumada), tal como se guardó. Este fix cambia la precarga hacia adelante, no reescribe
  historial.
- **Ajustar una línea repetida cuando ya existe una NC/ND previa creada ANTES de este fix** (que
  ajustó la cantidad fusionada de ese producto): el pendiente de ese producto se sigue calculando
  agregado (a lo bruto), igual que hoy, hasta que se cree la primera nota nueva con este fix sobre
  ese producto. Ver FR-006.
- **Comprobantes migrados sin ID de línea reconstruible**: si el dato migrado no permite identificar
  la línea de origen individualmente (ver Assumptions), el sistema debe seguir funcionando sin
  romperse — cae al comportamiento anterior para esos casos puntuales, documentado como brecha
  conocida, no como excepción silenciosa.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE precargar el alta de NC/ND con una línea independiente por cada línea
  del comprobante de origen (`Venta`/`Compra`) que tenga cantidad pendiente de ajuste, sin fusionar
  por producto — incluso cuando dos o más líneas comparten el mismo producto.
- **FR-002**: Cada línea precargada DEBE conservar el precio, el porcentaje de bonificación
  (`descuento_pct`), el IVA y la cantidad **de esa línea puntual** del comprobante de origen, no un
  valor combinado ni el de otra línea del mismo producto.
- **FR-003**: El cálculo de "cantidad pendiente de ajuste" DEBE evaluarse **por línea de origen**, no
  por producto agregado: cuánto de esa línea puntual ya fue ajustado por notas anteriores (no
  eliminadas) que referencian esa misma línea.
- **FR-004**: El sistema DEBE persistir, en cada línea de la NC/ND, una referencia a la línea
  específica del comprobante de origen que ajusta (no sólo el `producto_id`), para que el cálculo de
  pendiente (FR-003) y la edición de la nota puedan identificar inequívocamente qué línea del
  comprobante corresponde a qué línea de la nota.
- **FR-005**: El total propuesto al abrir el alta DEBE coincidir con el total del comprobante de
  origen (dentro de la tolerancia ya establecida por la spec 095, FR-014) cuando no hay notas
  previas, incluyendo comprobantes con el mismo producto repetido en varias líneas.
- **FR-006**: Para un producto de un comprobante donde **existe al menos una** NC/ND (no eliminada)
  sin referencia a la línea de origen (nota creada antes de este fix, con el cálculo agregado
  viejo), el sistema DEBE calcular el "pendiente" de ese producto **agregado por producto**, igual
  que hoy — sin intentar repartirlo entre líneas ni bloquear el ajuste, y sin importar si además
  existe alguna nota con referencia de línea. El producto pasa a calcularse **por línea** recién
  cuando **ninguna** NC/ND (no eliminada) de ese producto en ese comprobante carece de la
  referencia — es decir, cuando ya no queda ninguna nota "vieja" mezclada con las nuevas.
- **FR-007**: Borrar o modificar una de las líneas precargadas en el formulario, antes de guardar,
  DEBE dejar intactas las demás líneas precargadas (sin recalcular sus cantidades ni fusionarlas).
- **FR-008**: El comportamiento sobre un comprobante donde cada producto aparece en una única línea
  DEBE ser idéntico al actual — este fix no cambia la precarga de esos casos.
- **FR-009**: La edición de una NC/ND ya existente NO DEBE cambiar de comportamiento: sigue
  precargando desde la nota guardada, no desde el comprobante de origen (spec 095, FR-011).
- **FR-010**: El comportamiento DEBE ser equivalente en Ventas y en Compras.

### Key Entities *(include if feature involves data)*

- **VentaItem / CompraItem**: línea del comprobante de origen. Ya tiene identidad propia (su propio
  `id`) que hoy no se usa para el ajuste — sólo se usa `producto_id`. Este fix la convierte en la
  unidad real de "qué se está ajustando".
- **NotaCreditoDebitoItem**: línea de la nota. Hoy sólo guarda `producto_id`; pasa a guardar también
  una referencia a la línea de origen (`VentaItem`/`CompraItem`) para que el "ya ajustado" se calcule
  por línea y no por producto agregado.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Sobre un comprobante con el mismo producto en 3 líneas a precios distintos (caso
  verificado: venta 24854, total $94.380), el total propuesto por la NC/ND es $94.380, no $47.190.
- **SC-002**: El formulario de alta muestra tantas líneas como líneas pendientes de ajuste tiene el
  comprobante, sin fusionar por producto, en el 100% de los comprobantes con producto repetido.
- **SC-003**: 0 regresiones en la suite de tests existente de NC/ND (spec 045, 057, 059, 095) tras
  este cambio.
- **SC-004**: Sobre los comprobantes de referencia sin producto repetido usados en la verificación de
  la spec 095 (ventas 24740, 24741, 24677; compra 2442), el total propuesto no cambia.

## Assumptions

- **Las NC/ND ya creadas (migradas o hechas antes de este fix) no tienen forma de reconstruir a qué
  línea original correspondía cada ajuste** (no existía esa referencia). Este fix aplica hacia
  adelante: no reescribe NC/ND ya existentes ni les asigna retroactivamente una referencia de línea
  que no tenían. Verificado: 47 ventas y 199 compras tienen hoy el mismo producto repetido en más de
  una línea; de ésas, 3 ventas y 38 compras ya tienen una NC/ND creada con el método agregado viejo
  (FR-006 cubre ese caso).
- **"Afecta stock = No"** (notas sin ítems, spec 095 FR-013) no se ve afectado por este fix: sigue
  precargando sólo cabecera, sin ítems.
- El bug es preexistente al `groupBy` de `itemsDisponibles()` — no fue introducido por la spec 095,
  aunque ésta depende de ese método para el total propuesto. Este fix no reabre ni modifica el
  alcance ya cerrado de la spec 095 (cabecera, descuento general, tipo de comprobante); sólo
  corrige la identificación de líneas.
- Coexiste con el criterio ya fijado en spec 045 de que el "pendiente de ajuste" excluye lo ya
  cubierto por notas anteriores no eliminadas — este fix cambia la granularidad del cálculo (por
  línea en vez de por producto agregado), no el criterio en sí.
