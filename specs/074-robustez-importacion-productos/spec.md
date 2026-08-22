# Feature Specification: Robustez del importador de Productos (stock concurrente y auditoría de precios)

**Feature Branch**: `074-robustez-importacion-productos`

**Created**: 2026-08-22

**Status**: Draft

**Input**: User description: "Correcciones de robustez del importador oficial de Productos (asistente «Importar Datos» del CRM) para el flujo real de edición masiva vía Excel: el usuario exporta la planilla de Productos desde el CRM, le aplica fórmulas (ej. aumento porcentual de precios de una lista) o edita stock, y la reimporta mapeando la columna «Id» para que se resuelva como actualización. Dos gaps a corregir: (1) el ajuste de stock por importación lee el stock actual fuera de la transacción que lo escribe, así que una venta concurrente puede pisarse; (2) los cambios de precio por lista no dejan ningún rastro del valor anterior, ni por importación ni por edición manual."

## Contexto del problema

El negocio actualiza precios y stock en masa mediante el circuito **exportar → editar en Excel → reimportar**:
el usuario descarga la planilla de Productos desde el listado, le aplica una fórmula (típicamente un
aumento porcentual sobre una lista de precios) o corrige cantidades de stock, y vuelve a subir el mismo
archivo mapeando la columna **Id**, lo que hace que el importador resuelva cada fila como *actualización*
del producto existente en lugar de crear duplicados.

Ese circuito hoy funciona en el caso feliz, pero tiene dos puntos ciegos que pueden producir descuadres
difíciles de rastrear:

1. **El ajuste de stock por importación no es atómico.** Al actualizar un producto, el importador lee el
   stock actual del depósito, calcula la diferencia contra la cantidad que trae la planilla, y recién
   después aplica ese ajuste. La lectura y la escritura ocurren en momentos distintos: si entre ambas
   otra operación mueve el stock de ese producto/depósito (una venta en el mostrador, una compra, otro
   ajuste), la diferencia calculada quedó vieja y ese movimiento concurrente se pisa — se pierde o se
   duplica en la cantidad final. La ventana no es teórica: una importación grande se procesa en tandas
   sucesivas y puede tardar minutos con el negocio operando en simultáneo.

2. **Los cambios de precio no dejan rastro.** El precio de un producto en una lista se sobrescribe
   directamente, sin registrar en ningún lado cuál era el valor anterior. Esto vale tanto para el camino
   de importación como para la edición manual desde la ficha del producto. Si una fórmula de Excel sale
   mal (una referencia corrida, un redondeo, una columna mal mapeada) y el error se detecta días después,
   no hay forma de reconstruir qué precio tenía cada producto antes del cambio.

## Clarifications

### Session 2026-08-22

Las preguntas de alcance se resolvieron con el usuario **antes** de redactar la spec (atomicidad del
stock bajo bloqueo; consulta vía la pantalla de Auditoría existente sin sub-vista nueva en la ficha del
producto; cobertura de importación **y** edición manual). Las siguientes se resolvieron durante el
relevamiento del código, aplicando el default razonable y dejándolo asentado:

- Q: ¿Cómo se llama el nuevo tipo de operación auditable? → A: `precio_producto`, sumado al conjunto
  existente de tipos de operación auditados.
- Q: ¿Dónde se registra el origen del cambio (importación / manual / etc.)? → A: en el texto legible del
  detalle del evento, con un rótulo explícito por origen. **No** se reutiliza el campo de origen de
  sistema, que está reservado para acciones sin usuario humano (integraciones ML/Tiendanube).
- Q: ¿La auditoría cubre sólo importación y edición manual, o todos los caminos que escriben un precio?
  → A: **todos** los caminos que pasan por el punto único de escritura de precios. Al relevar el código
  aparecieron dos orígenes adicionales no contemplados en el pedido inicial: la **edición masiva de
  precios/costos** desde el listado de Productos (ajuste por porcentaje o monto fijo sobre todos los
  productos que matchean el filtro) y la **copia de producto**. La edición masiva es, de hecho, el
  origen de mayor riesgo del sistema — es el mismo "aumentar un % " que motivó esta spec, pero en un
  solo clic y sin planilla intermedia que sirva de respaldo. Excluirla dejaría el gap principal abierto.
