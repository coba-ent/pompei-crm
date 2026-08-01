# Feature Specification: Compras suman stock

**Feature Branch**: `030-compras-stock`

**Created**: 2026-08-01

**Status**: Draft

**Input**: User description: "Integrar Compras con Stock: al dar de alta una Compra, cada CompraItem con producto que controla stock debe sumar la cantidad comprada al stock del depósito de destino de esa Compra. Debe cubrir simétricamente alta, edición y baja/eliminación de compra. Análogo a como Ventas resolvió el mismo problema (research.md §R1, servicio StockDeVenta) pero en sentido de entrada de stock en vez de salida."

## Clarifications

### Session 2026-08-01

- Q: ¿La fecha del movimiento de stock que genera una Compra debe ser la `fecha_emision` de la Compra o la fecha del día en que se guarda? → A: `fecha_emision` de la Compra (para que informes de stock por fecha reflejen cuándo entró realmente la mercadería, incluso con carga retroactiva).
- Q: ¿Una Nota de Crédito/Débito sobre una Compra debe también mover stock? → A: Sí — y al revisar el código existente se confirmó que `NotaCreditoDebitoController::storeCompra` **ya** lo hace (NC resta stock, ND suma, con su propio selector de depósito en el modal, spec 009). No requiere trabajo nuevo; queda fuera del alcance de esta feature, sólo documentado aquí para dejar constancia de que no es una brecha.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Alta de Compra suma stock (Priority: P1)

Como usuario que carga una Compra a un proveedor, cuando la guardo, el stock de los productos comprados
(que controlan stock) aumenta automáticamente en el depósito por defecto del CRM, sin que tenga que
hacer un ajuste manual de stock aparte.

**Why this priority**: Es la brecha central detectada — hoy cargar una Compra no mueve stock, lo que
obliga a un ajuste manual paralelo y puede desincronizarse. Sin esto no hay integración.

**Independent Test**: Crear una Compra con un ítem de un producto que controla stock, cantidad 10.
Verificar que el stock del producto en el depósito por defecto aumentó en 10 y que se registró un
`MovimientoStock` de tipo `entrada` con origen la Compra.

**Acceptance Scenarios**:

1. **Given** un producto con `controlaStock()` = true y stock inicial 5 en el depósito por defecto,
   **When** se crea una Compra con un ítem de ese producto por cantidad 10, **Then** el stock del
   producto en el depósito por defecto pasa a 15 y queda un `MovimientoStock` de entrada por +10 con
   origen la Compra.
2. **Given** una Compra con un ítem de un producto que **no** controla stock (p. ej. un servicio),
   **When** se crea la Compra, **Then** no se genera ningún movimiento de stock para ese ítem.
3. **Given** una Compra con varios ítems del mismo producto, **When** se crea la Compra, **Then** el
   stock aumenta en la suma de las cantidades de todos esos ítems.

---

### User Story 2 - Edición de Compra reajusta stock (Priority: P2)

Como usuario que corrige una Compra ya cargada (cambia cantidades, agrega o quita ítems), al guardar la
edición el stock queda reflejando únicamente los ítems y cantidades vigentes después del cambio — ni de
más ni de menos.

**Why this priority**: Sin esto, corregir una Compra dejaría stock fantasma (de la versión vieja) o
duplicado (si además se vuelve a sumar la versión nueva sin reintegrar la vieja).

**Independent Test**: Crear una Compra con un ítem cantidad 10 (stock queda en +10). Editarla y cambiar
la cantidad a 6. Verificar que el stock neto atribuible a esa Compra terminó en +6 (se reintegraron las
10 anteriores y se aplicaron las 6 nuevas).

**Acceptance Scenarios**:

1. **Given** una Compra ya guardada que sumó 10 unidades de un producto, **When** se edita la Compra y
   se cambia la cantidad de ese ítem a 6, **Then** el stock neto atribuible a esa Compra pasa a ser +6
   (se reintegran las 10 anteriores y se aplican las 6 nuevas).
2. **Given** una Compra ya guardada con un ítem de un producto A, **When** se edita y se reemplaza ese
   ítem por uno de un producto B, **Then** el stock de A se reintegra (se resta lo que había sumado) y
   el stock de B aumenta según la nueva cantidad.

---

### User Story 3 - Baja de Compra reintegra stock (Priority: P2)

