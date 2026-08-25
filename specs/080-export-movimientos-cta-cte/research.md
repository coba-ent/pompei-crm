# Research: Exportar Movimientos de Cuenta Corriente

## D1: Cómo reutilizar `LibroIvaVentasQuery`/`LibroIvaComprasQuery` con un rango de fechas libre

**Problema**: esas dos clases (spec 077) resuelven el período con `rangoPeriodo(Request)`, que lee
`mes`/`anio` del request y arma un rango calendario (`Carbon::createFromDate($anio,$mes,1)->startOfMonth()/endOfMonth()`).
La pantalla de Cta Cte Movimientos filtra por `fecha_desde`/`fecha_hasta` **libres** (pueden cruzar
meses). No se puede expresar ese filtro como `mes`+`anio`.

**Decision**: extender `LibroIvaQuery::rangoPeriodo()` (clase base, no las subclases) para que, si el
request trae `fecha_desde`/`fecha_hasta`, los use directo; si no, siga leyendo `mes`/`anio` como hasta
ahora. Es un cambio **aditivo** (una rama `if` nueva antes del comportamiento existente) sobre un
método `protected` ya compartido — el Libro IVA del Contador sigue funcionando exactamente igual
(sigue mandando `mes`/`anio`, nunca `fecha_desde`/`fecha_hasta`), y las nuevas queries de Movimientos
mandan `fecha_desde`/`fecha_hasta` en vez de `mes`/`anio`. Ningún cálculo fiscal
(`sqlNeto`/`sqlIva`/subqueries correlacionadas) se toca.

**Alternativas consideradas**:
- Reimplementar el desglose fiscal desde cero en las nuevas queries → rechazada: exactamente lo que
  la spec prohíbe (FR-009), y duplica ~250 líneas de lógica ya probada (incluyendo las 4 ramas de
  precedencia de NC/ND).
- Copiar `LibroIvaVentasQuery`/`ComprasQuery` a una versión nueva con período distinto → rechazada:
  divergen con el tiempo, dos lugares para arreglar el mismo bug fiscal.

## D2: Cómo agregar filas de Cobro/Pago/Saldo Inicial (que el Libro IVA no tiene)

**Decision**: `MovimientosClientesQuery`/`MovimientosProveedoresQuery` (nuevas) hacen `UNION ALL` en
PHP-side (misma técnica que `CuentaCorrienteController::queryMovimientos()` ya usa hoy) entre:
1. El resultado de `LibroIvaVentasQuery::detalle()`/`LibroIvaComprasQuery::detalle()` (filas de
   Venta/Compra + NC/ND, con su desglose fiscal completo), envuelto en una subquery que le agrega las
   columnas que el Libro IVA no expone (Categoría, Tipo de Comprobante + Punto de Venta por separado,
   Vendedor, Subtotal sin/con Descuento, Descuento en $, Total Venta/Compra, Cobrado/Pagado, A
   Cobrar/Pagar) vía `JOIN` a `ventas`/`compras` por `id` — sólo para las filas cuyo `id` corresponde a
   un comprobante real, no a una NC/ND (que no tiene esas columnas, van en blanco).
2. Una query nueva y simple para Cobro/Pago (`cobros`/`pagos` + join a la venta/compra que cancelan),
   con las columnas fiscales ya en blanco/NULL desde el `SELECT` (FR-010) y Medio/Descripción/Nº de
   Comprobante propio/Id Venta-Compra completos.
3. Saldo Inicial, igual criterio que `queryMovimientos()` actual.

## D3: "A cobrar"/"A pagar" — saldo por comprobante, no el acumulado observado en los archivos reales

Documentado como Assumption en `spec.md`: se usa el mismo cálculo por comprobante que ya está en
pantalla (`ventas.total + notas_debito - notas_credito - cobros`, ver
`CuentaCorrienteController::queryMovimientos()`), no el acumulado global que se observó repetirse
entre clientes distintos en los archivos reales. No requiere cambio de diseño adicional: es el mismo
cálculo que las columnas `a_cobrar`/`a_pagar` de las queries actuales, sólo se agrega a las columnas
nuevas del Excel.

## D4: Columna "Vendedor" — en qué fila va

En el único ejemplo real disponible, "Vendedor" apareció con valor en la fila de **Cobro** y vacío en
la fila de **Venta** correspondiente — inconsistente con que el vendedor es un atributo de la venta
(`ventas.vendedor_id`), no del cobro. Se interpreta como un dato incompleto de esa venta puntual en
los datos reales de prueba (el vendedor no se cargó), no una regla estructural.

**Decision**: "Vendedor" se puebla desde `ventas.vendedor_id` en la fila de **Venta** (y de NC/ND, vía
la venta que ajustan), vacío en las filas de Cobro — es la relación de datos correcta y consistente
con el resto del modelo (`Venta::vendedor()`). No aplica a Proveedores (no hay columna Vendedor ahí,
confirmado contra el header real de ese archivo).

## D5: "Tipo de Comprobante" / "Punto de Venta" — de dónde salen

Mismo criterio ya usado por `LibroIvaVentasQuery`/`ComprasQuery` para "N° de Comprobante": preferir el
`comprobantable` aprobado en `comprobantes_fiscales` (que sí tiene `punto_venta_id` y `tipo_comprobante`
separados) y si no existe, caer a `ventas.tipo_comprobante`/`ventas.nro_comprobante` (que en ese caso no
tiene punto de venta propio — va NULL/vacío). Para las filas de Cobro/Pago, "Tipo de Comprobante" y
"Punto de Venta" describen el **recibo/orden de pago** (no la venta que cancelan) — se derivan
localmente (no hay comprobante fiscal ARCA para un recibo interno), consistentes con lo que ya
resuelve la pantalla hoy para "N° de Comprobante" del recibo.

## D6: "Aplicada en N° de Factura" / "Fecha Factura Aplicada"

Ver spec.md FR-012: quedan vacías en todas las filas — brecha de modelo de datos documentada, no se
implementa en este alcance la aplicación de un cobro/pago a una factura distinta de la que cancela.

## D7: Estilo visual del Excel

Se usa `HojaInforme` (ya existente, fondo oscuro + letra blanca en negrita, autosize) en vez de calcar
el azul `#0E5DA1` del archivo original de Contagram — mismo criterio ya aplicado en el export de
Saldos (consistencia visual interna del CRM).

## D8: Tope de filas del PDF

500 filas, igual que `InformeComprasController::TOPE_FILAS_PDF` — convención ya establecida, no un
número nuevo a inventar (FR-011).
