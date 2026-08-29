# Feature Specification: Las columnas del Excel del Libro IVA calcan las de Contagram

**Feature Branch**: `091-columnas-libro-iva-contagram`

**Created**: 2026-08-28

**Status**: Draft

**Input**: Comparando el Excel que envía el CRM contra el que exporta Contagram (fuente de verdad), la
estética ya coincide (spec 089) pero **las columnas no**. Contagram tiene 13 columnas; el CRM emite 19
distintas. Faltan tres que Contagram sí trae (Total Facturado, Provincia, Medio de Cobro) y sobran
varias que en la práctica van siempre en cero. Decisión del usuario (28/08/2026): **calcar exactamente
las 13 de Contagram**.

## Contexto

La spec 077 definió 19 columnas para el Libro IVA con el criterio de discriminar las cinco alícuotas de
IVA y las percepciones. La spec 089 calcó el formato visual del Excel de Contagram pero **preservó
explícitamente esas 19 columnas** (FR-010), asumiendo que eran funcionalmente superiores.

Al comparar contra el archivo real de Contagram quedó a la vista que esa suposición tenía un costo: el
CRM **no emite tres columnas que el contador sí venía recibiendo** —Total Facturado, Provincia y Medio
de Cobro— mientras emite nueve que en los datos reales del negocio están siempre en cero.

Verificado sobre un período real completo (Julio 2026, 718 comprobantes):

| Columna del CRM que Contagram no tiene | Total del período |
|---|---:|
| IVA 2,5% · IVA 5% · IVA 10,5% · IVA 27% | **0,00** (las cuatro) |
| Perc. IVA · Perc. IIBB | **0,00** (las dos) |
| Imp. Internos · Imp. Municipales | **0,00** (las dos) |
| IVA 21% | 15.134.625,83 |

El negocio factura exclusivamente al 21%. Discriminar cinco alícuotas y cuatro conceptos extra agrega
ocho columnas vacías y desplaza fuera de la vista las que el contador realmente usa.

## Clarifications

### Session 2026-08-28

- Q: ¿Se agregan las 3 columnas faltantes conservando las 19 actuales (22 en total), se calcan las 13
  de Contagram, o sólo se agrega Total Facturado? → A: **Calcar exactamente las 13 de Contagram.**
- Q: Si algún día aparece una venta con IVA 10,5% (o cualquier alícuota distinta de 21%), ¿qué pasa con
  una única columna rotulada "IVA 21%"? → A: La columna MUST contener el **IVA total del comprobante**,
  no sólo el tramo del 21% (ver FR-008). Así ningún importe de IVA desaparece del libro aunque el
  rótulo, calcado de Contagram, nombre una sola alícuota. Queda documentado como riesgo asumido.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El contador recibe las mismas columnas que venía recibiendo (Priority: P1)

El contador abre el Excel que le llega del CRM y encuentra exactamente las columnas del archivo que
recibía de Contagram, en el mismo orden: fecha, tipo, comprobante, razón social, CUIT, condición de
IVA, los tres netos, el IVA, el total facturado, la provincia y el medio de cobro.

**Why this priority**: es el objetivo entero de la feature. Hoy el contador recibe un archivo con
columnas que no reconoce y sin el Total Facturado, que es la columna con la que concilia.

**Independent Test**: exportar cualquier período y comparar la fila de títulos contra el archivo de
Contagram — deben coincidir nombre por nombre y posición por posición.

**Acceptance Scenarios**:

1. **Given** el Libro IVA Ventas exportado, **When** se mira la fila de títulos, **Then** trae
   exactamente 13 columnas con los rótulos de Contagram, en su orden.
2. **Given** una fila de comprobante cualquiera, **When** se mira la columna Total Facturado,
   **Then** muestra el importe total del comprobante.
3. **Given** un comprobante de un cliente con provincia cargada, **When** se mira la columna
   Provincia, **Then** muestra su provincia; si no tiene, muestra el guion que usa Contagram.
4. **Given** un comprobante cobrado, **When** se mira la columna Medio de Cobro, **Then** muestra la
   cuenta de tesorería con que se cobró.

---

### User Story 2 - Ningún importe de IVA se pierde (Priority: P1)

Aunque la columna se rotule "IVA 21%" —calcando a Contagram—, un comprobante con IVA a otra alícuota
sigue apareciendo con su importe, no en cero.

**Why this priority**: es la salvaguarda fiscal de la decisión de reducir columnas. Hoy el negocio sólo
factura al 21%, pero el día que eso cambie el libro no puede subdeclarar en silencio.

**Independent Test**: cargar un comprobante con IVA 10,5% y verificar que su importe aparece en la
columna de IVA, no en cero.

**Acceptance Scenarios**:

1. **Given** un comprobante con IVA al 10,5%, **When** se exporta, **Then** la columna de IVA muestra
   ese importe.
