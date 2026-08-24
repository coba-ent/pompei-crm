# Feature Specification: Fidelidad del Informe de Ventas contra Contagram

**Feature Branch**: `076-fidelidad-informe-ventas`

**Created**: 2026-08-24

**Status**: Draft

**Input**: Contraste del Informe de Ventas del CRM contra Contagram real (captura de pantalla del
24/08/2026 y cuatro exports reales), que reveló un botón faltante, una columna con un valor
incorrecto y tres divergencias de contenido de columna.

---

## Contexto: por qué existe esta spec

El 24/08/2026 se compararon, sobre el **mismo período** (01/07/2026), el export "Informe de Ventas
Resumen" de Contagram y el nuestro. Los ocho KPIs de los dos primeros bloques cuadran al centavo.
El detalle, no: además de la diferencia de CMV —ya resuelta por la spec 075 para las ventas
nuevas—, aparecieron cuatro divergencias estructurales.

La más grave la reveló una captura de la pantalla real de Contagram: **la columna "Total
Comprobante" no repite el total de la venta en cada línea, lo prorratea**. En la venta 23501, que
tiene 12 líneas, Contagram muestra 12 valores distintos que suman exactamente $1.349.647,48 —el
total del comprobante—, mientras el CRM muestra $1.349.647,46 doce veces.

Esto contradice lo que el proyecto tenía documentado. `docs/documentacion_principal_crm.md` §
Informe de Ventas afirma que esa columna va *"repetido por fila, **no sumable**"*, y la spec 068 lo
fijó con un test que lo llama *"la trampa principal del informe"*. **El relevamiento original se
equivocó**: en Contagram esa columna sí es sumable, y sumarla da el total del período. Es el mismo
tipo de error de premisa que la spec 075 corrigió para el CMV, y se corrige igual: contra el dato
real, dejando escrito qué decía antes y por qué estaba mal.

**Evidencia usada** (toda verificable, en el repo o aportada por el cliente):

| Fuente | Qué prueba |
|---|---|
| Captura de pantalla del Informe de Ventas de Contagram (01/07/2026) | Los tres botones al pie; el prorrateo en pantalla; el contenido de "Comprobante" y "Prod./Serv."; el formato contable de negativos |
| `Informe_de_Ventas_Detallado_24-08-2026_1429_Hs.xlsx` | Estructura exacta del export detallado: una hoja, 3 bloques de KPIs, 44 columnas |
| `Informe_de_Ventas_Resumen_24-08-2026_1406_Hs.xlsx` | El export resumen de Contagram, para el contraste columna por columna |
| `migracion-nueva/excel-origen/Ventas/Ventas 20XX.xlsx` (2021-2026) | Seis años de exports detallados reales, misma estructura de 44 columnas |

---

## Clarifications

### Session 2026-08-24

Ninguna de estas preguntas se le trasladó al cliente: todas se resolvieron contra los archivos y la
captura reales, que es una fuente más confiable que su memoria. Quedan asentadas porque cambian los
tests y el plan.

- Q: ¿El export resumen pone bien el tipo de comprobante? → A: **No.** Contagram escribe la sigla
  completa (`FCA`, `FCB`, `FC`, `NCA`, `NCB`, `NC`) y el CRM escribe sólo la letra (`A`, `B`).
  Verificado sobre los dos exports del 01/07/2026. Se suma como FR-021.
- Q: ¿El formato contable de negativos aplica también al Excel y al PDF? → A: **Sólo a la
  pantalla.** En los exports reales de Contagram los negativos vienen como números negativos
  crudos (`-44618.78`), no como texto entre paréntesis, y tiene que seguir siendo así para que las
  celdas se puedan sumar en Excel. FR-016 queda acotado a la pantalla.
- Q: ¿Cómo se prorratean los conceptos extra (percepciones, impuestos internos, intereses) en el
  importe de cada línea? → A: **En proporción al neto de cada línea**, el mismo criterio que ya usa
  el sistema para repartir un descuento general cargado como monto fijo. El requisito verificable
  sigue siendo FR-002: la suma tiene que cerrar contra el total del comprobante.