- Q: Ante una operación concurrente, ¿cuál es la cantidad final "correcta"? → A: la que resulte de una
  ejecución secuencial equivalente (el ajuste por importación y el movimiento concurrente se aplican uno
  después del otro, en cualquier orden). Lo que se exige es que **ningún movimiento se pierda ni se
  duplique**, no un valor final único predeterminado.
- Q: ¿Qué presupuesto de tiempo tiene el registro de auditoría en una importación grande? → A: una tanda
  del asistente (1.000 filas) debe seguir completándose dentro del margen de tiempo con el que ya opera
  hoy, incluyendo el registro de auditoría de todas las listas de precios activas de esas filas.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Reconstruir un cambio de precios que salió mal (Priority: P1)

El encargado exporta la planilla de Productos, aplica una fórmula de aumento sobre una lista de precios y
reimporta el archivo. Días después detecta que algunos productos quedaron con un precio incorrecto (la
fórmula tomó mal una referencia). Entra a la pantalla de Auditoría, filtra por el período y la operación
de cambio de precios, y puede ver para cada producto afectado qué lista se tocó, qué precio tenía antes,
qué precio quedó, quién hizo el cambio y por qué vía (importación o edición manual). Con esa información
puede restaurar los valores correctos.

**Why this priority**: es el gap que afecta directamente el flujo de trabajo real y habitual del usuario
(aumentos de precio por fórmula), y hoy no tiene ninguna red de contención: sin registro del valor previo,
un error de fórmula es irreversible salvo que exista por casualidad un export anterior guardado a mano.

**Independent Test**: se puede probar de forma aislada cambiando el precio de un producto (por importación
y por edición manual) y verificando que la pantalla de Auditoría muestra el evento con precio anterior y
precio nuevo. No depende de la corrección de stock.

**Acceptance Scenarios**:

1. **Given** un producto con precio $100 en la lista "Mayorista", **When** se reimporta una planilla que
   trae $120 para ese producto en esa lista, **Then** queda registrado un evento de auditoría que indica
   el producto, la lista "Mayorista", el precio anterior $100, el precio nuevo $120, el usuario que
   ejecutó la importación y que el origen fue una importación.
2. **Given** un producto con precio $100 en una lista, **When** un usuario edita ese precio a $150 desde
   la ficha del producto, **Then** queda registrado un evento de auditoría equivalente, indicando que el
   origen fue una edición manual.
3. **Given** un producto sin precio cargado en una lista, **When** se le asigna un precio por cualquiera
   de los dos caminos, **Then** queda registrado un evento de auditoría que refleja que no había precio
   anterior.
4. **Given** un producto con precio cargado en una lista, **When** se guarda el producto desde la ficha
   dejando esa lista sin precio (lo que hoy elimina el precio existente), **Then** queda registrado un
   evento de auditoría que refleja la eliminación de ese precio y cuál era su valor.
5. **Given** una reimportación donde el precio de la planilla es idéntico al que ya tenía el producto,
   **When** se procesa esa fila, **Then** NO se genera ningún evento de auditoría (sólo se auditan
   cambios reales, para que el registro no se llene de ruido en cada reimportación).
6. **Given** el registro de auditoría del cambio de precio, **When** se consulta desde la pantalla de
   Auditoría, **Then** se puede filtrar por este tipo de operación igual que se filtra por las demás.
7. **Given** un conjunto de productos alcanzados por un filtro del listado, **When** se les aplica un
   aumento masivo de precios por porcentaje desde la acción de edición masiva, **Then** queda registrado
   un evento de auditoría por cada precio efectivamente modificado, con su precio anterior y su precio
   nuevo, rotulado como originado en una edición masiva.

---

### User Story 2 - Importar stock sin pisar las ventas del día (Priority: P2)

El encargado reimporta una planilla con las cantidades de stock corregidas mientras el negocio sigue
operando. Durante los minutos que dura la importación, se registran ventas y compras que mueven el stock
de algunos de los productos que la planilla también toca. Al terminar, el stock de esos productos refleja
tanto el valor que traía la planilla como los movimientos que ocurrieron, sin que ninguno de los dos se
pierda ni se duplique.

