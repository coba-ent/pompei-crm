# Feature Specification: Información para tu Contador (Libro IVA Ventas / Compras)

**Feature Branch**: `077-informe-contador-iva`

**Created**: 2026-08-24

**Status**: Draft

**Input**: User description: Informe "Información para tu Contador" (Libro IVA Ventas / Libro IVA Compras), calcando la pantalla `/accountant_reports` de Contagram relevada con capturas el 24/08/2026 en `docs/informe_contagram_contador/`.

---

## Contexto y fuente de verdad

Este informe **existe en Contagram real** y está relevado con capturas reales del 24/08/2026 sobre la
cuenta del cliente (Pompei Sanitarios): `docs/informe_contagram_contador/informe_accountant_reports.md`
más 7 capturas de pantalla. Ese relevamiento es la **fuente de verdad estructural** de esta spec
(principio rector de `CLAUDE.md`): columnas, orden, filtros, totales y comportamiento salen de ahí, no
de una interpretación.

Ubicación: **novena tarjeta** del hub de Informes, con la descripción *"Obtené con un click toda la
información que necesita tu contador para el cálculo de tus impuestos."* No estaba en el relevamiento
del módulo Informes del 14/08/2026 (que documentó 8 tarjetas), por eso quedó fuera de las tandas 1 y 2.

Divergencia estructural respecto de los otros informes ya construidos, **deliberada y tomada del
relevamiento**: acá el período **no se precarga**. Compras y Gastos arrancan en el mes actual; este
informe arranca vacío y exige elegir Mes y Año explícitamente.

---

## Clarifications

### Session 2026-08-24

Resueltas contrastando las capturas del relevamiento (fuente de verdad estructural), sin necesidad de
consultar al usuario: en los cinco casos las capturas contienen la respuesta o el proyecto ya tiene un
criterio establecido en las specs 067/068.

