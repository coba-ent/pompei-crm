# Feature Specification: Envío Manual a ARCA para Notas de Crédito/Débito, con IVA real por línea

**Feature Branch**: `097-envio-manual-arca-ncnd`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "Envío manual a ARCA para Notas de Crédito/Débito, con cálculo real de IVA
por línea. Hoy la emisión de CAE de una NC/ND se dispara automáticamente al crearla
(NotaCreditoDebitoController::store/storeCompra, sólo si la venta/compra original ya tiene
ComprobanteFiscal aprobado) y usa un cálculo simplificado de IVA fijo al 21% (monto/1.21) en vez del
desglose real por línea. Esto reproduce para NC/ND el mismo defecto que la spec 040 ya corrigió para
Ventas (envío automático no deseado contra ARCA), y además nunca aprovechó que la spec 096 (línea
independiente por venta_item_id/compra_item_id, con iva_pct y neto reales por línea) ya deja los datos
disponibles para calcular el IVA real. Alcance: sacar el disparo automático; agregar acción manual
'Enviar a ARCA' por fila para NC/ND calcando el patrón de spec 040 (confirmación, modal de resultado
persistente, toast sólo para rechazos de precondición); corregir el cálculo de IVA usando los items
reales de cada línea; paridad para NC/ND de Compras; actualizar documentación."

## Clarifications

### Session 2026-09-03

- Q: ¿Los modales de "Enviar a ARCA" para NC/ND son propios o reutilizan los de Venta (spec 040)? → A: Modales propios de NC/ND (`#modal-confirmar-arca-nota` / `#modal-resultado-arca-nota`), independientes de los de Venta — evita acoplar dos flujos con distinta condición de elegibilidad a un mismo componente.
- Q: Si una NC/ND tiene ítems mixtos (algunos con `venta_item_id`/`compra_item_id` de spec 096, otros sin esa referencia), ¿cómo se calcula el IVA real por línea? → A: Fallback agregado para toda la nota — si al menos un ítem no tiene línea de origen, la nota completa usa el bloque único de IVA (mismo criterio dual que spec 096 ya aplicó en `AjustesPendientesNotaCreditoDebito::pendiente()` para no mezclar cálculo por línea con agregado dentro de la misma nota).
- Q: ¿Dónde se ve el estado de ARCA (enviado/aprobado/rechazado) de una Venta? → A: Hoy el listado de Ventas ya tiene un filtro/columna de "Estado de Factura" (sin_emitir/pendiente/aprobado/rechazado), pero el Detalle de Venta no muestra ese estado de forma clara — sólo un tooltip suelto en un ícono. Se agrega como requisito de esta spec (US4) mostrar el estado de ARCA también en el Detalle de Venta, y el mismo criterio en el Detalle/vista de NC/ND para su propio CAE.

## Contexto (por qué existe esta spec)

Dos defectos separados conviven hoy en la emisión de CAE de Notas de Crédito/Débito
(`NotaCreditoDebitoController::emitirComprobanteFiscalNota()`):

