# Feature Specification: Los totales de los Excel de informes siguen al filtro de Excel

**Feature Branch**: `114-informes-totales-filtrables`
**Created**: 2026-10-02
**Status**: En curso — Tanda 1 (Ventas Detallado) implementada
**Input**: Continuación de la spec 113. Resuelto el Ranking, aplicar el mismo criterio al resto de los exports del módulo Informes, módulo por módulo, verificando en cada uno dónde es viable y dónde generaría un número incorrecto.

## Contexto

La spec 113 arregló el Excel de Rankings: los totales pasaron de ser números fijos calculados en el
servidor a fórmulas `SUBTOTAL(109)`, que es la única función de Excel que ignora las filas ocultas
por un autofiltro. Filtrando por un proveedor, el total pasa a ser el de ese proveedor.

El resto de los exports del módulo comparten el mismo patrón —totales fijos, sin autofiltro— y la
misma limitación de origen. Esta spec los recorre uno por uno.

**La regla que ordena todo el trabajo**: un total sólo puede convertirse en fórmula si es la suma de
una columna del detalle. Si el valor se repite en varias filas del mismo comprobante, sumarlo por
fila da un número inflado, y entonces el cambio sería peor que el problema. Cada informe exige
medirlo contra la base antes de decidir, no suponerlo.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Filtrar un informe exportado y que los totales acompañen (Priority: P1)

El usuario exporta un informe, lo abre en Excel, filtra por una columna cualquiera (proveedor,
cliente, vendedor) y lee los totales del encabezado. Los que son suma de una columna muestran el
total de lo filtrado.

**Why this priority**: es el pedido que originó el trabajo y el que destraba el uso real del archivo
exportado sin tener que volver al CRM a re-exportar por cada corte.

**Independent Test**: exportar un informe, filtrar por un valor y verificar que el total coincide con
el que da el CRM aplicando ese mismo filtro en la pantalla.

**Acceptance Scenarios**:

1. **Given** un informe exportado sin filtros, **When** el usuario lo abre, **Then** los totales
   coinciden con los KPIs de la pantalla.
2. **Given** ese archivo, **When** el usuario filtra por un proveedor, **Then** los totales que son
   suma de columna pasan a ser los de ese proveedor.
3. **Given** el filtro aplicado, **When** el usuario lo quita, **Then** los totales vuelven al valor
   del informe completo sin re-exportar.

---

### User Story 2 - El archivo viene listo para filtrar (Priority: P2)

El usuario abre el archivo y la fila de encabezados ya tiene los desplegables de filtro, sin tener
que seleccionar el rango a mano.

**Why this priority**: seleccionar mal el rango es donde el usuario se equivoca y deja filas afuera.
Además, el rango correcto excluye las filas de totales, que si entraran ensuciarían el desplegable.

**Acceptance Scenarios**:

1. **Given** un archivo exportado, **When** el usuario lo abre, **Then** la fila de encabezados del
   detalle tiene el filtro activo.
2. **Given** el filtro activo, **When** el usuario despliega una columna, **Then** la lista no
   incluye valores provenientes de las filas de totales ni de los bloques de KPIs.

---

### User Story 3 - Queda claro qué total no responde al filtro (Priority: P2)

Los totales que no pueden recalcularse están rotulados de forma que el usuario lo entiende sin
consultar documentación.

**Why this priority**: un total que no se mueve al filtrar parece un error del sistema. Es
exactamente la confusión que originó este trabajo, y el rótulo la evita.

**Acceptance Scenarios**:

1. **Given** un archivo filtrado, **When** el usuario mira un total que no responde al filtro,
   **Then** su rótulo indica que corresponde al informe completo.

---

### Edge Cases

- **Informe sin filas**: el archivo no debe romperse ni dejar fórmulas con rangos inválidos.
- **Una sola fila**: el total tiene que dar exactamente esa fila.
- **Filtro sin resultados**: los totales filtrables quedan en cero, no en error.
- **Valores negativos** (notas de crédito): siguen restando dentro del filtro.
- **Redondeo**: el total por fórmula suma celdas ya redondeadas a 2 decimales, mientras que el KPI
  suma en SQL sin redondear. La diferencia es de centavos y es el comportamiento correcto para un
  número que vive en la planilla: suma exactamente lo que el usuario ve.
- **Apertura en otra herramienta** (LibreOffice, Google Sheets): `SUBTOTAL` es estándar y funciona
  igual; el archivo debe seguir siendo válido.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Todo KPI que sea la suma de una columna del detalle DEBE escribirse como fórmula que
  recalcule al aplicar un filtro en Excel.
- **FR-002**: Antes de convertir un KPI, su condición de sumable DEBE verificarse midiendo la suma
  por fila contra el KPI del informe sobre datos reales. Sólo se convierte si coinciden.