- Q: ¿Las casillas "Aprobadas por ARCA" / "Manuales" existen también en IVA COMPRAS? → A: **No, sólo en
  IVA VENTAS.** La captura de IVA Compras (`05_iva_compras_agosto2026_datos_reales.jpg`) muestra la
  tabla inmediatamente debajo de la barra de totales, sin casillas; la de IVA Ventas
  (`07_...jpg`) sí las muestra. Es coherente con el dominio: el CAE lo obtiene **el emisor**, y en
  Compras el emisor es el proveedor, así que la distinción "firme ante ARCA vs. manual" no aplica a un
  comprobante recibido. *(Corrige el texto del relevamiento, que decía que Compras "replica exactamente
  la misma lógica" — ante conflicto entre texto y captura, manda la captura.)*
- Q: ¿Cómo se define **Total Facturado**: como la suma exacta de los otros cuatro totales, o de forma
  independiente? → A: **Como la suma exacta de los otros cuatro.** Contagram lo calcula por separado y
  eso le produce una deriva de redondeo: en la captura de IVA Ventas los componentes suman
  `$3.230.106,22` pero muestra `$3.230.106,21`. Se **corrige deliberadamente** (mismo criterio que la
  spec 067 aplicó al signo de las NC): un informe para liquidar impuestos no puede mostrar una ecuación
  que no cierra.
- Q: ¿**Imp. Internos** e **Imp. Municipales** participan del Total Facturado? → A: **No.** Se listan
  como columnas por comprobante pero quedan fuera de la ecuación de totales, tal como muestran las
  capturas (en IVA Compras los cuatro componentes suman exactamente el Total Facturado sin ellos). En
  consecuencia, cuando un comprobante tiene impuestos internos su total real es mayor que lo que aporta
  al Total Facturado, y eso es correcto.
- Q: ¿Entran al libro los comprobantes anulados o eliminados? → A: **No.** Se excluyen los eliminados
  (borrado lógico), igual que en los informes de Ventas y Compras ya implementados, para que los tres
  informes concilien.
- Q: ¿Cuál es el orden por defecto del listado? → A: **Fecha de emisión ascendente, y a igual fecha por
  Id ascendente.** Es como se lee un libro de IVA y coincide con el orden observado en las capturas
  (03/08, 04/08, 04/08, …).

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Obtener el Libro IVA Ventas de un período (Priority: P1)

El responsable administrativo entra al informe, elige un mes y un año, y obtiene el listado de todos
los comprobantes de venta de ese período con su desglose impositivo completo (netos por clase, IVA por
alícuota, percepciones), más una barra de totales que cierra la ecuación del período. Con eso puede
entregarle al contador la base para liquidar el IVA sin tener que abrir venta por venta.

**Why this priority**: es el motivo de existir del informe y el caso de uso más frecuente (una vez por
mes, al cierre). Sin esto no hay informe; con sólo esto ya hay valor entregable.

**Independent Test**: cargar ventas de un mes con distintas alícuotas de IVA, entrar al informe, elegir
ese mes/año, y verificar que aparecen todas las ventas con los importes desglosados y que la barra de
totales cierra `No Gravados/Exentos + Gravados + IVA Total + Perc. IVA/IIBB Total = Total Facturado`.

**Acceptance Scenarios**:

1. **Given** el informe recién abierto sin período elegido, **When** el usuario mira la pantalla,
   **Then** la tabla está vacía con el mensaje "Utilizá los filtros y generá tu informe a medida", los
   cinco totales muestran `$ 0,00` y no se ejecutó ninguna consulta de datos.
2. **Given** ventas de agosto 2026 con IVA 21% y 10,5%, **When** el usuario elige Mes = Agosto y
   Año = 2026, **Then** la tabla lista esas ventas y cada una muestra su importe en la columna de la
   alícuota que le corresponde, con `$0,00` en las demás.
3. **Given** un período con ventas cargadas, **When** se muestran los totales, **Then**
   `Total Facturado` es exactamente la suma de los otros cuatro totales, sin diferencias por redondeo.
4. **Given** un período elegido, **When** el usuario cambia de mes, **Then** la tabla y los totales se
   recalculan sin recargar la página.
5. **Given** un período sin ningún comprobante, **When** se genera el informe, **Then** la tabla informa
   que no hay comprobantes en el período y los totales quedan en `$ 0,00`.

---

### User Story 2 - Obtener el Libro IVA Compras respetando la imputación contable (Priority: P2)

El usuario cambia a la pestaña "IVA COMPRAS" y obtiene el mismo libro, pero del lado de las compras:
proveedores en vez de clientes, y —clave— el período se determina por el **mes de imputación de IVA
Compras** que se cargó en cada compra (el campo "Contador"), no por su fecha de emisión. Así una factura
de proveedor recibida tarde se computa en el período fiscal que corresponde.

**Why this priority**: es la mitad del informe y la razón por la que el campo "Contador" existe en el
formulario de Compras. Va en P2 y no en P1 sólo porque IVA Ventas es entregable por sí solo.

**Independent Test**: cargar una compra con fecha de emisión en un mes y `mes_imputacion_iva` en el
siguiente, y verificar que aparece en el período de imputación y **no** en el de emisión.

**Acceptance Scenarios**:

1. **Given** una compra emitida el 28/07/2026 con mes de imputación agosto 2026, **When** el usuario
   genera IVA Compras de agosto 2026, **Then** la compra aparece en el listado.
2. **Given** esa misma compra, **When** el usuario genera IVA Compras de julio 2026, **Then** la compra
   **no** aparece.
3. **Given** una compra sin mes de imputación cargado, **When** se genera el informe, **Then** la compra
   se computa por su fecha de emisión.
4. **Given** compras con notas de crédito y débito asociadas, **When** se genera el informe, **Then** las
   notas aparecen como filas propias con su tipo de comprobante (NCA/NDA) y su importe con el signo que
   corresponde (crédito resta, débito suma).
5. **Given** la pestaña IVA Compras activa, **When** el usuario mira las columnas y filtros, **Then** ve
   "Proveedor" y "Medio de Pago" donde IVA Ventas muestra "Cliente" y "Medio de Cobro".

---

### User Story 3 - Separar lo que está firme fiscalmente de lo que no (Priority: P2)

En **IVA VENTAS**, el usuario necesita distinguir qué comprobantes emitidos ya están validados por ARCA
(tienen CAE aprobado) de los que se cargaron a mano o siguen esperando aprobación. Con dos checkboxes
decide qué universo mira, y tanto la tabla como los totales se ajustan a esa decisión.

**Why this priority**: para el contador esta distinción es determinante — no es lo mismo lo firme ante
el fisco que lo informal o pendiente. Es lo primero que se mira antes de dar un número por bueno.

**Alcance**: aplica **sólo a IVA VENTAS**. En IVA COMPRAS el comprobante lo emite el proveedor —el CAE
no es nuestro— y por eso las casillas no existen en esa pestaña (ver Clarifications).

**Independent Test**: cargar en un mismo período una venta con CAE aprobado y otra sin comprobante
fiscal, y verificar que cada checkbox las incluye o excluye correctamente, y que los totales acompañan.

**Acceptance Scenarios**:

1. **Given** el informe recién abierto en IVA VENTAS, **When** el usuario mira los checkboxes, **Then**
   "Facturas Aprobadas por ARCA" está tildado y "Facturas Manuales (NO enviadas a ARCA o Esperando
   Aprobación de ARCA)" está destildado.
