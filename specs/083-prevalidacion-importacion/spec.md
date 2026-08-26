# Feature Specification: Prevalidación y confirmación previa de la importación

**Feature Branch**: `083-prevalidacion-importacion`

**Created**: 2026-08-26

**Status**: Draft

**Input**: Prevalidación y confirmación previa del asistente de Importar Datos, más la corrección de
los cuatro defectos detectados el 26/08/2026 al importar una planilla real de 148 productos del
proveedor Ferrum.

## Contexto: qué pasó

Al probar el asistente con una planilla real aparecieron cuatro defectos. Ninguno es teórico: los
cuatro se observaron sobre datos reales, y uno ya está reproducido con un test.

| # | Qué pasó | Consecuencia observada |
|---|---|---|
| 1 | El `.xlsx` traía **fórmulas sin calcular** (`=+B2&" "&A2`) en la columna Código/SKU | 124 productos quedaron con el **texto de la fórmula como código** |
| 2 | La misma planilla tenía fórmulas sin calcular en una columna de precio | 24 filas fallaron con un mensaje **en inglés** que nombraba un campo interno |
| 3 | La exportación escribe el encabezado `Precio venta`; el importador espera `Precio de Venta` | Los 124 productos se crearon con **precio de venta 0** |
| 4 | Una importación abandonada dejó su acumulado en la sesión | El resumen informó **"1000 registros importados correctamente"** cuando **no se importó nada** |

El hilo común: **el usuario se entera de todo cuando ya está escrito**. El asistente pide confirmar
sin decir qué va a pasar, y después informa un resultado que en un caso era directamente falso.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ver qué va a pasar antes de que pase (Priority: P1)

Quien importa una planilla quiere saber, **antes de tocar nada**, cuántos registros va a crear,
cuántos va a actualizar y si hay filas con problemas. Hoy confirma a ciegas y recién al final
descubre que 24 filas fallaron o que 124 productos entraron con el precio en cero.

**Why this priority**: es el pedido central y el que previene los otros defectos. Un paso de
confirmación que muestre "124 altas, 0 actualizaciones, 24 filas con error" habría evitado los cuatro
problemas de arriba antes de escribir una sola fila.

**Independent Test**: subir una planilla con filas buenas y malas y verificar que el modal informa los
conteos correctos y el detalle de los errores, sin haber creado ni modificado ningún registro.

**Acceptance Scenarios**:

1. **Given** una planilla de 148 filas sin Id mapeado y todas las filas válidas, **When** el usuario
   pide importar, **Then** el modal muestra "148 altas, 0 actualizaciones, 0 errores" y deja confirmar.
2. **Given** una planilla donde 100 filas traen Id de registros existentes y 48 no, **When** se abre el
   modal, **Then** muestra "48 altas, 100 actualizaciones, 0 errores".
3. **Given** que hay actualizaciones, **When** se abre el modal, **Then** muestra **qué campos se van a
   modificar** en esos registros existentes — por su etiqueta visible — y **cuántos registros cambia
   cada uno** (ej. "Costo: 100 registros · Precio venta: 100 · Stock Local: 43").
4. **Given** una planilla con 24 filas inválidas, **When** se abre el modal, **Then** muestra el detalle
   de las 24 filas con su número y su motivo, y **el botón de confirmar está bloqueado**.
5. **Given** el modal abierto, **When** el usuario mira el estado del sistema, **Then** no se creó ni se
   modificó ningún registro: la prevalidación no escribe nada.
6. **Given** una planilla que la prevalidación dio por completamente válida, **When** el usuario
   confirma, **Then** ninguna fila falla durante la importación real.
7. **Given** el modal abierto, **When** el usuario lo cierra o cancela, **Then** vuelve al mapeo con su
   selección intacta y sin haber tocado ningún dato.

---

### User Story 2 - Entender el error sin ser programador (Priority: P2)

Cuando una fila falla, el motivo tiene que decir qué columna está mal —con el nombre que el usuario
ve— y qué se espera, en español.

**Why this priority**: sin esto, el bloqueo de la US1 se vuelve una pared: el usuario ve que no puede
importar pero no entiende qué corregir. Depende de la US1 para tener dónde mostrarse, pero es lo que
la hace accionable.

**Independent Test**: forzar filas inválidas de cada tipo y verificar que todos los mensajes están en
español y nombran la columna por su etiqueta visible.

**Acceptance Scenarios**:

1. **Given** una celda no numérica en la columna que el usuario mapeó como "AHORA 3", **When** se
   reporta el error, **Then** el mensaje nombra **"AHORA 3"** y está en español (hoy dice
   *"The precio lista 2 field must be a number"*).
2. **Given** cualquier fila inválida, **When** se muestra su motivo, **Then** el texto no contiene
   nombres internos de campo (`precio_lista_2`, `tipo_producto_id`) ni palabras en inglés.
3. **Given** una fila con varios errores, **When** se reporta, **Then** se indican todos, no sólo el primero.

---

### User Story 3 - Que una planilla exportada se pueda reimportar (Priority: P2)

Quien exporta productos, edita el Excel y lo vuelve a subir espera que **todas** las columnas se
reconozcan solas. Hoy "Precio venta" queda sin mapear y los precios se van a cero sin aviso.

**Why this priority**: es el flujo de trabajo real (exportar → editar → reimportar) y el defecto
tiene consecuencia directa sobre dinero. No bloquea a la US1, se puede entregar por separado.

**Independent Test**: exportar productos, reimportar el archivo sin tocar nada y verificar que todas
las columnas quedan automapeadas y que ningún valor cambia.

**Acceptance Scenarios**:

1. **Given** un archivo recién exportado desde Productos, **When** se sube al asistente, **Then**
   **todas** sus columnas quedan automapeadas, incluida "Precio venta".
2. **Given** ese mismo archivo reimportado sin modificar, **When** termina la importación, **Then**
   ningún producto cambió de precio de venta, costo, stock ni ningún otro valor.
3. **Given** que alguien agrega una columna nueva a la exportación sin darle su correspondencia en la
   importación, **When** corre la suite de tests, **Then** un test falla señalando la columna huérfana.

---

### User Story 4 - Un resumen en el que se pueda confiar (Priority: P2)

El resultado que muestra el asistente tiene que corresponder a **esa** importación: su archivo, su
fecha y sus números.

**Why this priority**: un resumen que miente es peor que no tener resumen — el usuario del incidente
creyó que había importado 1000 registros cuando no había importado ninguno. Es independiente de las
otras historias.

**Independent Test**: abandonar una importación a mitad, hacer otra, y verificar que el resumen de la
segunda informa sólo lo suyo.

**Acceptance Scenarios**:

1. **Given** una importación abandonada a mitad que dejó 1000 filas contabilizadas, **When** el
   usuario hace una importación nueva de 2 registros, **Then** el resumen informa **2**, no 1002.
2. **Given** cualquier importación terminada, **When** se muestra el resumen, **Then** aparecen el
   **nombre del archivo** y la **fecha y hora** de esa corrida, además de los conteos.
3. **Given** que el usuario abandona el asistente a mitad y vuelve a entrar más tarde, **When**
   arranca una importación nueva, **Then** no arrastra nada de la anterior.

---

### Edge Cases

- **Una sola fila mala en un archivo de 9.000**: por la regla de bloqueo (FR-005), el archivo entero
  queda sin importar hasta corregir esa fila. Ver *Cambio de comportamiento deliberado*.
- **Fórmula que no se puede evaluar** (referencia a otra hoja, referencia circular, función no
  soportada): la celda no puede guardarse como el texto de la fórmula; queda reportada como error de
  esa fila.
- **Archivo sin ninguna fila válida**: se informan todos los errores y no se puede confirmar.
- **Archivo sólo con encabezados**: la prevalidación informa 0 altas, 0 actualizaciones, 0 errores.
- **El archivo cambia entre la prevalidación y la confirmación** (el usuario sube otro en otra
  pestaña): la confirmación se rechaza; no se importa contra una prevalidación que ya no corresponde.
- **Un registro se borra o se modifica entre la prevalidación y la confirmación**: una fila prevista
  como actualización pasa a ser alta (o al revés). Los conteos mostrados eran correctos al momento de
  calcularlos; el resumen final es el que manda.
- **Prevalidación de un archivo grande**: no puede dejar el modal colgado sin señal de progreso.
- **Cientos o miles de filas con error**: el modal muestra un detalle desplazable y el total real, sin
  intentar renderizar 10.000 renglones de una.
- **Actualización que no cambia nada**: una fila cuyo valor es idéntico al que ya está guardado cuenta
  como actualización, pero conviene que el listado de campos no la infle — se resuelve al implementar.
- **Dos columnas del archivo mapeadas al mismo campo**: ya lo rechaza el Paso 2 y se mantiene.

## Requirements *(mandatory)*

### Paso de confirmación con prevalidación