- **FR-003**: Un KPI cuyo valor se repite entre las filas de un mismo comprobante NO DEBE
  convertirse: conserva el valor del informe completo.
- **FR-004**: Los KPIs no convertidos DEBEN estar rotulados de forma que el usuario entienda que no
  responden al filtro.
- **FR-005**: El archivo DEBE abrirse con el filtro activado sobre el encabezado del detalle y sus
  filas, excluyendo las filas de KPIs y la de totales.
- **FR-006**: Sin filtro aplicado, cada total DEBE coincidir con el KPI de la pantalla, salvo
  diferencias de redondeo de centavos.
- **FR-007**: La estructura del archivo —hojas, posición de los bloques, orden y cantidad de
  columnas— NO DEBE cambiar: replica los exports de Contagram y hay procesos del propio sistema que
  leen estos archivos por posición.
- **FR-008**: El trabajo DEBE hacerse por tandas, un informe o grupo por vez, con su verificación
  propia; no se aplica un cambio global a todos los exports a la vez.

### Key Entities

- **Total filtrable**: KPI que es la suma de una columna del detalle y puede seguir al filtro.
- **Total del informe**: KPI que no se puede derivar sumando filas visibles, porque el dato se
  repite entre las líneas de un mismo comprobante.

## Success Criteria *(mandatory)*

- **SC-001**: Filtrando por un valor cualquiera, los totales filtrables coinciden con los que da la
  pantalla del CRM aplicando ese mismo filtro.
- **SC-002**: Ningún total convertido produce un número mayor que el del informe completo cuando no
  hay filtro aplicado.
- **SC-003**: El usuario distingue, leyendo sólo el archivo, cuál total responde al filtro y cuál no.
- **SC-004**: Los archivos sin filtrar siguen coincidiendo con la pantalla, de modo que el cambio no
  introduce ninguna diferencia respecto de lo que el cliente ve hoy.

## Tandas

### Tanda 1 — Informe de Ventas Detallado *(implementada)*

Es el informe que usa la administrativa junto con el Ranking, el de más columnas (44) y el que tenía
evidencia medida de un caso real.

Medición contra la base (01/07–31/08/2026, 1.805 filas de detalle, 1.297 comprobantes):

| Columna | Suma por fila | KPI del informe | Sumable |
|---|---|---|---|
| Cantidad | 1.922,00 | 1.922,00 | sí |
| Costo Total Actual | 70.637.960,80 | 70.637.960,80 | sí |
| CMV Total | 42.462.605,15 | 42.462.605,15 | sí |
| Precio de Venta | 138.901.074,94 | 138.901.074,94 | sí |
| **Total Venta** | 167.783.605,32 | 168.345.358,78 | **no** |

Convertidos a fórmula: **Cantidad de Productos/Servicios, Costo Actual, Costo Mercadería Vendida,
Precio Neto y Resultado**.

Quedan como total del informe, rotulados "(informe completo)": **Total Ventas Creadas, Total Nota de
Débito, Total Nota de Crédito, Total Ventas, Cantidad Ventas Creadas y Venta Promedio**.

Autofiltro desde la fila 10 (el encabezado del detalle) hasta la última fila de datos.

### Tandas siguientes *(pendientes de análisis)*

Cada una repite el método: medir, clasificar, convertir sólo lo sumable, rotular el resto.

- **Informe de Ventas (resumen)** y **Informe de Compras**: mismos 11 KPIs, misma estructura.
- **Informe de Gastos** y **Reporte Final**.
- **Cuenta Corriente** de clientes y de proveedores.
- **Movimientos de Clientes / Proveedores**.
- **Libro IVA**: requiere una decisión de negocio previa. Sus totales forman parte de una
  presentación fiscal, y un total que cambia al filtrar puede llevar a presentar un número parcial
  como si fuera el total del período. Puede corresponder dejarlo fijo a propósito.

## Assumptions

- La verificación se hace contra los KPIs de la pantalla y contra la base, que ya fueron validados.
- La estructura y los nombres de hoja se mantienen: hay planillas de usuario y procesos internos
  apoyados en ellos.
- El usuario trabaja con Excel de escritorio; el archivo debe seguir siendo válido en otras
  herramientas.

## Out of Scope

- Agregar hojas, columnas o KPIs nuevos.
- Cambiar la presentación en pantalla de los informes.
- Convertir los totales de comprobante mediante técnicas que cuenten cada comprobante una sola vez:
  es posible, pero exige decidir qué significa "cantidad de ventas" cuando el filtro deja afuera
  parte de las líneas de una venta, y esa es una decisión de negocio aparte.