2. **Given** un período con 3 ventas con CAE y 2 sin CAE, **When** sólo está tildado "Aprobadas por
   ARCA", **Then** la tabla muestra 3 filas y los totales corresponden únicamente a esas 3.
3. **Given** ese mismo período, **When** el usuario tilda además "Facturas Manuales", **Then** la tabla
   muestra las 5 y los totales corresponden a las 5.
4. **Given** ese mismo período, **When** el usuario destilda los dos checkboxes, **Then** la tabla queda
   vacía y los totales en `$ 0,00`.
5. **Given** una venta cuyo envío a ARCA fue rechazado, **When** el usuario tilda sólo "Facturas
   Manuales", **Then** la venta aparece (no tiene CAE aprobado, así que no es firme).
6. **Given** la pestaña IVA COMPRAS activa, **When** el usuario mira la pantalla, **Then** **no** hay
   casillas de ARCA/Manuales: la tabla arranca inmediatamente debajo de la barra de totales y el informe
   incluye todos los comprobantes de compra del período.

---

### User Story 4 - Acotar el informe a un comprobante, cliente o condición de IVA (Priority: P3)

Cuando el contador pregunta por un comprobante puntual o el usuario necesita revisar sólo un cliente,
una condición de IVA o una provincia, abre el panel de filtros y acota el listado dentro del período
elegido. También puede ocultar las columnas que no le interesan para que la tabla entre en pantalla.

**Why this priority**: mejora sustancialmente el uso pero el informe ya sirve sin esto: el período más
la exportación cubren el flujo principal.

**Independent Test**: con varios comprobantes en el período, filtrar por condición de IVA = Responsable
Inscripto y verificar que el listado y los totales se acotan sólo a esos.

**Acceptance Scenarios**:

1. **Given** un período con comprobantes de Consumidor Final y de Responsable Inscripto, **When** el
   usuario filtra por Condición de IVA = Responsable Inscripto y presiona Buscar, **Then** la tabla y
   los totales incluyen sólo esos comprobantes.
2. **Given** filtros aplicados, **When** el usuario cambia el mes, **Then** los filtros siguen vigentes
   sobre el nuevo período.
3. **Given** la tabla visible, **When** el usuario destilda una columna en el selector de columnas,
   **Then** la columna desaparece de la tabla sin recargar la página y sin alterar los totales.
4. **Given** un filtro de N° de CUIT parcial, **When** el usuario presiona Buscar, **Then** se listan
   los comprobantes cuyo CUIT/DNI contiene esa secuencia.

---

### User Story 5 - Llevarse el informe en Excel (Priority: P3)

El usuario exporta el informe del período —con los filtros que tenga aplicados— a un archivo Excel para
mandárselo al contador o archivarlo.