**Why this priority**: es un defecto técnico más grave, pero sólo se manifiesta cuando hay operación
concurrente durante la importación; P1 en cambio se manifiesta siempre.

**Independent Test**: se puede probar de forma aislada simulando un movimiento de stock concurrente entre
la lectura y la escritura del ajuste por importación, y verificando que la cantidad final es consistente.
No depende de la auditoría de precios.

**Acceptance Scenarios**:

1. **Given** un producto con 10 unidades en un depósito y una planilla que trae 50 para ese producto,
   **When** se procesa esa fila sin operación concurrente, **Then** el stock queda en 50 y se registra un
   movimiento de stock de ajuste por +40 con el mismo motivo de importación que hoy.
2. **Given** un producto con 10 unidades y una planilla que trae 50, **When** entre la lectura del stock
   actual y la aplicación del ajuste se registra una venta de 3 unidades, **Then** la cantidad final es
   la que corresponde a una ejecución secuencial equivalente de ambas operaciones (50 si la venta se
   aplicó antes del ajuste, 47 si se aplicó después), ambos movimientos quedan en el histórico, y la
   venta no queda anulada silenciosamente. Lo que se verifica es que **ningún movimiento se pierda ni se
   duplique**, no un valor final único predeterminado.
3. **Given** un producto cuya cantidad en la planilla es igual a la que ya tiene, **When** se procesa esa
   fila, **Then** no se genera ningún movimiento de stock (comportamiento actual, que se conserva).
4. **Given** cualquier fila de stock procesada por importación, **When** se consulta el histórico de
   movimientos de ese producto, **Then** el movimiento generado sigue siendo del mismo tipo, con el mismo
   texto de motivo y atribuido al mismo usuario que antes de este cambio.

---

### Edge Cases

- **Producto que no controla stock (servicio)**: se mantiene el comportamiento actual — no se genera
  movimiento ni ajuste alguno.
- **Fallo al registrar la auditoría**: si el registro del evento de auditoría falla por cualquier motivo,
  ni la importación ni el guardado del producto deben abortarse ni revertirse. La auditoría documenta, no
  gatea la operación.
- **Importación de gran volumen**: una planilla de varios miles de productos con varias listas de precios
  puede generar un volumen alto de eventos de auditoría en una sola corrida. El registro debe absorber
  ese volumen sin degradar la importación al punto de hacerla inviable ni de provocar cortes por tiempo.
- **Producto nuevo creado por importación**: cuando la fila resulta en un alta (no una actualización), la
  carga inicial de precios también constituye un cambio de precio auditable, sin precio anterior.
- **Precio sin cambios pero producto sí modificado**: si la fila cambia otros campos del producto pero no
  el precio, no se genera evento de auditoría de precio.
- **Stock concurrente en el mismo lote**: dos filas de la misma planilla que apunten al mismo producto y
  depósito deben resolverse de forma consistente, quedando como valor final el de la última fila
  procesada.
- **Escrituras de precio que no pasan por el modelo**: existe al menos un comando de mantenimiento que
  borra precios operando directamente sobre los datos, sin pasar por el punto único de escritura. Esas
  escrituras quedan fuera del alcance de la auditoría; la excepción debe quedar documentada (FR-009a) en
  lugar de asumirse cubierta.
- **Aumento masivo sobre un producto sin precio en esa lista**: la acción de edición masiva crea el
  precio partiendo de cero. Ese caso se audita como creación de precio, sin precio anterior.

## Requirements *(mandatory)*

### Functional Requirements

#### Atomicidad del ajuste de stock (User Story 2)

- **FR-001**: Al actualizar el stock de un producto en un depósito a partir de un valor absoluto traído
  por una planilla, el sistema DEBE leer la cantidad actual, calcular la diferencia y aplicar el ajuste
  como una única operación indivisible, de modo que ninguna otra operación pueda modificar ese stock en
  el medio.
- **FR-002**: El sistema DEBE exponer esta operación de "fijar el stock a un valor absoluto" como una
  capacidad del servicio de stock, y el importador DEBE usarla en lugar de combinar por su cuenta una
  consulta de disponibilidad seguida de un ajuste.