- **FR-001**: El asistente DEBE mostrar, entre el mapeo y la escritura, un **modal de confirmación**
  que informe cuántas filas se van a **crear**, cuántas se van a **actualizar** y cuántas tienen
  **error**, antes de escribir nada.
- **FR-002**: La prevalidación NO DEBE crear, modificar ni borrar ningún registro.
- **FR-003**: La prevalidación DEBE aplicar **exactamente las mismas reglas** que la importación real.
  Una fila que la prevalidación da por válida no puede fallar después.
- **FR-004**: El detalle de errores DEBE indicar, por cada fila con problema, su **número de fila en
  el archivo** y el **motivo**.
- **FR-005**: Si hay al menos una fila con error, el modal DEBE **impedir la confirmación** hasta que
  el archivo se corrija y se vuelva a subir.
- **FR-005b**: Cuando haya filas que actualizan registros existentes, el modal DEBE informar **qué
  campos se van a modificar** y **a cuántos registros afecta cada uno**, nombrando los campos por su
  etiqueta visible. Es lo que permite darse cuenta de que se está por pisar algo que no se quería.
- **FR-005c**: El modal DEBE poder cerrarse sin efecto: al cancelar, el usuario vuelve al mapeo con su
  selección intacta y sin que se haya modificado ningún dato.
- **FR-006**: Con cero errores, el asistente DEBE permitir confirmar y proceder como hoy.
- **FR-007**: La prevalidación DEBE poder procesar un archivo de al menos 10.000 filas sin agotar el
  tiempo del servidor ni dejar la pantalla sin señal de avance.
- **FR-008**: La prevalidación DEBE informar su avance mientras corre.
- **FR-009**: El sistema DEBE rechazar una confirmación cuyo archivo o mapeo ya no se corresponda con
  la prevalidación mostrada.
- **FR-010**: El detalle de errores DEBE poder revisarse completo aunque sean cientos de filas, sin
  volver el modal inmanejable (área desplazable y, si hace falta, un límite visible con el total real).

### Fórmulas de Excel sin calcular

- **FR-011**: Al interpretar el archivo subido, el sistema DEBE **calcular las fórmulas** y quedarse
  con el valor resultante, no con el texto de la fórmula.
- **FR-012**: Una fórmula que no se pueda evaluar NO DEBE guardarse como texto en ningún campo: esa
  fila DEBE quedar reportada como error por la prevalidación, indicando la columna afectada.
- **FR-013**: Ningún campo de texto DEBE aceptar como valor una celda que empiece con `=` proveniente
  de una fórmula sin resolver.

### Correspondencia entre exportación e importación

- **FR-014**: **Toda** columna que escribe la exportación de Productos DEBE quedar automapeada al
  reimportar ese archivo, sin intervención manual.
- **FR-015**: Lo mismo DEBE valer para las exportaciones de Clientes y Proveedores, si existen.
  **Verificado el 26/08/2026 durante la implementación (T036): no existen.** La única exportación de
  datos reimportables es `ProductosExport`; no hay `ClientesExport` ni `ProveedoresExport`.
  `RoundTripExportImportTest::test_no_existe_export_de_clientes_ni_de_proveedores()` lo deja fijado,
  para que si alguien agrega una haya que extenderle también el chequeo de columnas huérfanas.
- **FR-016**: DEBE existir una verificación automática que falle si alguien agrega una columna a una
  exportación sin su correspondencia en la importación.
- **FR-017**: Reimportar un archivo recién exportado, sin modificarlo, NO DEBE cambiar ningún valor.

### Mensajes de error

- **FR-018**: Todo motivo de fila fallida DEBE estar **en español**.
- **FR-019**: Todo motivo DEBE nombrar la columna con la **etiqueta que el usuario ve** en el mapeo o
  el encabezado del archivo, nunca con el nombre interno del campo.
- **FR-020**: Cuando una fila tiene varios problemas, DEBEN informarse todos.

### Integridad del resumen

- **FR-021**: El resumen DEBE informar **únicamente** el resultado de la importación que acaba de
  terminar.
- **FR-022**: El estado de una importación abandonada NO DEBE sobrevivir para contaminar la siguiente.
- **FR-023**: El resumen DEBE mostrar el **nombre del archivo** y la **fecha y hora** de esa corrida.
- **FR-024**: Los números del resumen DEBEN coincidir con lo que efectivamente quedó en el sistema.

### No regresión

