# Feature Specification: Historial de importaciones — archivo descargable e informe de qué cambió

**Feature Branch**: `093-historial-importaciones-detalle`

**Created**: 2026-08-28

**Status**: Draft

**Input**: User description: "Que el historial guarde el archivo subido y permita descargarlo, y un informe más detallado de qué cambió y qué atributos se cambiaron."

## Por qué existe esta spec

El 28/08/2026 apareció en el listado de movimientos de stock un ajuste de **−181 unidades de "EMBALAJE JPD"**, con la única explicación de `Ajuste (importación)`. Para saber de dónde salía hubo que consultar la base a mano: encontrar la corrida, leer los snapshots y comparar contra el estado actual de cada producto.

Ya se mejoró el texto del movimiento (`ca382145`) para que nombre el archivo y el número de corrida. Pero **desde el historial sigue sin poder verse qué hizo esa importación**, y el archivo que la originó ya no está.

Dos hechos verificados en producción que cambian el tamaño del trabajo:

1. **El archivo ya se está guardando, pero por accidente.** `limpiarTemporales()` lo borra al confirmar, sólo que esa limpieza no siempre corre: hay **23 archivos acumulados, 9,2 MB**, el más viejo del 06/08, con nombres UUID que no se pueden asociar a ninguna corrida. Hoy se paga el costo de almacenamiento sin ninguno de los beneficios.
2. **Los datos del informe ya existen.** `importacion_filas_snapshot` guarda, por fila, el estado anterior completo del producto, sus precios en las 11 listas y su stock por depósito. **1.605 filas ocupando 1,33 MB**, y **sobreviven al vencimiento del deshacer** (verificado: las corridas 2 y 3 lo tienen vencido y conservan sus filas). No hace falta capturar nada nuevo ni tocar el importador.

## Clarifications

### Sesión 2026-08-28 (resueltas durante la especificación)

- **Qué mide el informe** — es la decisión que define la feature. El snapshot guarda el estado **anterior**; el estado **posterior** no se guarda en ningún lado. Comparar contra el producto **de hoy** es lo único posible, pero mezcla lo que hizo la importación con todo lo que pasó después (una venta, una edición manual). **Se adopta**: el informe se titula explícitamente *"qué cambió desde la importación"*, no *"qué hizo la importación"*, y **cada fila avisa cuando hubo actividad posterior** que puede estar contaminando la comparación. El sistema ya sabe detectarlo: los `limite_*` del snapshot son exactamente eso.
- **Corridas sin snapshot** (la corrida 1 tiene 0 filas) — se informa **"sin detalle disponible"**, nunca "0 cambios". Son cosas distintas y confundirlas haría que una importación vieja parezca inofensiva.
- **Cuánto se guarda el archivo** — **90 días**, configurable. Cubre un trimestre de auditoría; a 9 MB por 3 semanas, son unos 40 MB en régimen. Los archivos huérfanos que hoy están sueltos entran en la misma limpieza.
- **Quién puede descargar** — el mismo permiso que ya gobierna la pantalla de importación. Un archivo de importación tiene precios de costo y márgenes: no puede quedar más expuesto que la pantalla que lo originó.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver qué cambió una importación (Priority: P1)

Quien administra el catálogo abre el historial de importaciones, elige una corrida y ve un informe con **qué cambió realmente**: cuántos productos cambiaron cada campo, cuántos cambiaron de precio y en qué lista, y qué stock se movió con su valor antes y después. Puede abrir el detalle producto por producto.

**Why this priority**: es la pregunta que hoy no tiene respuesta y que obligó a consultar la base a mano. El archivo original **no la contesta**: muestra lo que se quiso cambiar, no lo que cambió. En la corrida real del 28/08, el archivo traía 192 filas y sólo 18 movieron algo — mirando el Excel eso no se puede saber.

**Independent Test**: abrir la corrida del 28/08 en el historial y verificar que informa 192 productos, 0 campos cambiados, 0 precios cambiados y 18 con cambio de stock, con el −181 de EMBALAJE JPD entre ellos.

**Acceptance Scenarios**:

1. **Given** una corrida que sólo cambió stock, **When** se abre su informe, **Then** muestra 0 campos y 0 precios cambiados, y lista los productos cuyo stock cambió con el valor anterior, el actual y la diferencia.
2. **Given** una corrida que cambió precios, **When** se abre su informe, **Then** indica cuántos productos cambiaron de precio **en cada lista por separado**, con ejemplos del importe anterior y el nuevo.
3. **Given** un producto que la importación no modificó, **When** se mira el informe, **Then** no aparece entre los cambios, aunque su fila estuviera en el archivo.
4. **Given** una corrida **sin filas de snapshot**, **When** se abre su informe, **Then** dice **"sin detalle disponible"** y explica por qué, sin mostrar cero cambios.
5. **Given** un producto que tuvo actividad **posterior** a la importación, **When** aparece en el informe, **Then** se lo marca advirtiendo que parte de la diferencia puede no ser de la importación.
6. **Given** un producto **eliminado** después de la importación, **When** se mira el informe, **Then** se lo muestra identificado como eliminado, sin romper el informe.
7. **Given** una corrida **deshecha**, **When** se abre su informe, **Then** se indica que fue deshecha y desde cuándo, para que sus cambios no se lean como vigentes.

