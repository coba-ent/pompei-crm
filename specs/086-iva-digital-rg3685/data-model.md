# Data Model: IVA Digital — régimen RG 3685 (spec 086)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

> **Esta feature no crea ni modifica ninguna tabla.** No hay migraciones. Es una **salida nueva sobre
> datos ya derivados** por la spec 077. Este documento describe de dónde sale cada campo de cada
> archivo, que es donde está toda la complejidad.

---

## 1. Origen de los datos

No se consulta ninguna tabla directamente. Las filas provienen de:

| Fuente | Rol |
|---|---|
| `LibroIvaVentasQuery::detalle()` | comprobantes de venta del período (facturas + NC/ND de venta) |
| `LibroIvaComprasQuery::detalle()` | comprobantes de compra del período (facturas + NC/ND de compra) |
| `MapeadorComprobante` | códigos ARCA de tipo de comprobante, tipo de documento y alícuota |

Esas queries ya resuelven, sin que esta spec lo repita: el período fiscal (emisión para ventas,
`COALESCE(mes_imputacion_iva, fecha_emision)` para compras, `mes_imputacion` para NC/ND), la exclusión
de eliminados, y el desglose de netos, IVA por alícuota y percepciones.

---

## 2. Derivación de campos — Comprobantes Ventas (266)

| Campo | Origen |
|---|---|
| Fecha de comprobante | `emision` de la fila |
| Tipo de comprobante | código ARCA vía `MapeadorComprobante`, a partir del tipo del CRM (`FEB` → `006`) |
| Punto de venta | punto de venta del comprobante fiscal |
| Número desde / hasta | número del comprobante, **repetido** en ambos campos (el CRM emite de a uno) |
| Código y número de documento | tipo y número de documento del cliente; sin identificación válida → `99` y ceros |
| Denominación | nombre del cliente, truncado a 30 |
| Importe total | **total almacenado del comprobante**, sin recalcular (FR-015) |
| No integra neto gravado | importe no gravado de la fila |
| Percepción a no categorizados | `0` — el CRM no modela este concepto |
| Operaciones exentas | importe exento de la fila |
| Percepción de IVA / IIBB | conceptos de percepción de la fila |
| Percepción municipal | `0` — brecha heredada de la spec 077 |
| Impuestos internos | concepto de impuestos internos de la fila |
| Moneda / tipo de cambio | moneda del comprobante; `PES` y `1,000000` en todo el fixture |
| **Cantidad de alícuotas** | **conteo de filas emitidas** en Alícuotas Ventas para este comprobante (FR-016) |
| Código de operación | `0` cuando hay alícuotas |
| Otros tributos | `0` |
| Fecha de vencimiento de pago | igual a la fecha de comprobante en todo el fixture |

## 3. Derivación de campos — Alícuotas Ventas (62)

Una fila **por cada alícuota distinta** presente en el comprobante.

| Campo | Origen |
|---|---|
| Tipo / punto de venta / número | los mismos del comprobante — es la clave que los vincula |
| Importe neto gravado | neto de esa alícuota |
| Alícuota | código ARCA (`21%` → `0005`) |
| Impuesto liquidado | IVA de esa alícuota |

## 4. Derivación de campos — Comprobantes Compras (325)

Igual que ventas, con estas diferencias:

| Campo | Origen |
|---|---|
| Número de comprobante | uno solo (no hay "desde/hasta") |
| Despacho de importación | vacío (espacios) — el negocio no importa |
| Documento / denominación | del **proveedor** |
| Percepciones de otros impuestos nacionales | concepto correspondiente de la compra |
| **Crédito fiscal computable** | suma del IVA de las filas de alícuota del comprobante (FR-018) |
| CUIT y denominación de emisor por cuenta de terceros | vacíos — el negocio no opera bajo esa figura |
| IVA comisión | `0` |

## 5. Derivación de campos — Alícuotas Compras (84)

Igual que Alícuotas Ventas **más** código y número de documento del vendedor entre el número de
comprobante y el neto gravado — los 22 caracteres que diferencian ambos layouts.

---

## 6. Invariantes

Se verifican en test (plan §Estrategia de test), no sólo se documentan:

1. Toda fila de alícuota tiene un comprobante con la misma clave en el archivo de comprobantes del
   mismo lado.
2. `Cantidad de alícuotas` de un comprobante = cantidad de filas emitidas para él. Se cumple **por
   construcción**: el campo es el `count()` de lo escrito, no un valor derivado en paralelo.
3. En compras, `Crédito fiscal computable` = suma del `Impuesto liquidado` de sus alícuotas.
4. Todas las líneas de un archivo tienen el mismo ancho **en bytes**.
5. Generar el mismo período dos veces produce bytes idénticos.

---

## 7. Nomenclatura de archivos

| Artefacto | Patrón |
|---|---|
| ZIP | `IVA Digital Ventas y Compras {Mes} {Año}.zip` |
| Comprobantes de venta | `Comprobantes Ventas {Mes} {Año} Res 3685.txt` |
| Alícuotas de venta | `Alicuotas Ventas {Mes} {Año} Res 3685.txt` |
| Comprobantes de compra | `Comprobantes Compras {Mes} {Año} Res 3685.txt` |
| Alícuotas de compra | `Alicuotas Compras {Mes} {Año} Res 3685.txt` |

`{Mes}` en castellano con inicial mayúscula (`Agosto`). `Alicuotas` va **sin acento** en el nombre del
archivo, tal como el fixture — aunque el campo se llame "alícuota" en la documentación.
