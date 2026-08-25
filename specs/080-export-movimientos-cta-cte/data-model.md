# Data Model: Exportar Movimientos de Cuenta Corriente

No hay entidades ni tablas nuevas — este feature es de sólo lectura sobre entidades ya existentes
(Venta, Compra, Cobro, Pago, NotaCreditoDebito, Cliente, Proveedor, Vendedor, ComprobanteFiscal). Lo
que se agrega es la **forma de la fila exportada** (vista derivada), espejo de la que ya usa
`queryMovimientos()` en pantalla, enriquecida con las columnas fiscales del Libro IVA.

## Vista derivada: fila de Movimientos — Excel Clientes (34 columnas)

| # | Columna | Fuente | Vacío/0 en |
|---|---|---|---|
| 1 | Id | `ventas.id` / `cobros.id` / `notas_credito_debito.id` | — |
| 2 | Emisión | `fecha_emision` / `cobros.fecha` | — |
| 3 | Cliente | `clientes.nombre` | — |
| 4 | CUIT | `clientes.cuit` | filas sin cliente identificado |
| 5 | Operación | `Venta` / `Cobro` / `Nota de Crédito` / `Nota de Débito` / `Saldo Inicial` | — |
| 6 | Categoría | `categorias.nombre` (de la venta) | Cobro, NC/ND |
| 7 | Medio de Cobro | `cuentas_tesoreria.nombre` (del cobro) | Venta, NC/ND |
| 8 | Descripción | `cobros.nota` / `notas_credito_debito.descripcion` | Venta |
| 9 | Tipo de Comprobante | comprobante fiscal aprobado → fallback `ventas.tipo_comprobante` (ver research D5); para Cobro, el del recibo | — |
| 10 | Punto de Venta | comprobante fiscal aprobado → `punto_venta_id`; vacío si no hay | — |
| 11 | N° de Comprobante | mismo criterio ya usado en pantalla (`nro_comprobante` resuelto) | — |
| 12 | Aplicada en N° de Factura | — (research D6) | siempre vacío |
| 13 | Fecha Factura Aplicada | — (research D6) | siempre vacío |
| 14 | Id Venta | `cobros.venta_id` | Venta, NC/ND (la fila ya es la venta) |
| 15 | Vendedor | `ventas.vendedor_id` → `vendedores.nombre` (research D4) | Cobro |
| 16 | Subtotal sin Descuento | `ventas.subtotal_sin_descuento` | Cobro, NC/ND |
| 17 | Descuento en $ | `ventas.descuento` | Cobro, NC/ND |
| 18 | Subtotal con Descuento | `ventas.subtotal_con_descuento` | Cobro, NC/ND |
| 19 | Importe Neto No Gravado | `LibroIvaVentasQuery` (`neto_no_gravado`) | Cobro |
| 20 | Importe Neto Gravado | `LibroIvaVentasQuery` (`neto_gravado`) | Cobro |
| 21-25 | IVA - 2,5% / 5% / 10,5% / 21% / 27% | `LibroIvaVentasQuery` (`iva_2_5`…`iva_27`) | Cobro |
| 26 | Exento | `LibroIvaVentasQuery` (`neto_exento`) | Cobro |
| 27 | No Gravado | alias de columna 19 en el header real (ver nota) | Cobro |
| 28 | Perc. IVA | `LibroIvaVentasQuery` (`perc_iva`) | Cobro |
| 29 | Perc. IIBB | `LibroIvaVentasQuery` (`perc_iibb`) | Cobro |
| 30 | Imp. Internos | `LibroIvaVentasQuery` (`imp_internos`) | Cobro |
| 31 | Imp. Municipales | `LibroIvaVentasQuery` (`imp_municipales`, siempre 0 hoy) | Cobro |
| 32 | Total Venta | `ventas.total` | Cobro |
| 33 | Cobrado | `cobros.monto` | Venta, NC/ND |
| 34 | A cobrar | mismo cálculo que `queryMovimientos()` ya usa hoy (research D3) | — |

> Nota columna 27 "No Gravado": el header real de Contagram repite conceptualmente "Importe Neto No
> Gravado" (columna 19) y "No Gravado" (columna 27) como dos columnas separadas. Se mapean ambas al
> mismo valor (`neto_no_gravado` de `DesgloseImpositivoVenta`) — no hay dos conceptos distintos de "no
> gravado" en el modelo fiscal del proyecto; se preserva la columna duplicada por fidelidad estructural
> (mismo criterio de la regla de oro: calcar la estructura real, no "simplificarla").

## Vista derivada: fila de Movimientos — Excel Proveedores (33 columnas)

Igual que Clientes, sin la columna "Vendedor" (#15), y con **"Sellos"** agregada entre "Imp.
Municipales" y "Total Compra" (siempre 0 — no hay concepto de Sellos modelado hoy en
`compra_conceptos`; se documenta como columna futura, no se inventa un cálculo). Cliente→Proveedor,
Cobrado→Pagado, A cobrar→A pagar, Medio de Cobro→Medio de Pago, Total Venta→Total Compra, Id
Venta→Id Compra, fuente de netos/IVA: `LibroIvaComprasQuery`.

## Vista derivada: fila de Movimientos — PDF (11 columnas, ambos)

Igual a la tabla que ya muestra hoy `CuentaCorrienteController::movimientosData()` /
`CuentaCorrienteProveedorController::movimientosData()` en pantalla — sin cambios de fuente de datos,
sólo un nuevo formato de salida (PDF en vez de JSON para DataTables).