**Why this priority**: es la forma concreta en que el informe llega al contador, pero se apoya en todo
lo anterior y no aporta valor sin ello.

**Independent Test**: generar un período, exportar, y verificar que el archivo trae exactamente los
mismos comprobantes e importes que la pantalla.

**Acceptance Scenarios**:

1. **Given** un período generado con filtros aplicados, **When** el usuario exporta, **Then** el archivo
   contiene los mismos comprobantes que la pantalla, con los mismos importes y en el mismo orden.
2. **Given** un informe con columnas ocultas en pantalla, **When** el usuario exporta, **Then** el
   archivo trae **todas** las columnas del desglose, no sólo las visibles.
3. **Given** la pestaña activa, **When** el usuario exporta, **Then** el archivo corresponde a esa
   pestaña (Ventas o Compras) y su nombre lo identifica junto con el período.
4. **Given** un período sin período elegido, **When** el usuario intenta exportar, **Then** el sistema
   avisa que primero debe elegir Mes y Año y no genera archivo.

---

### Edge Cases

- **Período sin elegir**: la pantalla no ejecuta ninguna consulta ni muestra totales; exportar está
  bloqueado con aviso.
- **Los dos checkboxes destildados (sólo IVA Ventas)**: universo vacío; tabla y totales en cero (no es un
  error).
- **Compra sin mes de imputación**: se computa por fecha de emisión, sin quedar fuera del informe.
- **Compra con mes de imputación anterior al período de emisión** (regularización retroactiva): se
  computa en el mes imputado, aunque sea anterior a su emisión.
- **Comprobante con varios intentos contra ARCA** (uno rechazado y uno aprobado): cuenta **una sola vez**
  y como aprobado; no se duplica la fila ni los importes.
- **Venta con más de un cobro, con medios distintos**: al filtrar por Medio de Cobro aparece si **alguno**
  de sus cobros usa ese medio; no se duplica la fila.
- **Comprobante sin CUIT/DNI cargado** (consumidor final sin identificar): aparece con la celda vacía,
  no se excluye del informe.
- **Nota de crédito que deja el total del período en negativo**: el total se muestra en negativo, no se
  fuerza a cero.
- **Período con miles de comprobantes**: la tabla pagina y los totales siguen correspondiendo al período
  completo, no a la página visible.
- **Cambio de pestaña con filtros cargados**: cada pestaña mantiene su propio estado de filtros; lo
  cargado en Ventas no se arrastra a Compras.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Acceso y estructura de pantalla

- **FR-001**: El sistema DEBE ofrecer el informe "Información para tu Contador" como una entrada más del
  módulo Informes, con la descripción "Obtené con un click toda la información que necesita tu contador
  para el cálculo de tus impuestos."
- **FR-002**: El informe DEBE tener dos pestañas, **IVA VENTAS** (activa por defecto) e **IVA COMPRAS**,
  con idéntica estructura de totales, filtros, tabla, paginación y exportación.
- **FR-003**: El acceso DEBE estar restringido a los usuarios con permiso de ver informes, igual que el
  resto del módulo.
- **FR-004**: Cambiar de pestaña, de período, de filtros o de columnas visibles NO DEBE recargar la
  página en ningún caso.

#### Selección de período

- **FR-005**: El sistema DEBE ofrecer dos selectores separados: **Mes** (Enero a Diciembre) y **Año**.
- **FR-005a**: El selector de **Año** DEBE ofrecer los años en los que hay comprobantes cargados, desde el
  más antiguo hasta el actual. No DEBE ofrecer años vacíos ni una lista fija arbitraria.
- **FR-006**: Al abrir el informe, el período DEBE estar **sin seleccionar**, la tabla vacía con el
  mensaje "Utilizá los filtros y generá tu informe a medida", y los totales en `$ 0,00`. El informe
  **no** se precarga automáticamente.