- Q: ¿Qué importe lleva una nota migrada sin detalle de ítems, que aporta una sola fila? → A: **Su
  monto completo**, con el signo que le corresponda. Es el único caso en que "importe de línea" e
  "importe del comprobante" coinciden, y es correcto que así sea.
- Q: ¿Qué valores concretos llevan las columnas nuevas del detallado que no existen hoy? → A:
  tomados del archivo real — **ARCA**: `Aprobado`, `Sin Enviar` o `---` cuando no corresponde;
  **Afecta Stock**: `Si` / `No`; **Punto de Venta** y **N° Factura**: `-` cuando el comprobante no
  fue emitido; **Lista de Precios**: vacío cuando la venta no tiene lista asignada.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El dueño ve el importe real de cada línea de venta (Priority: P1)

El dueño abre el Informe de Ventas y mira una venta con varios productos. Hoy la columna "Total
Comprobante" le muestra el mismo número gigante repetido en todas las líneas, así que no puede
saber cuánto pesó cada producto dentro de la venta, y si suma la columna le da un total inflado
tantas veces como líneas tenga cada venta. Después de este cambio, cada línea muestra **su propio**
importe con impuestos, y la columna suma el total del período.

**Why this priority**: es lo único de esta spec que muestra un dato incorrecto en una pantalla que
el dueño usa para decidir. Un informe cuya columna de dinero no se puede sumar es un informe en el
que no se puede confiar, y además hoy invita al error de sumarla igual.

**Independent Test**: abrir el informe con una venta de varias líneas y verificar que los importes
de esas líneas son distintos entre sí y suman el total del comprobante. Se puede validar sin tocar
nada del export detallado.

**Acceptance Scenarios**:

1. **Given** una venta con 12 líneas cuyo total es $1.349.647,48, **When** el dueño mira el detalle
   del informe, **Then** ve 12 importes distintos que suman $1.349.647,48.
2. **Given** una venta de una sola línea, **When** el dueño mira el detalle, **Then** el importe de
   esa línea es igual al total de la venta.
3. **Given** un período con ventas y notas, **When** el dueño suma la columna de todo el detalle,
   **Then** obtiene el mismo Total Ventas que muestra el KPI de arriba.
4. **Given** una nota de crédito, **When** el dueño la mira en el detalle, **Then** su importe sale
   en negativo, con el mismo criterio de signo que el resto de sus columnas.

---

### User Story 2 - El contador se lleva el informe con el desglose impositivo (Priority: P1)

El contador necesita del Informe de Ventas mucho más que las 12 columnas de la pantalla: el CUIT de
cada cliente, el estado en ARCA, el punto de venta y el número de factura, el código de cada
producto, su proveedor, y sobre todo el **desglose impositivo completo** —neto gravado, exento y no
gravado, el IVA abierto por las cinco alícuotas, percepciones e impuestos internos—. Hoy tiene que
pedírselo a Contagram porque el CRM no lo exporta.

**Why this priority**: es la razón por la que el cliente todavía vuelve a Contagram para cerrar el
mes. Mientras falte, el CRM no reemplaza a la herramienta original para la tarea contable, que es
el objetivo del proyecto.

**Independent Test**: exportar el detallado de un período y comparar el archivo contra el export
equivalente de Contagram del mismo período: mismas columnas, mismo orden, mismos valores.

**Acceptance Scenarios**:

1. **Given** un período con ventas, **When** el usuario usa la exportación detallada, **Then**
   recibe un archivo con las mismas 44 columnas, en el mismo orden y con los mismos rótulos que el
   de Contagram.
2. **Given** ese mismo archivo, **When** el usuario lo abre, **Then** encuentra arriba los mismos
   tres bloques de KPIs que ya trae el export resumen, y el detalle debajo.
3. **Given** una venta con IVA al 21%, **When** el contador mira su línea, **Then** el importe está
   en la columna de esa alícuota y las otras cuatro están en cero.
4. **Given** una línea de concepto libre sin producto de catálogo, **When** el contador la mira,
   **Then** las columnas de código, tipo de producto y proveedor salen vacías, sin romper la fila.
5. **Given** los filtros y el rango de fechas aplicados en pantalla, **When** el usuario exporta,
   **Then** el archivo contiene exactamente las mismas filas que la pantalla.