Como usuario que elimina una Compra cargada por error, al eliminarla el stock que esa Compra había
sumado se resta, para que no quede stock de una compra que ya no existe.

**Why this priority**: Mismo argumento de consistencia que la edición, pero para el caso borrado
completo — evita que compras eliminadas dejen stock fantasma sumado permanentemente.

**Independent Test**: Crear una Compra con un ítem cantidad 10 (stock +10). Eliminarla. Verificar que el
stock del producto vuelve al valor previo a la Compra.

**Acceptance Scenarios**:

1. **Given** una Compra guardada que sumó 10 unidades de un producto en el depósito por defecto,
   **When** se elimina la Compra (borrado lógico), **Then** el stock de ese producto en ese depósito
   disminuye en 10 y queda un `MovimientoStock` de tipo `salida` por −10 (reintegro) con origen la
   Compra.

---

### Edge Cases

- Compra con un ítem cuyo producto fue marcado inactivo (`activo=false`) después de cargada la Compra: no
  es una brecha — `Producto` no implementa soft-delete ni tiene un Global Scope que filtre por `activo`,
  así que la relación `CompraItem::producto()` sigue resolviendo el producto sin cambios; el reintegro por
  edición/baja funciona igual.
- Compra con un ítem de cantidad 0 o negativa: no debe llegar a este punto — ya está bloqueado por la
  validación existente de `StoreCompraRequest`/`UpdateCompraRequest` (cantidad > 0), fuera de alcance
  de esta feature.
- Compra cuyo depósito por defecto del CRM fue desactivado o eliminado entre el alta y la edición/baja:
  el sistema debe usar el mismo criterio de resolución de depósito por defecto que ya usa `StockDeVenta`
  (lanza error explícito si no hay ningún depósito activo, en vez de fallar en silencio). El reintegro
  por edición o baja DEBE resolver el depósito por defecto vigente en ese momento (no busca ni recuerda
  cuál era el depósito por defecto al momento del alta) — es la misma limitación aceptada que ya tiene
  `StockDeVenta` hoy para Ventas manuales; si el negocio cambia el depósito por defecto del CRM, puede
  dejar desincronizado el stock de documentos ya cargados contra el depósito anterior. No se resuelve en
  esta feature (sería agregarle a Ventas y Compras una capacidad que ninguna tiene hoy); cambiar el
  depósito por defecto con documentos históricos abiertos queda fuera de alcance.
- Ítem de Compra cuyo guardado falla después de que otros ítems de la misma Compra ya generaron su
  movimiento de stock (p. ej. un error de validación tardío en un ítem posterior de la misma request):
  no debe quedar un movimiento de stock huérfano de un ítem cuya Compra terminó sin guardarse — ver
  FR-007 (atomicidad transaccional).
- Doble submit de la Compra (mismo `submit_token`): ya está protegido a nivel de `CompraController` (no
  crea una segunda Compra); por lo tanto no debe duplicar el movimiento de stock.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Al crear una Compra, el sistema DEBE sumar al stock, en el depósito por defecto del CRM,
  la cantidad de cada `CompraItem` cuyo producto controla stock (mismo criterio `controlaStock()` que ya
  usa Ventas).
- **FR-002**: Los ítems de Compra cuyo producto no controla stock (o no tiene producto asociado) NO
  deben generar ningún movimiento de stock.
- **FR-003**: Al editar una Compra, el sistema DEBE reintegrar (restar) el stock que había sumado la
  versión anterior de la Compra y aplicar (sumar) el stock correspondiente a la versión nueva, dejando
  el stock neto atribuible a esa Compra igual al de sus ítems vigentes tras la edición.
- **FR-004**: Al eliminar (borrado lógico) una Compra, el sistema DEBE reintegrar (restar) todo el stock
  que esa Compra había sumado.
- **FR-005**: Cada movimiento de stock generado por una Compra DEBE quedar registrado en el histórico
  (`MovimientoStock`) con referencia (origen polimórfico) a la Compra que lo originó, igual que hace
  `StockDeVenta` para Ventas.
- **FR-006**: El depósito de destino de una Compra manual DEBE ser el depósito por defecto del CRM
  (mismo depósito único que usan las Ventas manuales), sin agregar un selector de depósito nuevo al
  formulario de Compra — ver Assumptions.
