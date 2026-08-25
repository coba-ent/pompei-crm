# Feature Specification: Importación por Excel escalable a archivos grandes

**Feature Branch**: `082-importacion-archivos-grandes`

**Created**: 2026-08-25

**Status**: Draft

**Input**: User description: "Importación por Excel escalable a archivos grandes (9.000+ filas) sin timeouts ni agotar memoria. Incidente real del 25/08/2026: al reimportar la planilla de Productos de 1.117 filas por el asistente web sólo se aplicaron las primeras 1.000; las 117 restantes quedaron sin importar por un timeout del servidor web. Causa raíz: cada tanda vuelve a interpretar el archivo completo antes de quedarse con su pedazo. Alcance: las tres solapas del asistente (Clientes, Proveedores, Productos & Servicios)."

## Clarifications

### Session 2026-08-25

Las decisiones de alcance se tomaron con el usuario **antes** de arrancar la cadena (ver *Assumptions →
Decisiones ya tomadas*). Los puntos de abajo son ambigüedades detectadas en la redacción de la spec y
resueltas con defaults justificados, sin volver a interrumpir al usuario.

- Q: ¿Cuántas filas por tanda concretamente, para cumplir el "margen holgado" de FR-005? → A:
  **250 filas**. Con el ritmo medido en producción (~0,103 s por fila) da ~26 s por tanda: **2,3x de
  margen** contra el límite de 60 s, y 39 tandas para el catálogo completo. Se descartó 500 (1,2x de
  margen, demasiado justo) y se prefirió 250 sobre 200 porque el margen extra de 200 no compensa
  sumar 10 tandas más. Queda como parámetro ajustable (FR-005), no como constante enterrada en la
  lógica.
- Q: ¿Cuánto espera entre reintentos el "backoff creciente" de FR-007? → A: **2 s, 4 s y 8 s**
  (14 s de espera total en el peor caso). Suficiente para absorber un corte de red breve o un
  reinicio del servicio web, y despreciable frente a los ~16 minutos de una importación completa.
- Q: ¿Se puede retomar una importación después de cerrar el navegador? → A: **No**, y es un límite
  aceptado. El estado transitorio vive en disco + sesión (invariante vigente de §2.4): si la sesión se
  pierde, hay que volver a subir el archivo. Retomar (FR-008/FR-009) cubre el corte de una tanda
  **dentro de la misma sesión**, que es el caso del incidente. Hacerlo sobrevivir al cierre del
  navegador exigiría persistir el estado en base, que es justamente lo que §2.4 prohíbe.
- Q: Al retomar, ¿cómo se detecta que el mapeo ya no corresponde al archivo (edge case)? → A: se
  compara la **cantidad y el orden de los encabezados** del archivo ya interpretado contra los que se
  usaron al armar el mapeo. Si no coinciden, se informa y se pide rehacer el mapeo, en vez de aplicar
  valores a columnas equivocadas. Es el único chequeo necesario porque el mapeo se referencia por
  índice de columna.
- Q: ¿El límite vigente de 10 MB por archivo alcanza para las 10.000 filas de SC-003? → A: **Sí, con
  margen amplio**. El archivo real del incidente pesa 215 KB para 1.118 filas; proyectado a 10.000
  filas da ~1,8 MB, muy por debajo de 10 MB. **El límite de tamaño no se toca.**

## Contexto del incidente que motiva esta spec

El 25/08/2026, en producción, el usuario reimportó la planilla exportada de Productos (1.117 filas de
datos) por el asistente web (§2.4 de `documentacion_principal_crm.md`), mapeando costo, precio de
venta, las 11 listas de precios y Estado.

- **Resultado**: se aplicaron **1.000 filas** y quedaron **117 sin procesar**. El resumen nunca
  llegó a mostrarse; la pantalla quedó colgada en "Preparando la importación…".
- **Evidencia en el servidor**: `upstream timed out (110: Connection timed out) while reading
  response header from upstream, request: "POST /importar-datos/productos/confirmar-lote"`.
