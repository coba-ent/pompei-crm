# Feature Specification: El Excel de Rankings exporta lo mismo que muestra la pantalla

**Feature Branch**: `113-ranking-export-excel`
**Created**: 2026-10-01
**Status**: Draft
**Input**: Reporte del cliente (01/10/2026). La administrativa usa Informe de Ventas > Rankings / "Arma tu Informe" con Productos y Proveedores en filas y Año/Mes en columnas, para calcular los puntos de reposición. El Excel que exporta no coincide con lo que ve en pantalla ni con los totales del informe. Es el flujo con el que trabaja hace años en Contagram.

## Contexto del hallazgo

El archivo de Contagram con el que ella trabaja (`2do PDR 2026 UN.xlsx`) tiene **784 productos**, un
renglón por producto, los meses en columnas y una fila `Totales` al pie con la suma de cada mes.
Sobre esa base ella agrega dos columnas propias (promedio y punto de reposición). El equivalente que
hoy exporta el CRM trae **166 productos**, la fila `Totales` vacía y cifras que no cierran con los
KPIs de la pantalla.

Se exportaron cuatro archivos de prueba del mismo período (01/07/2026–30/09/2026) variando sólo la
métrica. Los defectos se repiten en todos, así que no dependen de la métrica elegida:

| Métrica exportada | Productos | Suma de la columna Total | Fila Total | Última fila |
|---|---|---|---|---|
| Cantidad de Productos | 166 | 2.149 | vacía | sin total |
| Cantidad de Ventas | 166 | 1.939 | vacía | sin total |
| Total Venta sin impuestos | 166 | 184.273.985,43 | vacía | sin total |
| Total Venta (con impuestos) | 166 | 222.729.622,47 | vacía | sin total |

El KPI de la pantalla para ese período es **1.922** productos/servicios y **$9.949.478,81** de Total
Ventas. Ninguna de las cuatro columnas de total coincide.

Caso concreto que lo destapó, y que acota la causa: **el recorte depende de si hay filtro de
proveedor aplicado en la pantalla**.

| Export | Productos de Mauricio |
|---|---|
| Ranking **con** filtro de proveedor = Mauricio | **32** (correcto) |
| Ranking **sin** filtro (166 productos en total) | **7** (15, 14, 12, 8, 8, 6, 6 = 69) |
| Informe de Ventas Detallado, filtrado por Mauricio | **32 productos / 130 unidades** |

Los 130 se verificaron contra la base de producción y contra el KPI de la pantalla. Es decir: el
cruce **sí sabe traer los 32 productos** cuando el conjunto es chico, pero al exportar sin filtro se
queda con 166 productos —los de mayor volumen— y descarta el resto. Los 25 productos de Mauricio que
se pierden tienen todos menos de 6 unidades.

Eso explica el archivo que originó el reclamo: la administrativa exportó **sin filtrar por
proveedor**, y recibió un conjunto recortado. Su archivo equivalente de Contagram tiene 784
productos.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El Excel trae todos los productos (Priority: P1)

La administrativa arma el cruce de Productos × Meses para el trimestre y exporta a Excel. El archivo
tiene que listar **todos** los productos vendidos en el período, no un subconjunto.

**Why this priority**: sin esto el archivo es inservible para calcular puntos de reposición — los
productos de poca rotación, que son justamente los que más importa vigilar, son los que desaparecen.

**Independent Test**: exportar el cruce de un período y comparar la cantidad de productos del
archivo contra los productos distintos que el Informe de Ventas Detallado muestra para ese mismo
período.

**Acceptance Scenarios**:

1. **Given** un cruce de Productos × Meses sobre un trimestre, **When** el usuario exporta a Excel,
   **Then** el archivo lista la misma cantidad de productos que muestra la pantalla.
2. **Given** ese mismo cruce filtrado por un proveedor, **When** el usuario exporta, **Then** el
   archivo lista los 32 productos de ese proveedor y no 7.