- **FR-007**: La suma/resta de stock por Compra DEBE ser atómica junto con el guardado de los ítems de
  esa Compra (si falla el guardado de la Compra o de sus ítems, no debe quedar un movimiento de stock
  huérfano, y viceversa).
- **FR-008**: La fecha registrada en cada `MovimientoStock` generado por una Compra DEBE ser la
  `fecha_emision` de esa Compra (no la fecha en que se guarda el registro), para que los informes de
  stock por fecha reflejen cuándo entró realmente la mercadería aunque la Compra se cargue con demora o
  de forma retroactiva.

### Key Entities

- **Compra**: documento de compra a un Proveedor existente. No incorpora ningún campo nuevo para esta
  feature (ver FR-006/Assumptions) — el depósito de destino se resuelve, no se almacena por Compra.
- **CompraItem**: línea de una Compra con producto, cantidad y precio. Su cantidad es la que determina
  cuánto stock suma/resta cada ítem cuando el producto controla stock.
- **MovimientoStock**: registro histórico existente de movimientos de stock; se reutiliza sin cambios de
  estructura, sólo se generan nuevas filas con origen Compra.
- **Stock**: foto de cantidad actual por producto/variante/depósito; existente, se reutiliza sin cambios
  de estructura.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El 100% de las Compras nuevas con al menos un ítem de un producto que controla stock
  generan el movimiento de stock correspondiente en el mismo momento en que se guarda la Compra, sin
  pasos manuales adicionales.
- **SC-002**: Después de editar o eliminar cualquier Compra, el stock de cada producto involucrado queda
  exactamente igual al que resultaría de haber cargado desde cero la Compra en su estado final (o de que
  nunca hubiera existido, en el caso de eliminación) — cero desviación.
- **SC-003**: Ningún ítem de Compra de un producto que no controla stock (servicios, por ejemplo) genera
  jamás un movimiento de stock.

## Assumptions

- **Depósito de destino sin campo nuevo en el formulario**: el informe relevado de Contagram
  (`docs/informe_contagram_egresos.md`) no muestra ningún selector de depósito en el formulario "Nueva
  Compra" real. Agregar un campo ahí sin evidencia relevada violaría el principio rector de fidelidad
  estructural del proyecto. Por eso esta feature **no agrega ningún campo de depósito al formulario de
  Compra**: reutiliza exactamente el mismo criterio que ya usan las Ventas manuales
  (`StockDeVenta::depositoPorDefecto()` → `Deposito::porDefecto()`), es decir, todas las Compras
  cargadas manualmente en el CRM suman stock al depósito marcado como "por defecto" en Configuración.
  Si en el futuro se releva que Contagram real sí tiene ese selector, se ajusta en una spec propia.
- El criterio de "producto que controla stock" es exactamente `Producto::controlaStock()`, ya existente
  y usado por Ventas — no se redefine para Compras.
- Esta feature no cubre Compras generadas automáticamente por integraciones externas (Mercado Libre,
  Tiendanube) porque esas plataformas no generan Compras en este CRM — sólo Ventas. Fuera de alcance.
- Las Notas de Crédito/Débito sobre una Compra ya mueven stock (`NotaCreditoDebitoController::storeCompra`,
  spec 009) — confirmado como no siendo una brecha durante `/speckit-clarify`. Fuera de alcance de esta
  feature, no requiere cambios. Ese flujo usa un `deposito_id` que el usuario elige explícitamente en el
  modal de NC/ND (no el depósito por defecto) — es un criterio distinto e independiente del que usa esta
  feature para Compras (depósito por defecto, sin selector). No son contradictorios: son dos flujos
  distintos (alta de Compra vs. corrección posterior vía NC/ND) que ya tenían, cada uno, su propio
  criterio de depósito antes de esta feature; ninguno de los dos cambia el criterio del otro.
- Se reutiliza `StockService` (`app/Services/Stock/StockService.php`) para los movimientos atómicos,
  igual que hace `StockDeVenta` para Ventas — no se duplica lógica de bajo nivel de stock.
- No se agregan variantes de producto a `CompraItem` en esta feature (hoy no tiene `variante_id`): el
  movimiento de stock por Compra aplica siempre a la variante `null` (producto sin variante), igual
  alcance que tiene hoy la grilla de ítems de Compra. Si el negocio compra productos con variantes por
  Compra, queda documentado como brecha pendiente (ver `docs/documentacion_principal_crm.md §4.3`) hasta
  un relevamiento propio.