2. **Given** un período con comprobantes a distintas alícuotas, **When** se suma la columna de IVA,
   **Then** el total coincide con el IVA total del período.

---

### Edge Cases

- **Cliente sin provincia cargada**: se muestra el guion (`-`), igual que Contagram.
- **Comprobante sin cobrar** (sin movimiento de tesorería): la columna Medio de Cobro queda vacía.
- **Comprobante cobrado en varias cuentas**: ver Assumptions — se muestra la del primer cobro.
- **Libro IVA Compras**: la misma reducción de columnas, con "Medio de Pago" en lugar de "Medio de
  Cobro" y "Razón Social" refiriéndose al proveedor.

## Requirements *(mandatory)*

### Columnas

- **FR-001**: El Excel MUST emitir exactamente las 13 columnas de Contagram, en este orden: Fecha,
  Tipo, N° de Comprobante, Razón Social, CUIT / DNI, Condición de IVA, Neto No Grav., Neto Exento,
  Neto Grav., IVA 21%, Total Facturado, Provincia, Medio de Cobro.
- **FR-002**: El Excel MUST NOT emitir las columnas que Contagram no tiene: Id, las alícuotas
  discriminadas distintas de la principal, Perc. IVA, Perc. IIBB, Imp. Internos e Imp. Municipales.
- **FR-003**: La columna Total Facturado MUST mostrar el importe total del comprobante.
- **FR-004**: La columna Provincia MUST mostrar la provincia fiscal del cliente, con respaldo en la
  comercial, y un guion cuando no hay ninguna.
- **FR-005**: La columna Medio de Cobro MUST mostrar la cuenta de tesorería del cobro del comprobante,
  y quedar vacía si no fue cobrado.
- **FR-006**: En el Libro IVA Compras la última columna MUST rotularse "Medio de Pago" y referirse al
  pago al proveedor.

### Integridad fiscal

- **FR-007**: La reducción de columnas MUST NOT alterar ningún importe: los netos y el IVA de cada
  comprobante siguen siendo los mismos que hoy.
- **FR-008**: La columna de IVA MUST contener el **IVA total del comprobante**, sumando todas las
  alícuotas, aunque su rótulo nombre una sola (calcado de Contagram). Ningún importe de IVA puede
  quedar fuera del libro por no tener columna propia.

### Totales

- **FR-009**: Los tres renglones de totales del pie (spec 089) MUST seguir cerrando: facturación más
  notas de crédito igual al total, ahora sobre las columnas nuevas.
- **FR-010**: El pie MUST incluir el total de la columna Total Facturado.

## Success Criteria *(mandatory)*

- **SC-001**: La fila de títulos del Excel del CRM coincide con la del archivo de Contagram, columna por
  columna, en nombre y posición.
- **SC-002**: El contador puede conciliar con la columna Total Facturado, que hoy no recibe.
- **SC-003**: La suma de la columna de IVA del período es igual al IVA total del período, con
  comprobantes a cualquier alícuota.
- **SC-004**: Los netos y el IVA por comprobante son idénticos a los que emite el CRM hoy — esta spec
  cambia qué columnas se muestran, no cuánto vale cada importe.

## Assumptions

- **Un comprobante cobrado en varias cuentas** muestra la del primer cobro registrado. Contagram
  muestra un único valor en esa columna y el relevamiento no cubre el caso de cobros múltiples; se
  elige el primero por ser determinístico. Queda declarado como asunción, no como hecho relevado.
- La pantalla del informe (spec 077) **no cambia**: mantiene sus 19 columnas con selector de
  visibilidad. Esta spec versa sobre el archivo exportado, que es lo que recibe el contador.
- El IVA Digital (spec 086) **no cambia**: sus archivos de ancho fijo tienen su propio contrato con
  ARCA, ajeno a este.
- El archivo de referencia guardado como fixture (`IVA Ventas Contagram 13 columnas.xlsx`) está
  rotulado "Julio 2026" pero contiene comprobantes de agosto; se usa por su **estructura de columnas**,
  que es lo que esta spec calca, no por sus datos.

## Dependencies

- **Spec 077**: definió las 19 columnas actuales. Esta spec las reemplaza **en el export**; la pantalla
  sigue como está.
- **Spec 089**: el formato visual (encabezado, estilos, totales al pie) se conserva tal cual; sólo
  cambian las columnas sobre las que se aplica.
- **Spec 087**: el adjunto del correo usa el mismo generador, así que hereda el cambio.

## Out of Scope

- Cambiar las columnas de la **pantalla** del informe (spec 077).
- El formato visual (spec 089), ya implementado.
- Los archivos del IVA Digital (spec 086).
- Recuperar en otro lado las columnas que se quitan (percepciones, impuestos internos): si el negocio
  empieza a usarlas, se evalúa entonces cómo mostrarlas.