3. **Given** un producto con una sola unidad vendida en el período, **When** el usuario exporta,
   **Then** ese producto aparece en el archivo.

---

### User Story 2 - Los totales del Excel cierran (Priority: P1)

El usuario suma la columna Total del archivo y el resultado coincide con el KPI de la pantalla para
los mismos criterios.

**Why this priority**: es el síntoma por el que el cliente reportó el problema. Un archivo cuyos
totales no cierran con el sistema no se puede presentar a terceros.

**Independent Test**: exportar un cruce sin filtros y verificar que la suma de la columna Total
coincide con el KPI correspondiente de la pantalla.

**Acceptance Scenarios**:

1. **Given** un cruce con la métrica Cantidad de Productos sobre un período, **When** el usuario
   suma la columna Total del Excel, **Then** el resultado coincide con el KPI "Cantidad
   Prod./Serv." de la pantalla para ese período.
2. **Given** un cruce con una métrica de importe, **When** el usuario suma la columna Total,
   **Then** el resultado coincide con el KPI de importe correspondiente.
3. **Given** cualquier cruce exportado, **When** el usuario compara una celda del Excel con la misma
   celda de la pantalla, **Then** son iguales.

---

### User Story 3 - La fila de totales trae sus valores (Priority: P2)

La última fila del archivo, rotulada "Total", tiene que traer el total de cada columna y el total
general, igual que lo hace la pantalla y que lo hacía el archivo de Contagram.

**Why this priority**: hoy esa fila sale con el rótulo y todas las celdas vacías. Es la fila sobre la
que el usuario apoya sus propias fórmulas.

**Independent Test**: exportar cualquier cruce y verificar que la fila "Total" tiene un número por
cada columna de datos más el total general.

**Acceptance Scenarios**:

1. **Given** un cruce exportado, **When** el usuario mira la última fila, **Then** cada columna de
   mes tiene su total y la columna Total tiene el total general.
2. **Given** esa fila, **When** el usuario compara sus valores con la fila de totales de la
   pantalla, **Then** coinciden.

---

### User Story 4 - Ninguna fila pierde su total (Priority: P2)

Todas las filas de producto tienen que traer su valor en la columna Total.

**Why this priority**: hoy la última fila de producto sale sin total, de modo que un producto queda
con los meses cargados pero sin su suma.

**Independent Test**: exportar un cruce y verificar que no hay ninguna fila de producto con la celda
de Total vacía.

**Acceptance Scenarios**:

1. **Given** un cruce exportado con N productos, **When** el usuario revisa la columna Total,
   **Then** las N filas tienen un valor.

---

### User Story 5 - El archivo viene listo para filtrar (Priority: P3)

El usuario filtra por proveedor dentro del propio Excel sin tener que activar el autofiltro ni
seleccionar rangos a mano.

**Why this priority**: es el flujo de trabajo con el que ella viene — su archivo de Contagram tenía
el filtro puesto sobre el rango de datos. Depende de que las historias 1 a 4 estén resueltas: filtrar
sobre datos incompletos no sirve.

**Independent Test**: abrir el archivo exportado y comprobar que la fila de encabezados tiene los
controles de filtro activos.

**Acceptance Scenarios**:

1. **Given** un archivo recién exportado, **When** el usuario lo abre, **Then** la fila de
   encabezados tiene los controles de filtro activos sobre el rango de datos.
2. **Given** el filtro activo, **When** el usuario filtra por un proveedor, **Then** ve únicamente
   los productos de ese proveedor.

---

### Edge Cases

- **Cruce sin dimensión de filas** (sólo columnas): el archivo ya contempla ese caso con una
  estructura propia; no debe romperse con estos cambios.
- **Celdas vacías**: un producto sin ventas en un mes deja esa celda vacía, no en cero; la fila igual
  debe traer su total.
- **Valores negativos** (notas de crédito): deben restar en el total de la fila y en el de la
  columna, como lo hace la pantalla.