---

### User Story 3 - Las columnas del detalle dicen lo mismo que en Contagram (Priority: P2)

Tres columnas del detalle muestran algo distinto de lo que muestra Contagram: donde Contagram
escribe el tipo de operación ("Venta", "Nota de Crédito"), el CRM escribe el tipo y número de
comprobante; donde Contagram identifica el producto con su código adelante, el CRM pone sólo el
nombre; y los importes negativos, que Contagram muestra en rojo entre paréntesis, el CRM los
muestra con un signo menos.

**Why this priority**: no hay datos incorrectos, sólo presentados distinto. Molesta al comparar
ambas herramientas en paralelo —que es lo que el cliente está haciendo hasta el lanzamiento— pero
no lleva a una decisión equivocada.

**Independent Test**: comparar una captura del detalle del CRM contra la de Contagram del mismo
período y verificar que las tres columnas coinciden.

**Acceptance Scenarios**:

1. **Given** una venta en el detalle, **When** el usuario mira la columna de comprobante, **Then**
   lee "Venta"; **y Given** una nota de crédito, **Then** lee "Nota de Crédito".
2. **Given** una línea de un producto del catálogo, **When** el usuario mira la columna de
   producto, **Then** ve el código del producto antes del nombre.
3. **Given** una línea de nota de crédito, **When** el usuario mira sus importes, **Then** los ve
   en rojo y entre paréntesis.

---

### Edge Cases

- **Venta con descuento general**: la suma de los importes de línea tiene que seguir dando el total
  del comprobante, con el descuento ya prorrateado en cada línea.
- **Venta con percepciones, impuestos internos o intereses**: esos conceptos viven a nivel
  comprobante y no en una línea. La suma de la columna por venta tiene que seguir cerrando contra
  el total.
- **Nota de crédito o débito migrada sin detalle de ítems**: hoy aporta una fila con cantidades en
  cero pero con su monto, que es lo que alimenta el KPI. Ese comportamiento no puede romperse.
- **Línea sin producto de catálogo** (concepto libre): no tiene código, tipo ni proveedor.
- **Producto sin proveedor, cliente sin CUIT, venta sin vendedor o sin categoría**: columnas vacías,
  nunca una fila rota ni un cero que se confunda con un importe.
- **Comprobante no enviado a ARCA**: la columna de estado tiene que distinguir "sin enviar" de
  "aprobado" y de "no corresponde".
- **Período grande**: exportar varios miles de líneas con 44 columnas no puede degradar ni
  interrumpir la descarga.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Importe por línea (US1)

- **FR-001**: La columna de importe total del detalle MUST mostrar el importe **de esa línea** con
  impuestos incluidos, no el total del comprobante.
- **FR-002**: La suma de esa columna sobre todas las líneas de un mismo comprobante MUST ser igual
  al total de ese comprobante, incluyendo descuento general y conceptos extra.
- **FR-003**: El comportamiento MUST ser idéntico en la pantalla, el export resumen, el export
  detallado y el PDF. Ninguna de las cuatro salidas puede quedar con el criterio viejo.
- **FR-004**: Las líneas de nota de crédito MUST llevar el importe en negativo y las de nota de
  débito en positivo, con el mismo criterio de signo que ya rige el resto de sus columnas.
- **FR-005**: La documentación de dominio MUST corregirse: hoy afirma que esta columna va "repetido
  por fila, no sumable", y es falso. La corrección DEBE dejar registrado qué decía antes, por qué
  estaba mal y con qué evidencia se corrigió.

#### Exportación detallada (US2)

- **FR-006**: El Informe de Ventas MUST ofrecer una tercera acción de exportación, además de las dos
  que ya tiene, ubicada y rotulada como en Contagram.
- **FR-007**: El archivo generado MUST tener **una sola hoja**, a diferencia del export resumen, que
  tiene dos por una divergencia deliberada del módulo.
- **FR-008**: El archivo MUST abrir con los mismos tres bloques de KPIs que el export resumen, antes
  del detalle.