- **FR-007**: Mientras no haya período elegido, el sistema NO DEBE consultar datos ni permitir exportar.
- **FR-008**: En **IVA VENTAS**, el período de una venta DEBE determinarse por su **fecha de emisión**
  (una venta no tiene mes de imputación propio: el campo "Contador" es exclusivo de Compras).
- **FR-009**: En **IVA COMPRAS**, el período de una compra DEBE determinarse por su **mes de imputación
  de IVA Compras** (campo "Contador"); cuando ese dato no está cargado, DEBE usarse la fecha de emisión
  como respaldo.
- **FR-009a**: En **ambas pestañas**, el período de una **nota de crédito/débito** DEBE determinarse por
  su **mes de imputación** propio, que la nota siempre tiene cargado (se precarga con el mes de su fecha
  de emisión al crearla). Este campo se creó justamente para este informe, así que el informe DEBE
  respetarlo y no volver a la fecha de emisión de la nota.

#### Totales del período

- **FR-010**: El sistema DEBE mostrar cinco totales en este orden, con los operadores visuales que los
  relacionan: **No Gravados / Exentos** `+` **Gravados** `+` **IVA Total** `+` **Perc. IVA/IIBB Total**
  `=` **Total Facturado**.
- **FR-011**: **Total Facturado** DEBE definirse como la suma exacta de los otros cuatro totales, de
  modo que la ecuación cierre siempre sin diferencias por redondeo. *(Divergencia deliberada: Contagram
  lo calcula por separado y arrastra una deriva de centavos — ver Clarifications.)*
- **FR-011a**: **Imp. Internos** e **Imp. Municipales** NO DEBEN participar de la ecuación de totales,
  aunque sí se listen como columnas por comprobante. En consecuencia, un comprobante con impuestos
  internos aporta al Total Facturado menos que su total real, y eso es el comportamiento correcto.
- **FR-011b**: El **orden de redondeo** DEBE ser: redondear a 2 decimales el importe de cada componente
  **por comprobante**, sumar esos importes ya redondeados para obtener cada uno de los cuatro totales, y
  recién entonces sumar los cuatro para el Total Facturado. Fijar el orden es necesario porque sumar
  primero y redondear al final puede diferir en centavos, y eso es justamente lo que FR-011 prohíbe.
- **FR-012**: Los totales DEBEN calcularse sobre **todo el conjunto filtrado** del período, nunca sobre
  la página visible de la tabla.
- **FR-013**: Los totales DEBEN reflejar los mismos filtros, período y —en IVA Ventas— estado de los
  checkboxes que la tabla, en todo momento.

#### Universo de comprobantes (ARCA vs. manuales) — sólo IVA VENTAS

- **FR-014**: En **IVA VENTAS**, el sistema DEBE ofrecer dos casillas independientes y acumulativas:
  **"Facturas Aprobadas por ARCA"** (tildada por defecto) y **"Facturas Manuales (NO enviadas a ARCA o
  Esperando Aprobación de ARCA)"** (destildada por defecto).
- **FR-014a**: En **IVA COMPRAS** estas casillas NO DEBEN existir, y el informe DEBE incluir todos los
  comprobantes de compra del período: el comprobante lo emite el proveedor, así que la validación ante
  ARCA no es un atributo propio de la operación.
- **FR-015**: "Aprobadas por ARCA" DEBE incluir exclusivamente los comprobantes con validación fiscal
  firme (CAE aprobado).
- **FR-016**: "Facturas Manuales" DEBE incluir toda venta del período que **no** tenga validación fiscal
  firme: las nunca enviadas a ARCA, las que esperan aprobación y las rechazadas.
- **FR-017**: Las dos categorías DEBEN ser mutuamente excluyentes y cubrir el universo completo del
  período: con ambas tildadas, ninguna venta queda afuera ni se cuenta dos veces.
- **FR-018**: Una venta con varios intentos contra ARCA DEBE computarse **una sola vez**, según su
  estado vigente.
- **FR-019**: Con las dos casillas destildadas, la tabla DEBE quedar vacía y los totales en cero, sin
  tratarlo como error.

#### Tabla de comprobantes