- **FR-003**: El movimiento de stock resultante DEBE conservar exactamente las características que tiene
  hoy: mismo tipo de movimiento, mismo texto de motivo ("Ajuste (importación)" en actualizaciones y
  "Registro inicial (importación)" en altas), misma atribución de usuario y misma trazabilidad en el
  histórico.
- **FR-004**: Si la cantidad deseada coincide con la cantidad actual, el sistema NO DEBE generar ningún
  movimiento de stock.
- **FR-005**: Los productos que no controlan stock (servicios) DEBEN seguir siendo ignorados por este
  circuito.

#### Auditoría de cambios de precio (User Story 1)

- **FR-006**: El sistema DEBE registrar un evento de auditoría cada vez que el precio de un producto en
  una lista de precios se crea, se modifica o se elimina.
- **FR-007**: Ese registro DEBE incluir, como mínimo: el producto afectado, la lista de precios, el precio
  anterior (o la indicación de que no existía), el precio nuevo, el usuario responsable y el momento del
  cambio.
- **FR-008**: El registro DEBE permitir distinguir el origen del cambio mediante un rótulo explícito en
  el detalle legible del evento. Los orígenes a distinguir son, como mínimo: **importación masiva**,
  **edición manual desde la ficha del producto**, **edición masiva de precios/costos desde el listado**
  y **copia de producto**.
- **FR-009**: La auditoría DEBE cubrir todos los caminos por los que la aplicación escribe un precio de
  producto por lista, no solamente la importación y la edición manual. En particular DEBE cubrir la
  **edición masiva de precios/costos** disponible en el listado de Productos (ajuste por porcentaje o
  monto fijo aplicado a todos los productos que matchean el filtro vigente), por ser el camino de mayor
  riesgo: modifica muchos productos de una sola vez y, a diferencia de la importación, no deja ninguna
  planilla intermedia que sirva de respaldo.
- **FR-009a**: Si algún camino de escritura de precios NO queda cubierto por el punto único de auditoría
  (por ejemplo, un comando de mantenimiento que escriba directamente sobre los datos sin pasar por el
  modelo), esa excepción DEBE quedar documentada explícitamente en la documentación de dominio en lugar
  de pasar inadvertida.
- **FR-010**: El sistema NO DEBE registrar un evento cuando el precio guardado es igual al que ya tenía
  (escritura sin cambio real de valor).
- **FR-011**: Los eventos DEBEN ser consultables desde la pantalla de Auditoría ya existente, y DEBEN ser
  filtrables por tipo de operación de la misma forma que los demás tipos de operación auditados.
- **FR-012**: Un fallo al registrar el evento de auditoría NO DEBE abortar ni revertir la importación ni
  el guardado del producto.
- **FR-013**: Los eventos de auditoría de precio DEBEN ser inmutables y de sólo lectura desde la
  aplicación, igual que el resto de los eventos auditados.
- **FR-014**: El registro de auditoría NO DEBE alterar los valores de precio efectivamente guardados: es
  un registro paralelo, no interviene en el resultado de la operación.

#### Alcance y no-regresión

- **FR-015**: El comportamiento visible del asistente de importación (pasos, mapeo de columnas, vista
  previa, resumen de importados/fallidos/advertencias) DEBE permanecer sin cambios.
- **FR-016**: El resto del comportamiento del importador (validación por fila, resolución de alta vs
  actualización por Id, tolerancia a filas inválidas sin abortar el archivo) DEBE permanecer sin cambios.
- **FR-017**: Los cambios de precio que ya disparan sincronización hacia integraciones externas (tienda
  online y publicaciones) DEBEN seguir disparándola exactamente igual; la auditoría se suma a ese
  comportamiento, no lo reemplaza ni lo condiciona.

### Key Entities

- **Precio de producto por lista**: el precio de un producto en una lista de precios determinada. Es la
  entidad cuyo cambio pasa a ser auditado. Único por combinación de producto y lista.
- **Evento de auditoría**: registro inmutable de una acción sobre una entidad del negocio, con usuario,
  momento, tipo de acción, tipo de operación y un detalle legible. Se incorpora el nuevo tipo de
  operación **`precio_producto`**, que hoy no existe entre los tipos auditados (venta, presupuesto,
  cobro, gasto, compra, movimiento de tesorería, movimiento de stock). El origen del cambio se expresa
  como rótulo dentro del detalle legible, no como campo propio.