- **FR-009**: El detalle MUST tener exactamente estas 44 columnas, en este orden y con estos
  rótulos: Id, Emisión, Vencimiento, Categoría, Cliente, CUIT / DNI, ARCA, Tipo, Tipo de
  Comprobante, Punto de Venta, N° Factura, Vendedor, Producto/Servicio, Código, Tipo, Proveedor,
  Cantidad, Precio Unitario, Costo Total Actual, CMV Total, Lista de Precios, Precio de Venta,
  Resultado, Subtotal sin Descuento, Descuento en $, Subtotal con Descuento, Importe Neto No
  Gravado, Importe Neto Exento, Importe Neto Gravado, IVA - 2,5%, IVA - 5%, IVA - 10,5%, IVA - 21%,
  IVA - 27%, Exento, No Gravado, Perc. IVA, Perc. IIBB, Imp. Internos, Total Venta, Etiquetas, Nota
  para el Cliente, Nota Interna, Afecta Stock.
- **FR-010**: El export detallado MUST respetar el rango de fechas y todos los filtros activos en la
  pantalla, igual que las dos exportaciones que ya existen.
- **FR-010a**: Las columnas de fecha del export MUST escribirse como **fecha de Excel**, no como
  texto, para que el contador pueda ordenarlas y filtrarlas por fecha. Es así en los archivos de
  Contagram, y es una divergencia observada en nuestro export resumen, que las escribe como texto.
- **FR-011**: El importe neto de cada línea MUST imputarse a **una sola** de las tres columnas de
  neto (gravado, exento o no gravado) según la condición de IVA de esa línea, y el IVA a **una
  sola** de las cinco columnas de alícuota.
- **FR-011a**: Una línea cuya condición de IVA sea nula, vacía o **no reconocida** MUST imputarse a
  *Importe Neto No Gravado* y no aportar IVA, en lugar de quedar fuera de las tres columnas. El
  criterio es que ninguna línea puede desaparecer del desglose: si el dato está mal, el importe
  tiene que verse igual en alguna columna.
- **FR-011b**: Las columnas del desglose impositivo sin valor MUST escribirse como **cero**, no como
  celda vacía. En Excel no son lo mismo: una columna con celdas vacías intercaladas rompe el
  autosuma y las tablas dinámicas del contador. Es así en el archivo real de Contagram.
- **FR-012**: Las columnas de percepciones e impuestos internos MUST reflejar los conceptos extra
  del comprobante con el mismo criterio con el que se los prorratea en el importe de línea
  (FR-002).
- **FR-013**: El export detallado MUST usar el mismo motor de datos que la pantalla y las otras dos
  exportaciones, de modo que sus totales coincidan al centavo con los KPIs mostrados.

#### Contenido de columnas del detalle (US3)

- **FR-014**: La columna de comprobante del detalle MUST mostrar el **tipo de operación** —"Venta",
  "Nota de Crédito", "Nota de Débito"— y no el tipo y número de comprobante.
- **FR-015**: **En la pantalla**, la columna de producto MUST mostrar el **código del producto
  seguido del nombre** para las líneas de catálogo, y sólo la descripción para las de concepto
  libre. El alcance es la pantalla y **sólo** la pantalla: en el export detallado el código va en su
  **propia columna** (la 14) y la de producto lleva sólo el nombre, y el export resumen conserva el
  formato que ya tiene. Aplicar el formato de pantalla a los archivos rompería la comparabilidad
  celda a celda contra Contagram, que es el objetivo de la spec.
- **FR-016**: Los importes negativos MUST mostrarse **en pantalla** con formato contable: en rojo y
  entre paréntesis. En los archivos exportados MUST seguir siendo números negativos, para que las
  celdas se puedan sumar.
- **FR-017**: Las 12 columnas del detalle en pantalla MUST conservar sus rótulos, su orden y su
  cantidad actuales. Esta spec cambia **contenido**, no estructura de columnas.
- **FR-021**: La columna de tipo de comprobante de los exports MUST usar la sigla completa que usa
  Contagram —`FCA`, `FCB`, `FC` para ventas y `NCA`, `NCB`, `NC` para notas— y no la letra sola.
  Aplica al export resumen, que hoy escribe sólo la letra, y al detallado, que además tiene una
  columna aparte con la letra.

#### Alcance explícitamente excluido