- **FR-020**: La tabla DEBE mostrar **una fila por comprobante** (no por ítem), con estas columnas en
  este orden exacto: Id, Emisión, Tipo, N° de Comprobante, Cliente *(Proveedor en IVA Compras)*,
  CUIT / DNI, Condición de IVA, Importe Neto No Gravado, Importe Neto Exento, Importe Neto Gravado,
  IVA 2,5%, IVA 5%, IVA 10,5%, IVA 21%, IVA 27%, Perc. IVA, Perc. IIBB, Imp. Internos, Imp. Municipales.
- **FR-020a**: La columna **Tipo** DEBE mostrar, para ventas y compras, su tipo de comprobante tal cual
  (`FEA`, `FEB`, `FA`, `FB`, …), y para notas DEBE componerse como `NC`/`ND` seguido de la letra del
  comprobante que ajustan (`NCA`, `NDA`, `NCB`, …).
- **FR-020b**: La columna **Condición de IVA** DEBE mostrar la condición vigente en la ficha del cliente
  o proveedor. *(Limitación conocida: el sistema no guarda hoy un snapshot de la condición fiscal en el
  comprobante, así que un cambio en la ficha altera retroactivamente el libro de un período ya cerrado.
  Queda anotado como brecha; resolverlo excede esta spec.)*
- **FR-021**: El desglose de netos, IVA por alícuota, percepciones e impuestos internos DEBE reutilizar
  las reglas de clasificación impositiva ya vigentes en el sistema, sin reimplementar el cálculo.
- **FR-022**: Las notas de crédito y débito DEBEN aparecer como filas propias, con su tipo de comprobante
  y su importe con el signo que corresponde: crédito resta, débito suma.
- **FR-022c**: El importe de una nota de crédito/débito DEBE desglosarse en neto e IVA por alícuota —
  **no** puede emitirse con las columnas impositivas en cero. Un libro IVA con las notas sin discriminar
  subdeclara IVA: crédito fiscal perdido en Compras, débito fiscal no declarado en Ventas.
- **FR-022d**: Ese desglose DEBE resolverse en este orden de precedencia:
  1. Si la nota tiene impuestos de IVA cargados, se usan **esos** — es el dato que cargó el usuario.
  2. Si no, la nota **hereda la alícuota del comprobante que ajusta**, y su importe se parte en neto más
     IVA con esa alícuota.
  3. Si el comprobante ajustado combina **varias** alícuotas, el importe se reparte entre ellas en
     proporción al neto de cada alícuota en ese comprobante.
  4. Si no hay comprobante ajustado identificable, el importe entero va a **No Gravado** — el
     tratamiento conservador, que no inventa crédito ni débito fiscal.

  Cada una de las cuatro ramas DEBE tener test propio.
- **FR-022a**: El listado DEBE ordenarse por defecto por **fecha de emisión ascendente** y, a igual
  fecha, por Id ascendente — el orden en que se lee un libro de IVA.
- **FR-022b**: Los comprobantes eliminados (borrado lógico) DEBEN quedar **excluidos** del informe, igual
  que en los informes de Ventas y Compras ya implementados.
- **FR-023**: La tabla DEBE paginar en el servidor, con selector de "Registros por página", cantidad
  total de resultados y navegación por páginas incluyendo "Ir a la página".
- **FR-023a**: La columna **N° de Comprobante** en IVA Compras DEBE leerse del número cargado en la
  compra, **no** del comprobante fiscal — el comprobante lo emitió el proveedor con su propia numeración.
  *(El proyecto ya tuvo este bug exacto y lo corrigió en el commit `723b7a24`; no repetirlo.)*
- **FR-024**: El sistema DEBE mostrar al pie la leyenda de última actualización con la fecha y hora en
  que **se generó la consulta que se está viendo** (no la última modificación de los datos), y DEBE
  refrescarse cada vez que se regenera el informe.
- **FR-025**: El sistema DEBE ofrecer un selector de columnas visibles con una casilla por columna, que
  muestre u oculte columnas sin recargar la página y sin alterar los totales.