- **Qué pasó exactamente**: el proceso del servidor **sí terminó bien** la primera tanda (por eso las
  1.000 filas quedaron guardadas y consistentes, con su snapshot de deshacer completo), pero el
  navegador ya había recibido el error de tiempo agotado y **nunca pidió la segunda tanda**.
- **Cómo se resolvió**: las 117 filas faltantes se aplicaron a mano, por línea de comandos, sobre la
  misma corrida de import (para que el "deshacer" siguiera siendo una sola operación). Tardaron
  **7,5 segundos** al no pasar por el servidor web.

El dato relevante es que **no hubo corrupción ni pérdida de datos**: el diseño por tandas resistió el
corte. Lo que falló es que la importación **no se completa sola** y requiere intervención técnica.

### Mediciones tomadas sobre el caso real

| Volumen | Interpretar el archivo | Memoria pico |
|---|---|---|
| 1.118 filas (planilla del incidente) | 3 s | 66 MB |
| 9.632 filas (catálogo real del negocio, proyectado) | ~26 s | ~570 MB |

Cada tanda vuelve a interpretar el archivo **entero** y recién después se queda con su pedazo. Con el
catálogo completo eso da, por tanda, ~26 s de interpretación + ~103 s de proceso ≈ **129 s**, contra
el límite de **60 s** del servidor web; y ~570 MB contra un límite de memoria de **512 MB**.

**Achicar el tamaño de tanda no alcanza como arreglo**: con tandas de 250 filas sobre 9.632 se
pagarían los ~26 s de interpretación en **cada una** de las 39 tandas (~17 minutos gastados sólo en
releer lo mismo), y cada tanda quedaría en ~52 s, rozando igual el límite de 60 s.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Importar el catálogo completo sin que se corte (Priority: P1)

El usuario exporta la planilla de Productos & Servicios, la edita en Excel (típicamente una fórmula
de aumento sobre una o varias listas de precios, o una corrección de costos) y la reimporta entera
mapeando "Id". Hoy el catálogo real tiene **9.632 productos**. Necesita que la importación termine
sola, sin cortarse a la mitad y sin que nadie tenga que entrar por línea de comandos a completarla.

**Why this priority**: Es el motivo de la spec y el circuito central del negocio. Hoy este caso
**falla con certeza**: el catálogo completo es 8,6 veces más grande que el archivo que ya se cortó.
Sin esto, cada actualización masiva de precios queda a medio aplicar, con parte del catálogo con
precios nuevos y parte con precios viejos — y el usuario no tiene forma de saber cuáles sin comparar
a mano.

**Independent Test**: Se importa una planilla de 9.000+ filas por el asistente web y se verifica que
el resumen final reporta el total de filas del archivo, sin filas sin procesar, y que ningún registro
del archivo quedó con valores viejos.

**Acceptance Scenarios**:

1. **Given** una planilla de 9.632 filas de Productos con "Id" mapeado y cambios de precio en todas
   las filas, **When** el usuario confirma la importación, **Then** el resumen reporta 9.632 filas
   procesadas, la pantalla nunca queda colgada, y ningún producto del archivo conserva su precio
   anterior.
2. **Given** esa misma importación en curso, **When** el usuario mira la pantalla, **Then** ve el
   progreso avanzar de forma continua (filas procesadas sobre el total) sin quedarse trabado en un
   mismo número.
3. **Given** una planilla de 9.632 filas **idéntica** a los datos ya cargados, **When** se la
   reimporta completa, **Then** no se genera ningún evento de auditoría de precio ni ningún
   movimiento de stock, y el resumen reporta las 9.632 filas como procesadas.
4. **Given** una planilla chica (menos de una tanda), **When** se la importa, **Then** el
   comportamiento y el resumen son idénticos a los actuales (sin regresión).

---

### User Story 2 - No perder lo ya procesado si algo se corta (Priority: P2)

Durante una importación larga puede cortarse la conexión, reiniciarse el servicio web o fallar una
tanda puntual. El usuario necesita que el sistema reintente solo y, si aun así no puede, poder
retomar desde donde quedó en vez de empezar de cero o quedar sin saber qué se aplicó.