- **FR-018**: Las pestañas "Rankings" y "Arma tu Informe" quedan fuera de alcance; siguen siendo una
  divergencia deliberada ya registrada.
- **FR-019**: La corrección de la bonificación por línea no aplicada en la importación queda fuera
  de alcance; se resuelve por separado, sin spec, y no bloquea nada de acá.
- **FR-020**: El export resumen MUST conservar su estructura de dos hojas. La única corrección que
  recibe es la del importe de línea (FR-003).

### Key Entities

Esta feature **no crea ni modifica entidades**. Consume las que ya existen: las ventas y sus ítems,
las notas de crédito y débito y sus ítems, los conceptos extra del comprobante, los productos con su
código, tipo y proveedor, los clientes con su identificación fiscal, los vendedores, las categorías,
las listas de precio, las etiquetas y el comprobante fiscal de ARCA.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Sumar la columna de importe de línea sobre todo el detalle de un período da el mismo
  Total Ventas que muestra el KPI, con una diferencia menor a un centavo. Hoy, en un período con
  ventas de varias líneas, esa suma da varias veces el valor real.
- **SC-002**: El export detallado de un período contrastado contra el de Contagram del mismo período
  coincide en cantidad de columnas, rótulos y orden en el 100% de los casos.
- **SC-003**: Sobre las ventas cuyo costo ya quedó congelado, los importes del export detallado
  coinciden con los de Contagram con una diferencia menor al 0,1%.
- **SC-004**: Las cuatro salidas del informe —pantalla, export resumen, export detallado y PDF—
  muestran el mismo importe para la misma línea, sin excepción.
- **SC-005**: El contador puede obtener el desglose impositivo del período sin entrar a Contagram.
- **SC-006**: Un período de 5.000 líneas se exporta completo en las 44 columnas, sin interrupción ni
  pérdida de filas, en un tiempo comparable al del export resumen que ya existe.
- **SC-007**: El tipo de comprobante de cada fila exportada coincide con la sigla que usa Contagram
  en el 100% de las filas.

---

## Assumptions

- **La captura del 24/08/2026 es fuente de verdad para la estructura de pantalla**, por encima de
  `docs/documentacion_principal_crm.md`, que en este punto está demostrado que se equivoca. Es el
  criterio que ya fija el principio rector del proyecto ante conflicto entre un relevamiento con
  capturas y la documentación.
- **El importe de línea es neto con descuentos aplicados, más el IVA de esa línea, más los conceptos
  extra del comprobante prorrateados.** Se deriva de los exports reales: en una línea gravada al 21%
  el importe es el neto más su IVA, y en una línea no gravada es el neto solo. La forma exacta de
  prorratear los conceptos extra se define al planificar, con el requisito de que la suma cierre
  contra el total del comprobante (FR-002).
- **La columna "Tipo" aparece dos veces en el export detallado** —una para el comprobante y otra
  para el tipo de producto—. Es así en el archivo real de Contagram y se replica tal cual, aunque
  duplicar un rótulo sea una mala práctica: el objetivo es que los dos archivos sean comparables.
- **Los rótulos del export difieren de los de la pantalla** (Emisión/Fecha, Precio de Venta/Precio
  Total Neto, Total Venta/Total Comprobante). Es así en Contagram y ya está replicado en el export
  resumen; el detallado sigue el mismo criterio.
- **La sigla de las notas de débito no está verificada contra un archivo real.** Se confirmaron
  `FCA`/`FCB`/`FC` para ventas y `NCA`/`NCB`/`NC` para notas de crédito sobre los exports del
  01/07/2026, que no contienen ninguna nota de débito. Se asume `NDA`/`NDB`/`ND` por simetría. Si
  al validar aparece una nota de débito real con otra sigla, se corrige sin necesidad de revisar la
  spec.
- **El Informe de Compras no está en alcance**, aunque su export tenga un desglose impositivo
  parecido. Si al implementar aparece que comparte el mismo defecto de importe por línea, se
  registra como brecha y se trata aparte.
- **El identificador que se muestra sigue siendo el del CRM**, no el de Contagram. El número de
  origen sólo se usa internamente para contrastar durante la migración, y no debe aparecer en
  ninguna salida que vea el cliente.
