# Feature Specification: Lista de Precios en la configuración de Mercado Libre

**Feature Branch**: `016-lista-precio-mercadolibre`

**Created**: 2026-07-29

**Status**: Draft

**Input**: User description: "Agregar a la configuración de Mercado Libre una Lista de Precios, análoga
al Depósito y a la Categoría de Venta ya configurables, para que las Ventas creadas al convertir una
orden de Mercado Libre queden etiquetadas con una Lista de Precios (igual que el resto de las Ventas del
CRM), sin que esto cambie en absoluto cómo se calcula el precio de esas Ventas — el precio siempre sale
del importe real pagado en Mercado Libre, nunca de una lista de precios."

## Contexto y fuentes

`ml_configuracion` (specs 011/012/013) ya tiene dos campos de clasificación configurables que se aplican
a toda Venta creada al convertir una orden de Mercado Libre: `deposito_id` (depósito del que se
descuenta/publica stock, con reserva "depósito por defecto del CRM" si no se elige ninguno —
`Deposito::porDefecto()`) y `categoria_venta_id` (categoría que se asigna a la Venta, sin reserva —
queda `null` si no se configura). Falta el análogo para Lista de Precios: hoy toda Venta convertida desde
Mercado Libre queda con `lista_precio_id = null`, porque no hay ningún campo en `ml_configuracion` para
configurarla, a diferencia del resto de las Ventas del CRM (spec 008), donde ese campo existe y se
autocompleta desde el Cliente cuando tiene una lista asignada.

**Aclaración de alcance crítica**: en ningún flujo de Mercado Libre el precio de una línea de Venta se
calcula ni se deriva de `listas_precio`/`precios_producto`. `ConversorOrdenAVenta::armarLineas()`
desagrega el IVA directamente del importe total que pagó el comprador en Mercado Libre — ese cálculo no
se toca. Esta spec agrega **únicamente** un campo de clasificación/etiquetado, con el mismo rol que ya
cumple `categoria_venta_id`: sirve para que la Venta quede consistente con el resto del sistema a efectos
de informes y filtros por Lista de Precios, no para fijar ningún precio.

**Fuentes de dominio**: `docs/documentacion_principal_crm.md` §3.2.bis y §5.2 (Mercado Libre), §3
(Ventas/Presupuestos — bloque Cliente/Categoría/Lista de Precios); `docs/modelo_datos.md` (tablas
`ml_configuracion`, `listas_precio`, `ventas`); `specs/012-ventas-mercadolibre/spec.md` (origen de
`categoria_venta_id`, mismo patrón que esta spec replica para Lista de Precios).

## Alcance

**Incluye**: un campo "Lista de Precios" configurable en la pantalla Configuración → Integraciones →
Mercado Libre, en la misma sección donde ya se configuran Depósito y Categoría de Venta; al convertir una
orden de Mercado Libre en Venta (manual o automática), la Venta resultante queda etiquetada con la Lista
de Precios configurada, sin que el precio de ninguna línea cambie.

**Excluye explícitamente**:

- Cualquier cambio al cálculo del precio de las líneas de una Venta de Mercado Libre: sigue saliendo
  100% del importe pagado en la orden.
- Sincronización de precios **hacia** Mercado Libre (actualizar el precio de una publicación desde una
  lista de precios del CRM): es un feature distinto, no pedido, y no forma parte de esta spec.
- Un concepto nuevo de "Lista de Precios por defecto del CRM" a nivel global: a diferencia del Depósito
  (indispensable para descontar stock, por eso tiene reserva `Deposito::porDefecto()`), la Lista de
  Precios ya es opcional en el resto del sistema (Ventas/Presupuestos quedan sin ella si el Cliente no
  tiene una asignada). Esta spec no inventa un default global nuevo: si no se configura nada en Mercado
  Libre, la Venta convertida sigue quedando con `lista_precio_id = null`, igual que hoy.