**Why this priority**: Es la red de seguridad. Con la User Story 1 resuelta el corte deja de ser lo
esperable, pero una importación de ~16 minutos sigue siendo una ventana amplia para un corte de red.
Es exactamente lo que pasó en el incidente: no se perdió nada, pero **el usuario quedó sin forma de
completarlo por su cuenta**.

**Independent Test**: Se fuerza el fallo de una tanda intermedia y se verifica que el sistema
reintenta solo; si se fuerza el fallo de forma persistente, se verifica que la pantalla ofrece
retomar y que al hacerlo se procesan exactamente las filas que faltaban, sin repetir las ya hechas.

**Acceptance Scenarios**:

1. **Given** una importación en curso, **When** una tanda falla una vez por un error transitorio,
   **Then** el sistema la reintenta solo y la importación continúa hasta el final sin que el usuario
   tenga que hacer nada.
2. **Given** una importación en curso, **When** una tanda falla de forma persistente, **Then** la
   pantalla muestra el error en lenguaje claro e indica desde qué fila se puede retomar, sin
   descartar lo ya procesado.
3. **Given** una importación cortada, **When** el usuario elige retomar, **Then** se procesan
   únicamente las filas pendientes y el resultado final es el mismo que si nunca se hubiera cortado
   (mismos totales, sin filas duplicadas ni salteadas).
4. **Given** una importación de Productos cortada y retomada, **When** termina, **Then** el
   "deshacer" cubre **todas** las filas aplicadas como una sola corrida, no como dos.

---

### User Story 3 - La misma robustez en Clientes y Proveedores (Priority: P3)

Las solapas de Clientes y Proveedores usan el mismo motor de importación, así que arrastran el mismo
problema aunque hoy sus volúmenes sean menores.

**Why this priority**: El arreglo es el mismo código, así que el costo incremental es sólo de
verificación. Se prioriza por debajo porque el volumen real de Clientes/Proveedores todavía no
alcanza el punto de falla, pero dejarlo afuera sería dejar una bomba de tiempo conocida.

**Independent Test**: Se importa una planilla grande de Clientes y otra de Proveedores y se verifica
que completan igual que Productos, respetando las reglas propias de cada entidad.

**Acceptance Scenarios**:

1. **Given** una planilla grande de Clientes, **When** se la importa, **Then** termina completa y las
   reglas propias de Clientes (CUIT/DNI en dos columnas, saldo inicial, lista de precios por nombre)
   siguen funcionando igual.
2. **Given** una planilla grande de Proveedores, **When** se la importa, **Then** termina completa y
   las reglas propias de Proveedores siguen funcionando igual.

---

### Edge Cases

- **Archivo sin filas de datos** (sólo encabezados): la importación termina de inmediato informando
  que no había filas, sin error.
- **Archivo de una sola fila**: se procesa en una única tanda, igual que hoy.
- **El usuario cancela a mitad**: se detiene donde está; lo ya aplicado queda aplicado (y en
  Productos, cubierto por el deshacer), y los archivos temporales se eliminan.
- **El usuario cierra el navegador a mitad**: lo ya aplicado queda aplicado; al volver, no queda una
  importación "fantasma" bloqueando una nueva.
- **La sesión expira durante una importación larga**: el usuario recibe un mensaje claro en vez de un
  error técnico, y lo ya procesado no se pierde.
- **El archivo temporal ya no está** (limpieza, reinicio): se informa que hay que volver a subir el
  archivo, sin dejar la pantalla colgada.
- **Dos importaciones de la misma entidad en paralelo** (dos pestañas): no se mezclan entre sí ni se
  pisan los contadores.
- **Archivo con celdas corruptas o tipos raros** en filas del medio: esa fila se reporta como fallida
  y el resto del archivo continúa, igual que hoy.
- **Retomar una importación cuyo mapeo ya no coincide** con el archivo: se detecta comparando los
  encabezados y se informa, en vez de aplicar datos a columnas equivocadas.