- **Movimiento de stock**: registro histórico de cada variación de stock de un producto en un depósito.
  No cambia su forma; cambia la manera en que el importador calcula la variación que registra.
- **Stock**: cantidad actual de un producto en un depósito. Es el recurso sobre el que se necesita
  exclusividad durante el cálculo del ajuste.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ante un cambio de precio erróneo, el usuario puede identificar el precio anterior de
  cualquier producto afectado desde la pantalla de Auditoría, sin necesitar ningún archivo externo ni
  backup previo.
- **SC-002**: El 100% de los cambios de precio por lista quedan auditados, sin importar si se originaron
  en una importación masiva o en la edición manual de un producto.
- **SC-003**: Una importación que corre en simultáneo con ventas del mismo producto no produce ninguna
  pérdida ni duplicación de unidades: la cantidad final es reproducible y explicable a partir del
  histórico de movimientos.
- **SC-004**: Reimportar una planilla sin modificaciones no genera ningún movimiento de stock ni ningún
  evento de auditoría de precio (cero ruido por reimportación idéntica).
- **SC-005**: Una tanda de 1.000 filas del asistente de importación sigue completándose dentro del mismo
  margen de tiempo con el que opera hoy, incluyendo el registro de auditoría de todas las listas de
  precios activas de esas filas, sin cortes por tiempo de espera.
- **SC-007**: Un aumento masivo de precios aplicado desde el listado de Productos queda íntegramente
  auditado: para cada producto alcanzado se puede recuperar el precio previo por lista.
- **SC-006**: Ningún fallo de auditoría provoca la pérdida de una importación o de un guardado de
  producto.

## Assumptions

- Se reutiliza el mecanismo de auditoría existente del proyecto (spec 054) en lugar de crear una tabla o
  un módulo de auditoría nuevo. Esto implica **incorporar un tipo de operación nuevo** al conjunto de
  tipos auditables, que hoy sólo contempla venta, presupuesto, cobro, gasto, compra, movimiento de
  tesorería y movimiento de stock — el modelo de datos debe actualizarse en consecuencia.
- La consulta del historial se hace desde la pantalla de Auditoría existente. **No** se agrega una
  sub-vista ni una solapa de historial de precios en la ficha del producto.
- El detalle del evento (producto, lista, precio anterior → precio nuevo) se expresa en el campo de texto
  legible que ya usan los demás eventos auditados, respetando su límite de longitud.
- Existe ya un punto único por el que pasan todas las escrituras de precio hechas a través del modelo
  (usado hoy para sincronizar con las integraciones externas), lo que permite cubrir todos los orígenes
  sin duplicar lógica en cada camino. El origen del cambio debe poder determinarse desde ese punto; si no
  fuera determinable ahí, el mecanismo por el que cada camino informa su origen se define en el plan.
- El usuario responsable del evento es el usuario autenticado que ejecuta la importación o la edición;
  el asistente de importación siempre corre bajo una sesión autenticada.
- La corrección de stock no cambia el modelo de datos: se resuelve reorganizando cómo se calcula y
  aplica el ajuste, no agregando campos ni tablas.
- Los volúmenes de referencia son los del negocio real (planillas de miles de productos con unas pocas
  listas de precios activas), no volúmenes arbitrariamente grandes.

## Fuera de alcance

- Reversión o "deshacer" en bloque de una importación completa.
- Auditoría de cambios de stock: ya existe mediante el histórico de movimientos de stock.
- Corrección del desalineamiento entre el encabezado de stock del archivo exportado y el alias que usa el
  auto-mapeo del importador (es cosmético: el usuario revisa y corrige el mapeo en el paso 2 antes de
  confirmar).
- Cambios en la interfaz del asistente de importación.
- Auditoría de cambios en otros campos del producto (costo, precio de venta base, nombre, etc.), incluso
  cuando la acción de edición masiva los modifique en la misma operación: sólo se auditan los precios por
  lista.
- Auditoría de escrituras de precio hechas por comandos de mantenimiento que operan directamente sobre
  los datos sin pasar por el modelo (ver FR-009a: se documentan como excepción, no se cubren).
- Una sub-vista de historial de precios dentro de la ficha del producto.