- Autocompletar la Lista de Precios desde el Cliente de la orden de Mercado Libre (como sí ocurre en
  Ventas manuales, spec 008): la fuente es siempre la configuración de Mercado Libre, nunca el Cliente,
  porque el Cliente de una orden de Mercado Libre se resuelve/crea automáticamente (spec 012) y no tiene
  por qué tener una Lista de Precios propia cargada.

## Clarifications

### Session 2026-07-29

- Q: ¿La Lista de Precios configurada debe influir en el precio de las líneas de la Venta convertida? →
  A: No, en ningún caso. El precio sigue saliendo exclusivamente del importe pagado en Mercado Libre
  (confirmado con el usuario antes de especificar). Esta spec es puramente de clasificación.
- Q: ¿Debe existir una reserva "Lista de Precios por defecto del CRM" si no se configura ninguna, como
  pasa con el Depósito? → A: No. La Lista de Precios no es indispensable para ninguna operación (a
  diferencia del Depósito, que sí lo es para descontar stock); se mantiene el mismo comportamiento
  opcional que ya tiene en el resto del sistema. Si no se configura, la Venta queda sin Lista de Precios,
  igual que hoy.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Configurar la Lista de Precios de Mercado Libre (Priority: P1)

Como responsable del negocio, quiero poder elegir, desde la pantalla de configuración de Mercado Libre,
qué Lista de Precios se le va a asignar a las Ventas que se generen al convertir órdenes de Mercado
Libre, igual que ya puedo elegir el Depósito y la Categoría de Venta.

**Why this priority**: sin este campo no hay nada que asignar — es el prerrequisito de la historia 2.

**Independent Test**: se puede probar entrando a Configuración → Integraciones → Mercado Libre,
seleccionando una Lista de Precios activa en el nuevo campo, guardando, y verificando que la selección
persiste al recargar la pantalla.

**Acceptance Scenarios**:

1. **Given** la pantalla de configuración de Mercado Libre, **When** el usuario abre el selector de Lista
   de Precios, **Then** ve listadas las Listas de Precios activas del CRM.
2. **Given** una Lista de Precios seleccionada, **When** el usuario guarda la configuración, **Then** el
   sistema confirma el guardado por notificación, sin recargar la página, y la selección queda persistida.
3. **Given** ninguna Lista de Precios seleccionada (campo vacío), **When** el usuario guarda, **Then** el
   sistema lo permite sin error — el campo es opcional.

---

### User Story 2 - Que la Venta convertida quede etiquetada con esa Lista de Precios (Priority: P1)

Como responsable del negocio, cuando convierto una orden de Mercado Libre en Venta, quiero que esa Venta
quede clasificada bajo la Lista de Precios que configuré, para poder filtrarla/informarla junto con el
resto de mis Ventas por Lista de Precios, sin que eso altere ningún precio.

**Why this priority**: es el motivo de ser de esta spec — sin esto, el campo configurado en la historia 1
no tendría ningún efecto.

**Independent Test**: se puede probar configurando una Lista de Precios (historia 1), convirtiendo una
orden de Mercado Libre en Venta, y verificando que la Venta creada tiene esa Lista de Precios asignada y
que el total/los precios de sus líneas no cambiaron respecto de lo que ya calculaba el sistema antes de
esta spec.

**Acceptance Scenarios**:

1. **Given** una Lista de Precios configurada en Mercado Libre, **When** se convierte una orden de
   Mercado Libre en Venta (manual o automática), **Then** la Venta creada queda con esa Lista de Precios
   asignada.
2. **Given** esa misma conversión, **When** se revisan los precios de las líneas de la Venta, **Then**
   siguen saliendo exclusivamente del importe pagado en la orden de Mercado Libre, sin ninguna diferencia
   respecto del cálculo ya existente.
3. **Given** ninguna Lista de Precios configurada en Mercado Libre, **When** se convierte una orden,
   **Then** la Venta se crea igual que hoy, sin Lista de Precios asignada, sin bloquear la conversión.