- **Cruce muy grande**: el archivo debe contener todas las filas del cruce aunque la pantalla las
  muestre paginadas o con scroll.
- **Exclusiones hechas por el usuario** (el embudo del pivot): lo excluido en pantalla no debe
  aparecer en el Excel — el archivo refleja el cruce que el usuario está viendo.
- **Informe vacío**: un cruce sin resultados no debe romper el archivo.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El Excel exportado DEBE contener todas las filas del cruce que la pantalla representa,
  sin recortes.
- **FR-002**: La suma de la columna Total del archivo DEBE coincidir con el KPI de la pantalla
  correspondiente a la métrica elegida, para los mismos criterios de búsqueda.
- **FR-003**: Cada celda del archivo DEBE tener el mismo valor que la celda equivalente de la
  pantalla.
- **FR-004**: La fila de totales DEBE traer el total de cada columna y el total general.
- **FR-005**: Toda fila de datos DEBE traer su valor en la columna Total, incluida la última.
- **FR-006**: Lo anterior DEBE cumplirse para todas las métricas seleccionables en "Dato", no sólo
  para la que motivó el reporte.
- **FR-007**: El archivo DEBE abrirse con el filtro activado sobre la fila de encabezados y el rango
  de datos.
- **FR-008**: La estructura del archivo —hojas, orden y significado de las columnas— NO DEBE
  cambiar: replica el export de Contagram y hay usuarios con planillas apoyadas en ese formato.
- **FR-009**: El archivo DEBE seguir reflejando las exclusiones y el orden que el usuario definió en
  la pantalla: exportar es llevarse lo que se está viendo.
- **FR-010**: Los números DEBEN escribirse como números y no como texto, para que Excel pueda
  sumarlos sin conversión previa.

### Key Entities

- **Cruce (pivot)**: la matriz que el usuario arma eligiendo qué dimensiones van en filas, cuáles en
  columnas y qué dato se agrega.
- **Fila de producto**: un renglón del cruce, con un valor por cada columna y su total.
- **Fila de totales**: el renglón al pie, con el total de cada columna y el total general.
- **Métrica ("Dato")**: la magnitud que se agrega — cantidades o importes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: La cantidad de productos del archivo coincide con la de la pantalla en el 100% de los
  cruces exportados.
- **SC-002**: La suma de la columna Total coincide con el KPI de la pantalla, con diferencia cero,
  para todas las métricas disponibles.
- **SC-003**: Filtrando por un proveedor, el archivo muestra la misma cantidad de productos y las
  mismas unidades que el Informe de Ventas Detallado para ese proveedor y período.
- **SC-004**: Ninguna fila del archivo queda sin su valor de total.
- **SC-005**: La administrativa puede retomar su flujo de trabajo histórico —exportar, filtrar por
  proveedor y aplicar sus propias fórmulas— sin pasos manuales adicionales respecto de lo que hacía
  con Contagram.

## Assumptions

- El cruce que se exporta es el que el usuario tiene en pantalla, incluidas sus exclusiones: el
  export no vuelve a consultar la base con otros criterios.
- La verificación se hace contra el Informe de Ventas Detallado y contra los KPIs de la pantalla, que
  ya fueron validados contra la base para el caso del proveedor Mauricio (32 productos, 130 unidades).
- El formato de archivo y el nombre de las hojas se mantienen: hay planillas de usuario apoyadas en
  esa estructura.
- Alcance: el pivot del **Informe de Ventas**. El Informe de Compras comparte el mismo componente, y
  si la causa resulta común se corrige junto; si fuera específica de Ventas, Compras queda anotado.

## Out of Scope

- Agregar métricas o dimensiones nuevas al pivot.
- Cambiar la presentación en pantalla del cruce.
- Agregar al archivo las columnas de cálculo propias del usuario (promedio, punto de reposición):
  son fórmulas suyas y así se mantienen.
- Rediseñar la exportación de los demás informes del módulo.