- **FR-025**: Se DEBEN preservar el procesamiento por tandas y la retoma de la spec 082.
- **FR-026**: Se DEBE preservar el deshacer de la spec 078, y una importación con prevalidación previa
  DEBE seguir siendo deshacible.
- **FR-027**: Se DEBEN preservar las reglas de alta/actualización por Id de la spec 027 y las reglas
  de mapeo del Paso 2.
- **FR-028**: El comportamiento DEBE ser el mismo en las tres solapas: Clientes, Proveedores y
  Productos & Servicios.

## Cambio de comportamiento deliberado ⚠️

Hasta hoy el asistente es **tolerante por fila** (specs 006/026): una fila inválida se omite, se
reporta en el resumen y el resto del archivo se importa igual. **FR-005 cambia esa regla**: con una
sola fila con error, no se puede importar nada.

Es una **decisión explícita del usuario** (26/08/2026), tomada después del incidente en el que
entraron 124 productos con el código y el precio mal. El criterio es que es preferible corregir el
archivo antes que quedar con datos malos adentro.

**Consecuencia a tener presente**: en un archivo de 9.000 filas, una sola fila con un dato inválido
—por ejemplo un CUIT mal tipeado en Clientes— impide importar las 8.999 restantes hasta corregirla.
Queda registrado acá, no como objeción sino para que la decisión sea informada y se pueda revisar
más adelante si molesta en el uso diario.

## Success Criteria *(mandatory)*

- **SC-001**: Quien importa sabe cuántos registros va a crear y cuántos va a actualizar **antes** de
  que se escriba nada.
- **SC-002**: Ninguna planilla con filas inválidas llega a escribir datos: el sistema la frena antes.
- **SC-002b**: Antes de confirmar una actualización masiva, el usuario puede ver **qué campos** se van a
  pisar y **a cuántos registros**, sin tener que abrir el Excel para deducirlo.
- **SC-003**: De las filas que la prevalidación aprueba, **el 100%** se importa sin fallar.
- **SC-004**: Ninguna celda con una fórmula sin calcular queda guardada como dato: el caso real de
  los 124 códigos `=+B2&" "&A2` no puede volver a pasar.
- **SC-005**: Un archivo exportado y reimportado sin cambios deja **cero** diferencias.
- **SC-006**: El 100% de los motivos de error está en español y nombra columnas por su etiqueta visible.
- **SC-007**: El resumen coincide siempre con lo efectivamente importado, incluso después de abandonar
  una importación a mitad.
- **SC-008**: La prevalidación de un archivo de 10.000 filas termina con progreso visible y sin
  cortarse.
- **SC-009**: Cero regresiones sobre las specs 006/026/027/074/078/082.

## Key Entities

- **Informe de prevalidación**: resultado transitorio del análisis de un archivo contra un mapeo —
  cuántas altas, cuántas actualizaciones, **qué campos se modifican y a cuántos registros**, y qué
  filas fallan y por qué. Vive lo que dura el asistente y
  nunca toca la base, igual que el archivo subido y el mapeo (invariante de §2.4).
- **Resultado de importación**: lo que informa el resumen. Deja de ser un número suelto y pasa a estar
  atado a una corrida concreta, con su archivo y su fecha.

## Assumptions

- La prevalidación se apoya en el archivo ya interpretado una sola vez por la spec 082; no vuelve a
  abrir el Excel.
- Corregir el archivo implica volver a subirlo: no se editan filas desde la aplicación.
- Los conteos de altas y actualizaciones se calculan contra el estado de la base al momento de
  prevalidar; el resumen final es la fuente de verdad de lo que pasó.
- "Fórmula que no se puede evaluar" incluye referencias externas, referencias circulares y funciones
  no soportadas.
- Las exportaciones de Clientes y Proveedores pueden no existir todavía; si no existen, FR-015 no
  aplica y se deja registrado.

## Out of Scope

- El motor de tandas y la retoma de la spec 082: se usan como están.
- El deshacer de la spec 078: se preserva sin cambios.
- Editar o corregir filas desde la aplicación.
- Importar entidades distintas de Clientes, Proveedores y Productos & Servicios.

## Dato de comportamiento registrado (no es un defecto)

El **"Deshacer"** del historial no borra los productos creados: los deja **inactivos**, **no libera
los ids** del contador de la tabla y **no borra las filas de precios**. Después de deshacer una
importación de 124 productos, la base queda con 124 productos inactivos y sus precios. Se documenta
porque sorprendió durante las pruebas; **cambiarlo no forma parte de esta spec**.