4. **Given** se cambia la Lista de Precios configurada en Mercado Libre, **When** se convierte una nueva
   orden, **Then** la Venta usa la Lista de Precios vigente al momento de la conversión; las Ventas ya
   convertidas anteriormente no se modifican retroactivamente.

---

### Edge Cases

- **Lista de Precios configurada que luego se desactiva**: la configuración conserva la referencia; el
  comportamiento de la conversión no se bloquea (mismo criterio ya usado hoy con `categoria_venta_id`, que
  tampoco valida "activa" al momento de convertir). No forma parte del alcance endurecer esta validación.
- **Lista de Precios configurada que luego se elimina**: si el borrado de Listas de Precios en el CRM ya
  impide eliminar una en uso (fuera de esta spec, comportamiento existente del módulo de Listas de
  Precios), ese resguardo cubre también este uso; si no existiera tal resguardo, queda documentado como
  brecha preexistente ajena a esta spec, no algo que esta spec deba resolver.
- **Conversión automática** (creación automática de Ventas desde órdenes, spec 012): se comporta
  exactamente igual que la conversión manual respecto de este campo — no hay lógica distinta entre ambos
  caminos, porque ambos pasan por el mismo `ConversorOrdenAVenta`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE permitir configurar, desde la pantalla de configuración de Mercado Libre,
  una Lista de Precios entre las activas del CRM.
- **FR-002**: El campo Lista de Precios de la configuración de Mercado Libre DEBE ser opcional: el
  sistema DEBE permitir guardar la configuración sin ninguna Lista de Precios seleccionada.
- **FR-003**: El sistema DEBE asignar a toda Venta creada al convertir una orden de Mercado Libre la
  Lista de Precios vigente en la configuración de Mercado Libre al momento de la conversión (el valor
  leído al inicio de esa conversión puntual, sin volver a consultarlo si la configuración cambia
  mientras esa conversión está en curso). Esto aplica **sin distinción** entre conversión manual y
  conversión automática (spec 012): ambos caminos DEBEN producir exactamente el mismo resultado para
  este campo, porque ambos ejecutan el mismo proceso de conversión.
- **FR-004**: Si la configuración de Mercado Libre no tiene ninguna Lista de Precios seleccionada, el
  sistema DEBE crear la Venta sin Lista de Precios asignada, igual que el comportamiento actual, sin
  bloquear ni degradar la conversión. Aplica igual en conversión manual y automática (FR-003).
- **FR-005**: El sistema NO DEBE usar la Lista de Precios configurada (ni ninguna otra) para calcular o
  modificar el precio unitario, el subtotal ni el total de ninguna línea o de la Venta convertida desde
  Mercado Libre, en ningún escenario (conversión manual, automática, con o sin Lista de Precios
  configurada): esos valores siguen derivándose exclusivamente del importe pagado en la orden de
  Mercado Libre, exactamente igual que antes de esta spec.
- **FR-006**: El sistema NO DEBE modificar Ventas ya convertidas cuando se cambia la Lista de Precios
  configurada en Mercado Libre con posterioridad: el cambio sólo aplica hacia adelante, a conversiones
  que ocurran después del cambio.
- **FR-007**: El sistema DEBE rechazar el guardado de la configuración de Mercado Libre si el valor
  enviado para Lista de Precios no corresponde a ninguna Lista de Precios existente del CRM, informando
  el error sin guardar el resto de la configuración.
- **FR-008**: El sistema NO DEBE utilizar la Lista de Precios configurada aquí (ni ninguna otra) para
  sincronizar, actualizar o publicar precios de publicaciones en Mercado Libre: esta configuración sólo
  clasifica Ventas ya creadas en el CRM, nunca escribe hacia Mercado Libre.

### Key Entities

- **Configuración de Mercado Libre** (`ml_configuracion`, ya existente): se le agrega el atributo Lista
  de Precios (referencia opcional a una Lista de Precios del CRM), con el mismo rol que ya cumplen
  Depósito y Categoría de Venta. No es una entidad nueva.