1. **Envío automático no deseado.** Al crear una NC/ND, si la Venta/Compra original ya tiene un
   `ComprobanteFiscal` aprobado, el sistema solicita el CAE a ARCA en la misma transacción de creación,
   sin ninguna confirmación del usuario. Es el mismo defecto que la spec 040 ya identificó y corrigió
   para Ventas (incidente real del 04/08/2026 contra ARCA producción) — spec 040 dejó **expresamente
   fuera de su alcance** la emisión de NC/ND (`FR-010`: "La emisión de CAE para Notas de Crédito/Débito
   ... sigue disparándose desde su propio flujo existente, fuera de alcance de esta spec"), quedando
   pendiente hasta ahora.
2. **Cálculo de IVA simplificado e inexacto.** El payload que se arma para ARCA calcula el neto y el
   IVA de toda la nota dividiendo el monto total por 1.21 (`round($nota->monto / 1.21, 2)`),
   asumiendo el 21% para toda la nota — sin importar la alícuota real de los productos involucrados
   (0%, 2.5%, 5%, 10.5%, 21%, 27%) ni si la nota mezcla varias alícuotas. Esto puede llevar a ARCA un
   ImpNeto/ImpIVA que no coincide con las líneas reales de la nota, con riesgo de rechazo o de un
   comprobante fiscal con montos incorrectos. La spec 096 (línea independiente en NC/ND) ya dejó
   disponible, en cada `NotaCreditoDebitoItem`, la referencia a la línea de origen
   (`venta_item_id`/`compra_item_id`) con su neto e IVA reales — dato que `MapeadorComprobante` ya sabe
   consumir (`armarBloquesAlicIva()` acepta un array `items` y agrupa por alícuota, contrato ya usado
   por Venta/Compra) pero que `emitirComprobanteFiscalNota()` nunca le pasa.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enviar una NC/ND a ARCA manualmente (Priority: P1) 🎯 MVP

Como usuario que gestiona la facturación electrónica del negocio, quiero decidir yo mismo, nota por
nota, cuándo una Nota de Crédito/Débito se envía a ARCA para solicitar su propio CAE — en vez de que el
sistema lo haga solo al crearla — para no arriesgar envíos reales no deseados a un ente fiscal, igual
que ya corrige la spec 040 para Ventas.

**Why this priority**: es la misma corrección de fondo que motivó la spec 040, aplicada al caso que esa
spec dejó pendiente. Mientras no se corrija, cualquier NC/ND creada sobre una Venta/Compra con CAE
aprobado dispara sola un envío real a ARCA.

**Independent Test**: crear una Nota de Crédito sobre una Venta con `ComprobanteFiscal` aprobado y
verificar que la nota queda creada sin CAE ni intento de envío; la fila/detalle de la nota ofrece la
acción "Enviar a ARCA"; ejecutarla y confirmar que recién ahí se solicita el CAE.

**Acceptance Scenarios**:

1. **Given** una Venta o Compra con `ComprobanteFiscal` aprobado, **When** el usuario crea una Nota de
   Crédito o Débito sobre ella, **Then** la nota queda creada sin `ComprobanteFiscal` propio y sin que
   se haya contactado a ARCA.
2. **Given** una NC/ND recién creada (Tipo de Comprobante A/B/C, sin `ComprobanteFiscal` propio
   aprobado) sobre un comprobante original con CAE aprobado, **When** el usuario mira el listado/detalle
   de esa NC/ND, **Then** ve disponible la acción "Enviar a ARCA".
3. **Given** esa NC/ND, **When** el usuario ejecuta "Enviar a ARCA" y confirma, **Then** el sistema
   solicita el CAE (mismo servicio `EmisorComprobante::emitir()` ya existente) y muestra un modal con el
   resultado — CAE y vencimiento si fue aprobado, o el motivo exacto del rechazo/falla si no — que
   permanece visible hasta que el usuario lo cierra, actualizando la fila/vista sin recargar la página.
4. **Given** una NC/ND cuyo comprobante original (Venta/Compra) todavía **no** tiene `ComprobanteFiscal`
   aprobado, **When** el usuario mira el listado/detalle, **Then** la acción "Enviar a ARCA" no está
   disponible para esa nota (no se puede referenciar un comprobante asociado sin CAE).
5. **Given** una NC/ND que ya tiene su propio `ComprobanteFiscal` aprobado, **When** el usuario mira el
   listado/detalle, **Then** la acción "Enviar a ARCA" no está disponible (ya fue enviada).
6. **Given** una NC/ND sobre una Compra (no Venta) en la misma situación que el escenario 2, **When** el
   usuario la mira, **Then** el mismo comportamiento aplica — paridad Ventas/Compras.

---

### User Story 2 - El envío a ARCA usa el IVA real de cada línea (Priority: P1)

Como usuario responsable de que la facturación electrónica sea fiscalmente correcta, quiero que al
enviar una Nota de Crédito/Débito a ARCA el neto y el IVA informados reflejen las alícuotas reales de
los productos ajustados — no un 21% fijo — para que el comprobante fiscal sea exacto incluso cuando la
nota incluye productos con IVA distinto de 21% o mezcla varias alícuotas.

**Why this priority**: es un requisito de exactitud fiscal, igual de crítico que US1 — un envío manual
que sigue mandando un IVA mal calculado no resuelve el problema de fondo, sólo lo pospone hasta que el
usuario aprieta el botón.

**Independent Test**: crear una Nota de Crédito sobre una Venta con líneas de distinta alícuota de IVA
(por ejemplo, un producto al 21% y otro al 10.5%), enviarla a ARCA, y verificar que el payload informado
desglosa ambas alícuotas con su neto/IVA reales — no un único bloque al 21% sobre el total.

**Acceptance Scenarios**:

1. **Given** una NC/ND cuyos ítems tienen referencia a su línea de origen (`venta_item_id` o
   `compra_item_id`, spec 096) con distintas alícuotas de IVA, **When** el usuario ejecuta "Enviar a
   ARCA", **Then** el sistema informa a ARCA un bloque `AlicIva` por cada alícuota presente, con el neto
   e IVA reales de esa alícuota — no un cálculo único al 21% sobre el monto total.
2. **Given** una NC/ND cuyos ítems son todos de la misma alícuota, **When** se envía a ARCA, **Then** el
   neto/IVA informados coinciden con la suma real de esa alícuota (mismo resultado numérico que hoy
   *sólo* si esa alícuota es 21%; distinto y correcto si es otra).
3. **Given** una NC/ND vieja (creada antes de la spec 096) sin referencia a línea de origen en sus
   ítems, **When** se envía a ARCA, **Then** el sistema no puede desglosar por alícuota real y usa el
   comportamiento de fallback ya existente (un único bloque, alícuota informada explícitamente en la
   nota si está disponible, o 21% como último recurso) — sin romper el envío.
3a. **Given** una NC/ND con ítems **mixtos** (algunos con `venta_item_id`/`compra_item_id`, otros sin
   esa referencia), **When** se envía a ARCA, **Then** el sistema NO combina ambos criterios dentro de
   la misma nota — la nota completa usa el fallback agregado (bloque único) hasta que **todos** sus
   ítems tengan línea de origen.
4. **Given** una NC/ND sin ítems propios (nota "global", sin desglose de productos), **When** se envía a
   ARCA, **Then** se mantiene el comportamiento actual de bloque único (sin regresión sobre notas que
   nunca tuvieron ítems).

---

### User Story 3 - Corregir la documentación (Priority: P3)

Como responsable del proyecto, quiero que `docs/documentacion_principal_crm.md` refleje que el envío de
NC/ND a ARCA también es manual y con IVA real por línea, referenciando esta spec junto a la 040 y la
096, para que una futura sesión no vuelva a asumir el comportamiento automático o el cálculo simplificado
como intencionales.

**Why this priority**: no bloquea el uso del sistema, pero mantiene la documentación de dominio como
fuente de verdad consistente con el código.

**Independent Test**: leer `docs/documentacion_principal_crm.md` en la sección de Facturación
Electrónica / NC-ND y confirmar que describe el envío como acción manual con IVA real por línea,
referenciando esta spec.

**Acceptance Scenarios**:

1. **Given** `docs/documentacion_principal_crm.md`, **When** se revisa la sección de Facturación
   Electrónica que describe NC/ND, **Then** el texto ya no dice que el CAE se solicita automáticamente
   al crear la nota, describe la acción manual "Enviar a ARCA" y el cálculo de IVA por línea real, y
   referencia esta spec (097) junto a la 040 y la 096.

---

### User Story 4 - Ver el estado de ARCA en el Detalle de Venta y de NC/ND (Priority: P2)

Como usuario que gestiona la facturación electrónica, quiero ver claramente, al entrar al Detalle de una
Venta o de una NC/ND, si ese comprobante fue enviado a ARCA y qué resultado tuvo (sin enviar, pendiente,
aprobado con CAE, rechazado) — hoy el listado de Ventas ya tiene esa información en su filtro/columna
"Estado de Factura", pero el Detalle de Venta no la muestra de forma clara (sólo un tooltip suelto en un
ícono), y el Detalle/PDF de NC/ND tampoco distingue su propio estado de envío del estado del comprobante
original que ajusta.

**Why this priority**: no bloquea el envío en sí (US1/US2 ya cubren esa acción), pero es la referencia
que el usuario necesita para decidir si corresponde ejecutar "Enviar a ARCA" o si la nota ya fue
enviada — sin esto, la nueva acción manual queda sin contexto visible en la pantalla donde el usuario la
va a usar.

**Independent Test**: abrir el Detalle de una Venta con `ComprobanteFiscal` aprobado y verificar que se
ve un indicador de estado (aprobado, con CAE) sin necesidad de volver al listado; abrir el Detalle de una
NC/ND sin enviar y verificar que se distingue su propio estado ("Sin enviar") del estado del comprobante
original que ajusta (que sí puede estar aprobado).

**Acceptance Scenarios**:

1. **Given** una Venta con `ComprobanteFiscal` propio en estado `aprobado`, **When** el usuario abre su
   Detalle, **Then** ve un indicador de estado ARCA con el valor "Aprobado" (con CAE), sin tener que
   volver al listado ni pasar el mouse sobre un ícono para enterarse.
2. **Given** una Venta con `ComprobanteFiscal` en estado `rechazado`, **When** el usuario abre su
   Detalle, **Then** ve el indicador con el valor "Rechazado", con el motivo del último rechazo visible
   (mismo dato que ya devuelve `EmisorComprobante`/`ArcaRechazoException`).
3. **Given** una Venta sin ningún `ComprobanteFiscal` (nunca enviada), **When** el usuario abre su
   Detalle, **Then** ve el indicador con el valor "Sin enviar".
4. **Given** una NC/ND cuyo propio envío a ARCA (US1) todavía no se ejecutó, **When** el usuario abre su
   Detalle/Ver Detalle, **Then** ve su propio indicador de estado ARCA ("Sin enviar", distinto del estado
   del comprobante original que ajusta) — evita la confusión de leer el estado de la Venta/Compra como si
   fuera el de la nota.
5. **Given** una NC/ND ya enviada y aprobada, **When** el usuario abre su Detalle/Ver Detalle, **Then**
   ve su propio CAE y vencimiento, igual que ya lo muestra su PDF (spec 039).

---

### Edge Cases

- ¿Qué pasa si el usuario ejecuta "Enviar a ARCA" dos veces muy rápido sobre la misma nota (doble
  click)? El sistema no debe generar dos solicitudes ni dos `ComprobanteFiscal` para la misma nota —
  mismo resguardo que ya aplica en el servicio de emisión existente (usado también por Venta).
- ¿Qué pasa si no hay certificado fiscal configurado, o la Función Avanzada "Facturación Electrónica"
  está desactivada? Es un rechazo de precondición (nunca se llega a contactar a ARCA) — se informa por
  **toast**, no por el modal de resultado (igual que en spec 040 para Ventas).
- ¿Qué pasa si el comprobante original (Venta/Compra) todavía no tiene CAE aprobado cuando el usuario
  intenta enviar la NC/ND? Es un rechazo de precondición — no se puede armar el `CbtesAsoc` sin un
  comprobante asociado válido; se informa por toast, sin intentar el envío.
- ¿Qué pasa si una NC/ND mezcla ítems con `venta_item_id`/`compra_item_id` (línea real) e ítems sin esa
  referencia (nota vieja o "nuevo" agregado a mano, ver `atributosItemNota()`)? El desglose por alícuota
  se arma con la alícuota real de cada ítem cuando esté disponible; para un ítem sin alícuota propia
  identificable se usa el mismo criterio de fallback que hoy.
- ¿Qué pasa con una NC/ND ya enviada y aprobada — se puede reenviar o editar? Fuera de alcance de esta
  spec (mismo criterio que spec 040 dejó fuera para Venta: inmutabilidad post-CAE no se toca acá).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema NO DEBE solicitar el CAE a ARCA automáticamente al crear una Nota de
  Crédito/Débito (corrige el comportamiento actual de `NotaCreditoDebitoController::store()` /
  `storeCompra()`).
- **FR-002**: El sistema DEBE ofrecer una acción manual "Enviar a ARCA" por cada NC/ND, para solicitar
  su CAE bajo decisión explícita del usuario — con el mismo patrón de interacción que la acción
  homónima de Ventas (spec 040): confirmación previa, modal de resultado persistente, toast sólo para
  rechazos de precondición.
- **FR-003**: La acción "Enviar a ARCA" DEBE estar disponible únicamente para NC/ND con Tipo de
  Comprobante A, B o C que todavía no tengan un `ComprobanteFiscal` propio en estado `aprobado`, **y**
  cuyo comprobante original (Venta o Compra) sí tenga un `ComprobanteFiscal` en estado `aprobado`.
- **FR-004**: Antes de ejecutar el envío, el sistema DEBE pedir confirmación explícita del usuario (es
  una acción real contra un ente fiscal, no reversible) — igual que FR-005 de spec 040.
- **FR-005**: Al confirmar, el sistema DEBE reutilizar el servicio de emisión existente
  (`EmisorComprobante::emitir()`) sin modificar su lógica interna (WSAA/WSFEv1, reconciliación ante
  timeout, etc.) — igual que FR-006 de spec 040.
- **FR-006**: El resultado de un intento real de emisión contra ARCA (aprobado con CAE, o
  rechazado/falla con su motivo) DEBE comunicarse en un modal que permanece visible hasta que el usuario
  lo cierra, mostrando CAE y vencimiento si fue aprobado o el motivo exacto si no. Cerrado el modal, la
  vista de la NC/ND DEBE reflejar el nuevo estado sin recargar la página completa.
- **FR-006a**: Un rechazo de **precondición** (nota no elegible, comprobante original sin CAE propio,
  Función Avanzada desactivada, certificado no configurado) DEBE comunicarse por **toast**, no por el
  modal de FR-006.
- **FR-007**: Si la Función Avanzada "Facturación Electrónica" está desactivada, el sistema NO DEBE
  ofrecer ni ejecutar la acción "Enviar a ARCA" para ninguna NC/ND.
- **FR-008**: La acción "Enviar a ARCA" DEBE estar protegida por el mismo permiso que ya controla el
  acceso a NC/ND de Venta o de Compra respectivamente (sin crear un permiso nuevo).
- **FR-009**: Al armar el payload de emisión, el sistema DEBE calcular el neto y el IVA a partir de los
  ítems reales de la NC/ND (agrupados por alícuota real vía `venta_item_id`/`compra_item_id`, spec 096)
  en vez de un cálculo fijo al 21% sobre el monto total.
- **FR-010**: Cuando una NC/ND no tenga ítems con referencia a línea de origen disponible (notas
  creadas antes de la spec 096, o sin ítems propios), el sistema DEBE mantener el comportamiento de
  fallback actual (bloque único de IVA) sin romper el envío.
- **FR-010a**: Cuando una NC/ND tenga ítems **mixtos** (algunos con línea de origen, otros sin ella), el
  sistema NO DEBE combinar el cálculo por línea con el agregado dentro de la misma nota — DEBE aplicar
  el fallback agregado (FR-010) a la nota completa mientras exista al menos un ítem sin línea de origen
  (mismo criterio dual que `AjustesPendientesNotaCreditoDebito::pendiente()`, spec 096).
- **FR-013**: La acción "Enviar a ARCA" para NC/ND DEBE usar modales propios e independientes de los ya
  existentes para Venta (spec 040) — no reutilizar `#modal-confirmar-arca` / `#modal-resultado-arca`.
- **FR-011**: El comportamiento de esta spec (envío manual + IVA real por línea) DEBE aplicar por igual
  a NC/ND de Venta y de Compra (paridad, mismo criterio que spec 096 aplicó a `itemsDisponibles()`).
- **FR-012**: La documentación (`docs/documentacion_principal_crm.md`) DEBE actualizarse para reflejar
  el envío manual de NC/ND y el cálculo de IVA real por línea, referenciando esta spec junto a la 040 y
  la 096.
- **FR-014**: El Detalle de Venta DEBE mostrar un indicador claro y siempre visible (no sólo un tooltip)
  del estado de ARCA de la Venta: "Sin enviar", "Pendiente", "Aprobado" (con CAE) o "Rechazado" (con el
  motivo del último intento), derivado de su relación `comprobanteFiscal` existente.
- **FR-015**: El Detalle/Ver Detalle de una NC/ND DEBE mostrar su **propio** indicador de estado de ARCA
  (mismos valores que FR-014, referido al `ComprobanteFiscal` propio de la nota, no al de la Venta/Compra
  que ajusta), distinguible del estado del comprobante original.
- **FR-016**: Cuando la NC/ND está aprobada (con CAE propio), el indicador de FR-015 DEBE mostrar CAE y
  vencimiento — mismo dato que ya expone su PDF (spec 039) — sin obligar al usuario a abrir el PDF sólo
  para confirmar que fue enviada.

### Key Entities *(include if feature involves data)*

- **NotaCreditoDebito**: entidad existente, sin cambios de esquema. Su relación `comprobanteFiscal`
  (existente, mismo patrón `morphOne` ordenado que Venta) determina si la acción "Enviar a ARCA" está
  disponible y qué muestra el indicador de estado (FR-015).
- **NotaCreditoDebitoItem**: entidad existente (spec 096) — su `venta_item_id`/`compra_item_id`, `neto`
  e `iva_pct` (o los del ítem de origen cuando no estén propios) son la fuente para el desglose real de
  IVA hacia ARCA.
- **ComprobanteFiscal**: entidad existente, sin cambios de esquema — se sigue creando/actualizando
  exclusivamente vía `EmisorComprobante::emitir()`, ahora invocado desde la nueva acción manual; también
  es la fuente del indicador de estado de FR-014/FR-015.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ninguna NC/ND solicita CAE a ARCA sin que un usuario haya ejecutado explícitamente
  "Enviar a ARCA" sobre esa nota (0 envíos automáticos, verificable contra `arca_logs_auditoria`).
- **SC-002**: Un usuario puede enviar una NC/ND puntual a ARCA en menos de 3 clicks (acción → confirmar).
- **SC-003**: Para una NC/ND con productos de distinta alícuota de IVA, el neto/IVA informado a ARCA
  coincide, alícuota por alícuota, con la suma real de las líneas afectadas — 0 casos de IVA calculado
  como 21% fijo cuando la nota incluye alícuotas distintas.
- **SC-004**: La documentación de dominio ya no describe el envío de NC/ND como automático ni el IVA
  como fijo al 21%.
- **SC-005**: Un usuario puede determinar el estado de ARCA de una Venta o de una NC/ND (sin enviar,
  pendiente, aprobado, rechazado) abriendo únicamente su Detalle, sin volver al listado ni depender de un
  tooltip.

## Assumptions

- El servicio `EmisorComprobante::emitir()` y `MapeadorComprobante::armarBloquesAlicIva()` (que ya
  soporta recibir `items` y agruparlos por alícuota, usado hoy por Venta/Compra) se reutilizan tal cual
  están — esta spec sólo cambia quién/cuándo los invoca y qué datos le pasa `emitirComprobanteFiscalNota()`.
- La acción "Enviar a ARCA" para NC/ND vive donde estructuralmente corresponda dentro de la pantalla de
  NC/ND ya existente (listado y/o detalle de Venta/Compra) — calcando el patrón visual/interactivo ya
  construido para Ventas en spec 040 (menú de fila o acción equivalente), con modales propios de NC/ND
  (ver Clarifications).
- No se requiere envío en lote — mismo criterio que spec 040 confirmó para Ventas.
- Esta spec no resuelve la inmutabilidad de una NC/ND una vez que tiene CAE aprobado — se asume el mismo
  criterio que ya exista o no para Venta (fuera de alcance, igual que spec 040 lo dejó fuera).
- El "fallback" de IVA para notas sin ítems con línea de origen (FR-010) reutiliza el mismo criterio que
  `MapeadorComprobante::armarBloquesAlicIva()` ya implementa hoy cuando `items` viene vacío
  (`alicuota_iva_id` explícito o 21% por defecto) — no se inventa un tercer criterio nuevo.