---

### User Story 2 - Descargar el archivo que originó la importación (Priority: P2)

Desde el historial se puede **descargar el archivo original** de cualquier corrida, para ver exactamente qué se subió.

**Why this priority**: es el respaldo, no el diagnóstico. Responde *"¿qué mandé?"*, mientras la historia 1 responde *"¿qué pasó?"*. Es más barata, pero sirve menos sola.

**Independent Test**: hacer una importación, ir al historial y descargar el archivo; tiene que bajar íntegro y con su nombre original.

**Acceptance Scenarios**:

1. **Given** una corrida confirmada, **When** se pide la descarga, **Then** baja el archivo tal cual se subió, con su nombre original.
2. **Given** una corrida anterior a esta feature, **When** se mira el historial, **Then** se indica que el archivo no está disponible, sin ofrecer una descarga que fallaría.
3. **Given** un usuario sin permiso sobre importaciones, **When** intenta descargar, **Then** se le niega el acceso.
4. **Given** un archivo eliminado por antigüedad, **When** se mira el historial, **Then** se informa que venció, distinguiéndolo de "nunca se guardó".

---

### User Story 3 - Que los archivos no se acumulen para siempre (Priority: P3)

Los archivos guardados se eliminan solos pasado el plazo configurado, y los que hoy están sueltos sin corrida asociada también.

**Why this priority**: sin esto, la historia 2 convierte un problema chico en uno que crece sin techo. Hoy ya hay 9,2 MB de basura de tres semanas.

**Independent Test**: dejar un archivo con fecha vieja y verificar que la limpieza lo borra y marca la corrida como "archivo vencido"; y que un archivo dentro del plazo no se toca.

**Acceptance Scenarios**:

1. **Given** un archivo más viejo que el plazo, **When** corre la limpieza, **Then** se elimina y su corrida queda marcada como vencida, conservando el resto de sus datos.
2. **Given** un archivo dentro del plazo, **When** corre la limpieza, **Then** no se toca.
3. **Given** un archivo suelto sin corrida asociada, **When** corre la limpieza, **Then** se elimina.
4. **Given** una importación en curso, **When** corre la limpieza, **Then** sus archivos de trabajo no se tocan.

---

### Edge Cases

- **Corrida con snapshot parcial** (algunas filas sí, otras no): se informa sobre las que hay y se aclara cuántas faltan.
- **Producto cuyo campo cambió y volvió al valor original** después: el informe no lo detecta, porque compara puntas. Es una limitación inherente y tiene que estar dicha.
- **Archivo presente en disco pero ilegible o corrupto**: la descarga falla con un mensaje claro, no con un archivo vacío.
- **Dos corridas del mismo archivo** (mismo nombre, distinto contenido): cada una conserva el suyo; el nombre no puede ser la clave.
- **Una lista de precios eliminada** después de la importación: el snapshot la referencia por id; el informe la nombra por id si ya no existe, sin romper.
- **Un depósito eliminado**: mismo criterio.
- **Corrida con 0 filas totales**: se informa sin cambios y sin error.
- **Espacio en disco insuficiente al guardar**: la importación **no debe fallar** por no poder guardar la copia — el archivo es un respaldo, no parte de la operación. Se registra que no se pudo guardar.

## Requirements *(mandatory)*

### Funcionales — informe de cambios (US1)

- **FR-001**: El sistema DEBE mostrar, por corrida, cuántos productos cambiaron cada campo, con ejemplos del valor anterior y el actual.
- **FR-002**: El sistema DEBE mostrar cuántos productos cambiaron de precio **por cada lista**, no agregado.
- **FR-003**: El sistema DEBE listar los productos cuyo stock cambió, con depósito, valor anterior, valor actual y diferencia.
- **FR-004**: El sistema DEBE ordenar los cambios de stock por magnitud, para que el más grande se vea primero.
- **FR-005**: El informe DEBE compararse contra el estado **actual** del producto y DEBE decir explícitamente que eso es lo que mide.
- **FR-006**: El sistema DEBE marcar los productos con actividad **posterior** a la importación, advirtiendo que la diferencia puede no ser atribuible a ella.
- **FR-007**: Una corrida **sin filas de snapshot** DEBE informarse como *"sin detalle disponible"*, nunca como *"sin cambios"*.
- **FR-008**: Un producto **eliminado** después de la importación DEBE mostrarse identificado como tal.
- **FR-009**: Una corrida **deshecha** DEBE indicarse como tal, con su fecha.
- **FR-010**: Los usuarios DEBEN poder ver el detalle producto por producto, además del resumen.
- **FR-011**: El informe es de **sólo lectura**: no modifica productos, precios ni stock.

