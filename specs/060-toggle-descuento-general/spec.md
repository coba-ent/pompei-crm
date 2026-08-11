# Feature Specification: Toggle %/monto fijo para el Descuento General

**Feature Branch**: `060-toggle-descuento-general`

**Created**: 2026-08-11

**Status**: Draft

**Input**: User description: "Toggle %/monto fijo para el descuento general en Presupuestos, Ventas,
Compras y Notas de Crédito/Débito: hoy el descuento general de cada comprobante se carga siempre
como porcentaje. Se necesita agregar un botón inline junto al input de descuento general que permita
alternar entre cargarlo como porcentaje (%) o como monto fijo ($) — mismo campo visual, cambia la
interpretación/unidad del valor ingresado y cómo se calcula el descuento resultante sobre el
subtotal. Debe estar disponible tanto en el formulario de alta (crear) como en el de edición, para
los cuatro módulos, que hoy comparten el mismo patrón de descuento general (Presupuestos/Ventas vía
CalculoComprobante; Compras vía el mismo servicio; NC/ND con cálculo propio client-side). Confirmado
por captura real de Contagram (Editar Nota de Crédito, 11/08/2026): el campo 'Descuento General' ya
tiene ahí un botón '%' inline junto al valor, mismo patrón esperado para los otros tres módulos. Al
guardar, el comprobante debe persistir tanto el tipo de descuento elegido (porcentaje o monto fijo)
como el valor cargado en esa unidad, de forma que al reabrir para editar se muestre exactamente como
se cargó (no convertido)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Cargar el descuento general como monto fijo en vez de porcentaje (Priority: P1) 🎯 MVP

Un usuario está cargando una Venta (o Presupuesto, Compra, NC/ND) y conoce el descuento a aplicar
como un monto fijo en pesos (ej. "$5.000 de descuento"), no como porcentaje. Hoy sólo puede cargarlo
como %, obligándolo a calcular a mano qué porcentaje representa ese monto sobre el subtotal. Necesita
un botón junto al campo "Descuento General" que alterne la unidad entre % y $, para cargar
directamente el monto que conoce.

**Why this priority**: es el caso de uso concreto que motiva el pedido — sin esto, el usuario sigue
calculando porcentajes a mano.

**Independent Test**: en el formulario de alta de una Venta con ítems cargados, hacer clic en el
botón de unidad junto a "Descuento General" para pasar de % a $, cargar un monto fijo, y verificar que
el descuento aplicado y el total resultante reflejan ese monto exacto (no un porcentaje).

**Acceptance Scenarios**:

1. **Given** el formulario de alta de Venta/Presupuesto/Compra/NC-ND con el descuento general en modo
   % (default), **When** el usuario hace clic en el botón inline junto al campo, **Then** el botón
   pasa a mostrar "$", el campo pasa a interpretarse como monto fijo, y cualquier valor ya cargado se
   limpia (no se auto-convierte un número que estaba en % a $ o viceversa, para no confundir al
   usuario con un valor que no cargó él).
2. **Given** el modo "$" activo con un monto cargado, **When** el usuario hace clic en el botón
   nuevamente, **Then** vuelve a modo "%" y el campo se limpia de la misma manera.
3. **Given** el modo "$" con un monto fijo cargado mayor a $0, **When** el sistema recalcula
   subtotal/descuento/total del comprobante, **Then** el descuento aplicado es exactamente ese monto
   fijo (no un porcentaje derivado), y el total baja en esa misma cantidad respecto del subtotal sin
   descuento (sujeto a la distribución proporcional entre neto e IVA ya vigente para Ventas/
   Presupuestos, ver spec 044).
4. **Given** un monto fijo de descuento general mayor al subtotal del comprobante, **When** el usuario
   intenta guardar, **Then** el sistema rechaza el guardado con un mensaje claro (el descuento no
   puede ser mayor al importe a descontar).

---

### User Story 2 - Ver el mismo modo y valor al reabrir para editar (Priority: P1)

Un usuario abre para editar una Venta/Presupuesto/Compra/NC-ND que se cargó originalmente con
descuento general en monto fijo (o en %). Necesita que el formulario de edición muestre el mismo modo
y el mismo valor con el que se cargó originalmente, no un valor convertido a la otra unidad.

**Why this priority**: sin esto, el toggle es inconsistente entre alta y edición — el usuario perdería
de vista cómo cargó el descuento originalmente, y editar sin querer podría cambiar el monto real
aplicado.

**Independent Test**: crear una Venta con descuento general en modo "$" y un monto, guardarla, volver
a abrirla en modo edición, y verificar que el botón de unidad muestra "$" y el campo muestra el mismo
valor cargado (no recalculado a %).

**Acceptance Scenarios**:

1. **Given** un comprobante guardado con descuento general en modo %, **When** se abre su formulario
   de edición, **Then** el botón de unidad muestra "%" y el campo muestra el valor porcentual
   original.