## Requirements *(mandatory)*

### Functional Requirements

**Interpretación del archivo**

- **FR-001**: El sistema DEBE interpretar el contenido del archivo subido **una sola vez por
  importación**, no una vez por tanda.
- **FR-002**: Cada tanda DEBE leer únicamente las filas que le tocan procesar, sin necesidad de
  recorrer ni cargar las filas de las demás tandas.
- **FR-003**: El consumo de memoria de una tanda DEBE ser independiente del tamaño total del archivo
  (una tanda de un archivo de 10.000 filas no puede consumir más que una del mismo tamaño de un
  archivo de 1.000).
- **FR-004**: Los datos intermedios que el sistema genere para lograr lo anterior DEBEN ser estado
  transitorio: se eliminan al terminar, al cancelar y al abandonar la importación, y nunca se
  persisten en la base de datos (invariante vigente de §2.4).

**Tamaño de tanda y progreso**

- **FR-005**: El tamaño de tanda DEBE ser de **250 filas**, calibrado para dejar al menos **2x de
  margen** frente al límite de tiempo del servidor web con el ritmo de proceso medido, y DEBE quedar
  como parámetro ajustable, no como constante enterrada en la lógica.
- **FR-006**: El sistema DEBE informar el progreso (filas procesadas sobre total) después de cada
  tanda, de modo que el usuario vea avance continuo durante toda la importación.

**Resiliencia**

- **FR-007**: Ante el fallo de una tanda, el sistema DEBE reintentarla automáticamente hasta **3
  veces**, esperando **2 s, 4 s y 8 s** entre intentos.
- **FR-008**: Si una tanda sigue fallando después de los reintentos, el sistema DEBE mostrar el error
  en lenguaje claro y ofrecer **retomar desde la primera fila no procesada**, conservando todo lo ya
  aplicado.
- **FR-009**: Al retomar, el sistema DEBE procesar exactamente las filas pendientes: ninguna fila ya
  aplicada puede volver a procesarse ni ninguna pendiente puede saltearse.
- **FR-010**: En Productos & Servicios, una importación que se cortó y se retomó DEBE quedar
  registrada como **una sola corrida** a efectos del deshacer (spec 078).

**Compatibilidad (invariantes que no se pueden romper)**

- **FR-011**: El upsert por Id DEBE seguir comportándose igual: alta nueva, actualización parcial,
  alta preservando el id, o fila fallida, según lo definido en la spec 027.
- **FR-012**: Una fila inválida DEBE seguir reportándose con su motivo sin abortar el resto del
  archivo.
- **FR-013**: El snapshot de deshacer (spec 078) y la auditoría de cambios de precio (spec 074) DEBEN
  seguir registrándose igual, incluyendo la reutilización de la misma corrida entre tandas.
- **FR-014**: Reimportar una planilla sin cambios DEBE seguir sin generar eventos de auditoría de
  precio ni movimientos de stock.
- **FR-015**: El stock DEBE seguir fijándose de forma atómica contra el valor actual (spec 074), sin
  reintroducir la ventana de *lost update*.
- **FR-016**: Las reglas de mapeo del Paso 2 (campo obligatorio mapeado, sin dos columnas al mismo
  campo, CUIT admite dos columnas) DEBEN seguir vigentes sin cambios.