### Funcionales — archivo descargable (US2)

- **FR-012**: El sistema DEBE conservar el archivo subido asociándolo a su corrida.
- **FR-013**: Los usuarios DEBEN poder descargarlo desde el historial, con su nombre original.
- **FR-014**: La descarga DEBE exigir el mismo permiso que la pantalla de importación.
- **FR-015**: El historial DEBE distinguir tres estados: **archivo disponible**, **nunca se guardó** (corrida anterior a esta feature) y **vencido por antigüedad**.
- **FR-016**: No poder guardar el archivo NO DEBE hacer fallar la importación.
- **FR-017**: Dos corridas del mismo nombre de archivo DEBEN conservar cada una su copia.

### Funcionales — limpieza (US3)

- **FR-018**: El sistema DEBE eliminar los archivos más viejos que el plazo configurado.
- **FR-019**: El plazo DEBE ser configurable, con **90 días** por defecto.
- **FR-020**: Al eliminar un archivo, su corrida DEBE quedar marcada como vencida, conservando todos sus demás datos.
- **FR-021**: La limpieza DEBE eliminar también los archivos sueltos sin corrida asociada.
- **FR-022**: La limpieza NO DEBE tocar archivos de importaciones en curso.

### Transversales

- **FR-023**: Las pantallas DEBEN respetar las especificaciones de diseño obligatorias del proyecto: tablas DataTables con carga por AJAX, detalle en modal Bootstrap sin recargar la página, notificaciones por toast y Select2 en los selects de datos dinámicos.
- **FR-024**: Nada de esta feature DEBE modificar el importador ni el flujo de importación.

### Key Entities

- **Archivo de la corrida**: la copia del archivo subido. Pertenece a una corrida; guarda dónde está, su nombre original, su tamaño y si sigue disponible, se venció o nunca se guardó.
- **Informe de cambios**: no es una entidad persistida, se calcula al pedirlo comparando el estado anterior guardado contra el actual. Agrupa por campo, por lista de precios y por depósito.
- **Plazo de conservación**: cuántos días se guardan los archivos. Único para el sistema.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ante un movimiento de stock originado en una importación, se puede llegar desde el historial a qué cambió esa corrida **sin consultar la base de datos**. Verificable reproduciendo el caso del −181 de EMBALAJE JPD.
- **SC-002**: El informe distingue los productos que la importación cambió de los que no: sobre la corrida real del 28/08 informa 18 con cambios sobre 192 filas, no 192.
- **SC-003**: Una corrida sin snapshot nunca se presenta como "sin cambios".
- **SC-004**: Un producto con actividad posterior está visiblemente marcado, de modo que nadie le atribuya a la importación un cambio que no hizo.
- **SC-005**: El archivo de cualquier importación dentro del plazo se descarga íntegro desde el historial.
- **SC-006**: El espacio ocupado por archivos de importación deja de crecer sin techo: los anteriores al plazo se eliminan solos, incluidos los 23 huérfanos actuales.
- **SC-007**: Ninguna importación falla por un problema al guardar o al limpiar archivos.

## Assumptions

- **El snapshot es suficiente y no hay que capturar nada nuevo.** Verificado: guarda estado del producto, precios y stock, sobrevive al vencimiento del deshacer, y ocupa 1,33 MB en 1.605 filas. Si en el futuro se purgaran los snapshots vencidos, esta feature perdería su fuente — conviene no hacerlo sin revisar esto.
- **El informe compara puntas, no reconstruye la historia.** Un valor que cambió y volvió no se detecta. La alternativa —guardar también el estado posterior— duplicaría el almacenamiento para un caso marginal.
- **90 días de conservación** cubren un trimestre de auditoría. A 9 MB por tres semanas, son unos 40 MB en régimen: irrelevante frente al valor de poder rastrear una importación.
- **Sólo Productos.** Es la única entidad con corrida y snapshot; el resto no tiene de dónde sacar el informe.
- **El historial ya existe** con su tabla y su acción de deshacer; esta spec le agrega columnas y una pantalla de detalle, no la reemplaza.
- Los archivos huérfanos actuales **no se asocian retroactivamente** a ninguna corrida: sus nombres UUID no permiten saber a cuál pertenecen. Se eliminan.

## Out of Scope

- Importaciones de entidades que no sean Productos.
- Cambiar el importador, el mapeo de columnas o el flujo de confirmación.
- Guardar el estado **posterior** de cada fila para un informe exacto e inmune a cambios posteriores.
- Deshacer parcial, o deshacer una corrida ya vencida.
- Comparar dos corridas entre sí.
- Exportar el informe a Excel o PDF.