2. **Given** un comprobante guardado con descuento general en modo $, **When** se abre su formulario
   de edición, **Then** el botón de unidad muestra "$" y el campo muestra el monto fijo original.
3. **Given** un comprobante editado cambiando sólo otros campos (no el descuento general), **When** se
   guarda, **Then** el modo y valor del descuento general no cambian por sí solos.

---

### User Story 3 - Mismo comportamiento en los cuatro módulos (Priority: P2)

Un usuario que ya usa el toggle en Ventas espera encontrar el mismo control, en el mismo lugar visual
relativo al campo, con el mismo comportamiento, en Presupuestos, Compras y Notas de Crédito/Débito.

**Why this priority**: consistencia de UI entre módulos hermanos — no es tan urgente como que el
toggle funcione (P1), pero es necesario para que la fidelidad estructural del proyecto no se rompa
entre pantallas.

**Independent Test**: repetir el Test de la Historia 1 en Presupuestos, Compras y Notas de Crédito/
Débito (alta y edición, Ventas y Compras) y verificar el mismo comportamiento visual y de cálculo en
los cuatro.

**Acceptance Scenarios**:

1. **Given** cualquiera de los cuatro módulos, **When** se compara el control de descuento general,
   **Then** el botón de unidad, su posición y su comportamiento al alternar son iguales en los cuatro.
2. **Given** una Nota de Crédito/Débito (que hoy no persiste el descuento general — sólo calcula el
   monto final en el navegador), **When** se guarda con descuento general en cualquiera de los dos
   modos, **Then** el tipo y valor cargados quedan persistidos igual que en los otros tres módulos,
   para poder reabrirse en el mismo modo (corrige ese hueco existente, ver Assumptions).

---

### Edge Cases

- Cambiar de modo con el campo vacío o en $0: no dispara la validación de "monto mayor al subtotal"
  (sólo aplica si hay un valor cargado mayor a 0).
- Alternar el modo varias veces seguidas antes de guardar: sólo importa el modo y valor vigentes al
  momento de guardar, no el historial de cambios de modo dentro de la sesión de carga.
- Un Presupuesto con descuento general en monto fijo que se convierte en Venta (spec 044 US2): el modo
  y valor del descuento general se trasladan tal cual a la Venta resultante, sin reconvertir a %.
- Un comprobante con descuento de línea (`descuento_pct` por ítem) y descuento general en monto fijo
  combinados: el descuento de línea sigue aplicándose primero, sobre el bruto del ítem, igual que hoy;
  el descuento general en monto fijo se aplica después, sobre lo que resulte de ahí, prorrateado igual
  que hoy se prorratea el de %, entre los ítems/alícuotas (mismo criterio de spec 044, adaptado a un
  monto ya conocido en vez de un porcentaje a calcular).
- Monto fijo cargado sin ítems todavía (subtotal $0): no dispara la validación de "monto mayor al
  subtotal" (no hay nada contra qué comparar todavía); al guardar, si el subtotal sigue siendo $0, el
  descuento general efectivo aplicado es $0 (no hay nada que descontar), sin rechazar el guardado por
  esto — la validación de FR-007 sólo aplica cuando el subtotal de ítems es mayor a $0.
- Monto fijo cargado exactamente igual al subtotal de ítems: caso válido (no rechazado por FR-007, que
  sólo rechaza cuando el monto es *mayor* al subtotal) — el comprobante queda con neto e IVA en $0.
- Descuento general dejado en $0 o vacío en cualquiera de los dos modos: se persiste igual que hoy se
  persiste `descuento_general_pct = null`/`0` cuando no hay descuento — el modo (tipo) elegido se
  guarda igual, pero no hay ningún efecto sobre el cálculo.
- Editar un comprobante cambiando únicamente el modo del descuento general (sin recargar un valor
  nuevo): al alternar el botón el campo se limpia (mismo comportamiento de FR-003 aplicado también en
  edición, no sólo en alta) — si el usuario guarda así, el descuento general queda en $0/vacío bajo el
  nuevo modo, no conserva el valor numérico anterior reinterpretado en la otra unidad.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El formulario de alta y el de edición de Presupuestos, Ventas, Compras y Notas de
  Crédito/Débito DEBEN mostrar un botón inline junto al campo "Descuento General" que alterna su
  unidad entre porcentaje (%) y monto fijo ($).
- **FR-002**: El modo por defecto al crear un comprobante nuevo DEBE ser porcentaje (%), igual al
  comportamiento actual, para no sorprender a los usuarios existentes.
- **FR-003**: Al alternar el modo del botón, el sistema DEBE limpiar el valor cargado en el campo (no
  convertir automáticamente el número entre unidades).
- **FR-004**: El sistema DEBE persistir, junto con cada comprobante (Presupuesto, Venta, Compra, Nota
  de Crédito/Débito), tanto el modo de descuento general elegido (porcentaje o monto fijo) como el
  valor cargado en esa unidad.