- **Venta** (ya existente, spec 008): su atributo Lista de Precios, ya definido para el resto del CRM,
  pasa a completarse también en las Ventas de origen Mercado Libre.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El usuario puede configurar una Lista de Precios para Mercado Libre y confirmar que quedó
  guardada en menos de 30 segundos, sin recargar la página.
- **SC-002**: El 100% de las Ventas creadas al convertir órdenes de Mercado Libre, con una Lista de
  Precios configurada al momento de la conversión, queda con esa Lista de Precios asignada.
- **SC-003**: El 100% de las Ventas creadas al convertir órdenes de Mercado Libre mantiene precios de
  línea idénticos a los que el sistema ya calculaba antes de esta spec (ningún caso de precio alterado
  por la Lista de Precios).
- **SC-004**: Con la configuración sin Lista de Precios seleccionada, el 100% de las conversiones se
  completa sin error, igual que hoy.
- **SC-005**: Al cambiar la Lista de Precios configurada, el 100% de las Ventas convertidas **antes** del
  cambio conserva la Lista de Precios que tenía asignada en el momento de su propia conversión (ninguna
  queda re-etiquetada retroactivamente).

## Assumptions

- **Sin fallback global**: no se crea un "Lista de Precios por defecto del CRM" (ver Clarifications) —
  queda fuera del alcance de esta spec introducir ese concepto a nivel general.
- **Fuente única**: la Lista de Precios de la Venta convertida sale siempre de `ml_configuracion`, nunca
  del Cliente de la orden ni de ningún otro origen.
- **Sin validación de "activa" al convertir**: se sigue el mismo criterio ya vigente para
  `categoria_venta_id` — la configuración no revalida en cada conversión que la Lista de Precios elegida
  siga activa.
- **Una sola cuenta de Mercado Libre**: se mantiene el supuesto single-tenant ya vigente desde la spec
  011.

## Dependencies

- **Interna — spec 012 (implementada)**: `ml_configuracion`, `ConversorOrdenAVenta`, pantalla de
  configuración de Mercado Libre, sobre las que esta spec agrega un campo más, mismo patrón que
  `categoria_venta_id`.
- **Interna — spec 008 (implementada)**: Ventas y su atributo Lista de Precios ya definido.
- **Interna**: módulo de Listas de Precios (`listas_precio`, `ListaPrecio`), ya existente.

## Restricciones de diseño y entorno

- **Especificaciones de diseño obligatorias del proyecto** (`CLAUDE.md`): el selector de Lista de Precios
  usa Select2 (regla obligatoria para selects de datos dinámicos), dentro del mismo formulario AJAX sin
  recarga de página y notificación toast ya usado por Depósito y Categoría de Venta en esta misma
  pantalla.
- **Idioma del dominio**: nombres de campos y textos de interfaz en español, consistentes con "Lista de
  Precios" tal como aparece en Ventas/Presupuestos.
- **Testing**: por el principio IV de la constitución, FR-003 (asignación al convertir, manual y
  automática), FR-004 (comportamiento sin configurar), FR-005 (no alterar precios) y FR-006 (no
  retroactividad) requieren tests obligatorios — FR-005 en particular, por ser la garantía central de
  que esta spec no introduce una regresión de cálculo.

## Impacto en la documentación de dominio

Conforme al principio I de la constitución, esta spec introduce contenido que debe reflejarse en la
documentación de dominio **antes de pasar a `/speckit-tasks`**:

1. `docs/documentacion_principal_crm.md`:
   - Actualizar §3.2.bis/§5.2 (Mercado Libre) para documentar el nuevo campo Lista de Precios en la
     configuración, análogo a Depósito y Categoría de Venta, aclarando explícitamente que no afecta el
     cálculo de precios.
2. `docs/modelo_datos.md`:
   - Agregar `lista_precio_id` (FK → `listas_precio`, nullable) a la tabla `ml_configuracion`.