#### Filtros

- **FR-026**: El panel de filtros DEBE ofrecer estos ocho campos: **Id**, **Tipo de Comprobante**,
  **N° de Comprobante**, **Cliente** *(Proveedor en IVA Compras)*, **N° de CUIT**, **Condición de IVA**,
  **Medio de Cobro** *(Medio de Pago en IVA Compras)* y **Provincia**, más un botón **Buscar**.
- **FR-027**: Los filtros DEBEN combinarse entre sí de forma restrictiva (todos deben cumplirse) y
  aplicarse siempre **dentro** del período elegido.
- **FR-028**: Los filtros de texto libre (N° de Comprobante, N° de CUIT) DEBEN buscar por coincidencia
  parcial.
- **FR-029**: Al cambiar el período, los filtros ya cargados DEBEN mantenerse y aplicarse al nuevo
  período.
- **FR-030**: Cada pestaña DEBE mantener su propio estado de filtros, período y columnas visibles, sin
  contaminar el de la otra.
- **FR-031**: Filtrar por Medio de Cobro/Pago DEBE incluir el comprobante cuando **alguno** de sus
  cobros/pagos usa ese medio, sin duplicar la fila.

#### Exportación

- **FR-032**: El sistema DEBE ofrecer un botón **Exportar** que genere un archivo de planilla con el
  contenido del informe de la pestaña activa.
- **FR-033**: El archivo exportado DEBE respetar el período, los filtros y el estado de los checkboxes
  vigentes en pantalla.
- **FR-034**: El archivo exportado DEBE incluir **todas** las columnas del desglose, independientemente
  de cuáles estén ocultas en pantalla.
- **FR-035**: El nombre del archivo DEBE identificar el libro (Ventas o Compras) y el período exportado.
- **FR-036**: Intentar exportar sin período elegido DEBE avisar al usuario y no generar archivo.

#### Sólo lectura

- **FR-037**: El informe DEBE ser de **sólo lectura**: no crea, modifica ni elimina ningún comprobante,
  cliente, proveedor ni dato fiscal. *(El endpoint de datos usa POST por un motivo de transporte —evitar
  el límite de largo de URL— no porque escriba: no persiste nada.)*
- **FR-038**: Los totales de este informe **no tienen por qué coincidir** con los del Informe de Ventas o
  de Compras para el mismo mes, y la diferencia es legítima: aquellos informes acotan por **fecha de
  emisión** y éste por **período de imputación fiscal**, además de excluir impuestos internos de la
  ecuación. Esta diferencia DEBE quedar documentada para que nadie la lea como un descuadre.

### Key Entities

- **Comprobante del libro**: una fila del informe. Representa una venta, una compra o una nota de
  crédito/débito, con su fecha, tipo, número, contraparte (cliente o proveedor), identificación fiscal,
  condición de IVA y su desglose impositivo agregado a nivel comprobante.
- **Período fiscal**: el par mes/año que acota el informe. Se resuelve contra la fecha de emisión en
  Ventas y contra el mes de imputación de IVA Compras (con respaldo en la emisión) en Compras.
- **Estado de validación fiscal**: la condición del comprobante frente a ARCA — firme (con CAE aprobado)
  o no firme (nunca enviado, esperando aprobación o rechazado). Determina en cuál de los dos universos
  cae el comprobante.
- **Desglose impositivo del comprobante**: los importes que componen el comprobante, agrupados en netos
  por clase (no gravado, exento, gravado), IVA discriminado por alícuota (2,5%, 5%, 10,5%, 21%, 27%),
  percepciones (IVA, IIBB), impuestos internos e impuestos municipales.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: El usuario obtiene el Libro IVA de un mes cerrado en **menos de 3 interacciones** desde el
  hub de Informes (abrir el informe, elegir mes, elegir año).
- **SC-002**: La ecuación de totales cierra **exactamente** (diferencia cero) en el 100% de los períodos,
  incluidos los que tienen notas de crédito, notas de débito y percepciones.