- **FR-005**: Al reabrir un comprobante existente para editar, el formulario DEBE mostrar el mismo
  modo y el mismo valor con el que se guardó originalmente, sin reconvertir entre unidades.
- **FR-006**: Cuando el modo es monto fijo, el sistema DEBE aplicar ese monto exacto como descuento
  general (no derivar un porcentaje), prorrateado entre ítems/alícuotas de IVA con el mismo criterio
  ya vigente para el modo porcentaje (spec 044). Presupuestos, Ventas y Compras comparten el mismo
  servicio de cálculo, así que este criterio aplica sin excepción a los tres por igual.
- **FR-007**: El sistema DEBE rechazar el guardado si el monto fijo de descuento general cargado es
  mayor al subtotal de los ítems ya con sus descuentos de línea aplicados (equivalente a
  `subtotal_sin_descuento`, es decir, antes de aplicar el descuento general pero después del descuento
  por ítem), con un mensaje de error claro. Un monto igual a ese subtotal es válido (no se rechaza,
  ver Edge Cases); la validación no aplica si ese subtotal es $0 (sin ítems cargados aún).
- **FR-008**: Cuando el modo es porcentaje, el comportamiento de cálculo DEBE ser idéntico al actual
  (no-regresión) — el toggle sólo agrega la alternativa de monto fijo, no cambia el cálculo existente
  en modo %.
- **FR-009**: Los cuatro módulos DEBEN tener el mismo control (posición, comportamiento) para el
  toggle, siguiendo el patrón ya presente en la pantalla real de Contagram "Editar Nota de Crédito"
  (botón "%" inline junto al campo).
- **FR-010**: Para Notas de Crédito/Débito, que hoy no persisten el detalle del descuento general
  (sólo el monto final del comprobante), el sistema DEBE empezar a persistir el modo y valor del
  descuento general igual que los otros tres módulos, para cumplir FR-004/FR-005 también ahí.

### Key Entities *(include if feature involves data)*

- **Presupuesto / Venta / Compra / NotaCreditoDebito**: cada uno pasa a tener, además del valor de
  descuento general ya existente, un dato de qué unidad representa ese valor (porcentaje o monto
  fijo). Para Notas de Crédito/Débito esto es un dato nuevo (hoy no existe ninguna persistencia del
  descuento general).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede cargar un descuento general como monto fijo en cualquiera de los cuatro
  módulos sin tener que calcular a mano el porcentaje equivalente.
- **SC-002**: El 100% de los comprobantes reabiertos para edición muestran el mismo modo (%/$) y el
  mismo valor con el que fueron guardados originalmente, sin excepción.
- **SC-003**: El comportamiento de cálculo en modo porcentaje no cambia para ningún comprobante
  existente ya guardado antes de esta funcionalidad (0 regresiones).
- **SC-004**: El control se ve y se comporta igual en los cuatro módulos, sin diferencias perceptibles
  para un usuario que ya conoce el control en uno de ellos.

## Assumptions

- El botón de unidad es un toggle binario simple (%/$), sin más unidades — coincide con lo observado
  en la captura real de Contagram.
- Al alternar el modo se limpia el valor en vez de auto-convertirlo, porque convertir automáticamente
  (ej. "15%" → "$X" calculado sobre un subtotal que puede seguir cambiando mientras se cargan ítems)
  puede generar un valor que el usuario no reconoce como propio; limpiar es más predecible y es
  coherente con que Contagram real tampoco muestra evidencia de conversión automática en la captura.
- Para Presupuestos, Ventas y Compras, que ya calculan el descuento general mediante el servicio
  compartido `CalculoComprobante` (o equivalente), el modo monto fijo se sustenta convirtiendo
  internamente ese monto a un descuento aplicado directo, reutilizando el mismo criterio de
  prorrateo proporcional a neto e IVA que spec 044 ya estableció para el modo porcentaje.
- Para Notas de Crédito/Débito, que hoy sólo envían el `monto` final calculado en el navegador sin
  persistir el desglose de descuento general (gap preexistente, no introducido por spec 057/059),
  esta funcionalidad agrega esa persistencia (modo + valor) como parte de FR-010, sin tocar el resto
  del cálculo (`monto / 1.21`) ya establecido para NC/ND en spec 044.
- No se requiere migración de datos de comprobantes ya guardados con el `descuento_general_pct`
  actual — todos se interpretan como modo "porcentaje" por defecto (mismo valor, mismo significado
  que hoy), sin necesidad de backfill explícito más allá de asumir ese valor por defecto para filas
  existentes.
- Fuera de alcance: el descuento *por línea/ítem* (columna "Desc." de cada renglón) no se toca en este
  spec — aunque la captura de Contagram muestra un botón "%" similar también ahí, el pedido del
  usuario fue específicamente sobre el descuento general a nivel comprobante.