- **FR-017**: El automapeo por encabezado y alias (incluidos "Stock {depósito}" y "Punto de
  Reposición") DEBE seguir funcionando igual.
- **FR-018**: El comportamiento DEBE ser idéntico al actual para archivos que caben en una sola
  tanda, sin regresiones visibles para el usuario.

**Alcance por entidad**

- **FR-019**: Las mejoras DEBEN aplicar a las tres solapas del asistente: Clientes, Proveedores y
  Productos & Servicios.

### Key Entities

- **Archivo de importación en curso**: el archivo que el usuario subió más su representación ya
  interpretada, lista para leerse por pedazos. Es estado transitorio asociado a la importación en
  curso, y se elimina al terminar o cancelar.
- **Progreso de importación**: cuántas filas se procesaron y cuál es la primera pendiente. Es lo que
  permite mostrar avance y retomar tras un corte.
- **Corrida de import** (ya existente, spec 078, sólo Productos): agrupa todas las filas aplicadas de
  una misma importación para poder deshacerlas juntas. Un corte y su retoma siguen siendo una sola
  corrida.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Una planilla con el catálogo completo del negocio (9.632 filas al 25/08/2026) se
  importa **completa**, sin filas sin procesar y sin intervención técnica.
- **SC-002**: Esa importación completa termina en **menos de 25 minutos**, mostrando progreso que
  avanza durante todo el proceso.
- **SC-003**: El sistema soporta archivos de al menos **10.000 filas** sin fallar por tamaño ni
  quedarse sin memoria, dejando margen para el crecimiento del catálogo.
- **SC-004**: Ante un corte forzado en una tanda intermedia, **el 100% de las filas ya aplicadas se
  conserva** y la importación puede completarse sin repetir ni saltear filas.
- **SC-005**: Reimportar una planilla sin cambios sobre 9.632 filas genera **cero** eventos de
  auditoría de precio y **cero** movimientos de stock.
- **SC-006**: Las tres entidades (Clientes, Proveedores, Productos & Servicios) completan una
  importación grande con sus reglas propias intactas.
- **SC-007**: Cero regresiones en el comportamiento actual para archivos chicos: la suite de tests
  existente de importación (specs 026/027/074/078) pasa sin modificaciones de expectativas.

## Assumptions

### Decisiones ya tomadas con el usuario (25/08/2026)

- **Alcance**: las tres entidades del asistente, porque el problema está en el motor compartido.
- **Cómo se interpreta una sola vez**: al subir el archivo se lo convierte a un formato intermedio
  **de una fila por línea, en disco**, junto al archivo temporal que ya existe. Cada tanda lee sólo
  sus líneas. Se descartó guardar las filas en una tabla de la base para no meter miles de
  inserciones extra ni una tabla de staging que haya que limpiar. (Detalle de implementación a
  confirmar en `/speckit-plan`.)
- **Ante fallo de tanda**: reintento automático (hasta 3, con espera creciente) **y**, si igual falla,
  botón para retomar desde la fila pendiente.
- **Límite de tiempo del servidor web**: se documenta como paso de despliegue subir el
  `fastcgi_read_timeout` del VPS a 300 s. **No es parte del código** y requiere autorización
  explícita del usuario antes de tocarlo. Con el arreglo de interpretación única las tandas deberían
  bajar a ~25 s, con lo cual el límite actual de 60 s ya alcanzaría: el cambio de servidor es margen
  extra, no un requisito para que la feature funcione.

### Supuestos

- El paso de subida del archivo puede permitirse el pico de memoria de interpretar el archivo entero,
  porque ocurre **una sola vez** y ya eleva el límite de memoria para ese request. El límite de 10 MB
  de tamaño de archivo vigente en §2.4 se mantiene.
- El navegador del usuario permanece abierto durante la importación. La importación no corre en
  segundo plano en el servidor: sigue siendo dirigida desde la pantalla, como hoy.
- No se agrega detección de duplicados ni cambios al mapeo: esta spec es de robustez, no de
  funcionalidad nueva.
- Los tiempos por fila medidos (~0,1 s) se mantienen; si el proceso por fila se hiciera más lento por
  otra causa, el tamaño de tanda es el parámetro a recalibrar (FR-005).
- El estado transitorio sigue viviendo en disco + sesión. Si la sesión se pierde, la importación no
  se puede retomar y hay que volver a subir el archivo (comportamiento aceptable, ya vigente).

### Dependencias

- `ImportadorFilas` (motor compartido por las tres entidades) y `ImportacionController`.
- Spec 074 (stock atómico y auditoría de precios), spec 078 (deshacer import), spec 027 (upsert por
  Id): sus invariantes y sus tests son el contrato que esta spec no puede romper.
- El despliegue al VPS de producción requiere autorización explícita del usuario (regla vigente del
  proyecto).