- **SC-003**: Un comprobante con mes de imputación distinto al de emisión aparece en el período imputado
  y **no** en el de emisión, en el 100% de los casos.
- **SC-004**: En IVA Ventas, la suma de comprobantes de los dos universos (aprobados por ARCA + manuales)
  es igual a la cantidad total de ventas del período, sin faltantes ni duplicados.
- **SC-005**: Un período de 12 meses con el volumen real del negocio (~30 comprobantes de venta y ~20 de
  compra por mes) se genera y pagina **sin degradación perceptible** para el usuario.
- **SC-006**: Los importes del archivo exportado coinciden **exactamente** con los mostrados en pantalla
  para el mismo período y filtros.
- **SC-007**: El informe reemplaza la necesidad de abrir comprobantes de a uno: el usuario obtiene el
  detalle impositivo completo del período **sin navegar a ninguna otra pantalla**.

---

## Fuera de alcance

Estas tres capacidades **existen en Contagram real** y están relevadas en las capturas, pero quedan
deliberadamente fuera de esta spec. Se documentan como brechas explícitas para no perderlas de vista:

- **"Exportar IVA Digital"**: genera los archivos en el formato de ancho fijo que exige el Libro IVA
  Digital de ARCA. Requiere fijar la versión del diseño de registro, el mapeo de códigos de comprobante y
  sus validaciones — merece spec propia.
- **"Enviar Info. a mi Contador"**: envía el informe por correo al contador de la cuenta. Requiere
  configurar los datos del contador y un circuito de envío hacia afuera; se evaluará junto con el módulo
  de Notificaciones ya pendiente.
- **"Video Explicativo"**: material de ayuda propio de Contagram, sin equivalente en este proyecto.

---

## Assumptions

- **Impuestos Municipales**: la columna existe en la pantalla relevada, pero el sistema **no** modela hoy
  un concepto de impuesto municipal diferenciado. Se emite en cero, manteniendo la columna para no
  divergir estructuralmente de Contagram, y se documenta la brecha en la documentación de dominio. Si el
  negocio empieza a cargar ese concepto, la columna ya está.
- **Partición del universo fiscal (IVA Ventas)**: se asume que "manual" es toda venta sin CAE aprobado —
  nunca enviada, pendiente **o rechazada**. Es la única partición que hace que las dos casillas cubran el
  universo completo sin solapamiento (FR-017), lo que a su vez hace verificable el total del período.
- **Provincia**: se asume la provincia **fiscal** de la contraparte, con respaldo en la provincia
  comercial cuando la fiscal no está cargada — es la que corresponde a un libro de IVA.
- **Medio de Cobro / Medio de Pago**: se asume que corresponde a la cuenta de tesorería usada en los
  cobros de la venta o los pagos de la compra, que es donde el sistema registra hoy ese dato.
- **Granularidad**: se asume una fila por comprobante (no por ítem), como muestran las capturas. El
  desglose impositivo se agrega a nivel comprobante a partir del detalle de ítems.
- **Notas de crédito/débito**: se asume el mismo criterio de signo ya vigente en los informes de Ventas y
  Compras (crédito resta, débito suma), para que los tres informes concilien entre sí.
- **Reutilización**: se asume que el desglose impositivo se apoya en las reglas ya implementadas para los
  informes de Ventas y Compras, de modo que los tres informes no puedan divergir en la clasificación de
  una misma operación.

---

## Dependencias

- Requiere el módulo de **Facturación Electrónica** ya implementado (estados de comprobante y CAE), que
  es lo que permite separar comprobantes firmes de manuales.
- Requiere el campo **"Contador"** (mes de imputación de IVA Compras) del formulario de Compras, ya
  existente en el modelo.
- Requiere el **mes de imputación de las notas de crédito/débito**, creado por la spec 045 con el
  propósito explícito de alimentar este informe. Esta spec es su primer consumidor real.
- Requiere las reglas de **desglose impositivo** ya implementadas para los informes de Ventas y Compras.
- Requiere el catálogo de **provincias** y las **condiciones de IVA**, ya existentes.
