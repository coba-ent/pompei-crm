# Data Model: Información para tu Contador (spec 077)

**Fecha**: 2026-08-24
**Spec**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

> **Este informe no crea ni modifica ninguna tabla.** Es de sólo lectura (FR-037) y se apoya
> íntegramente en el esquema ya existente. Esta sección documenta **cómo se derivan** las columnas del
> libro a partir de ese esquema, que es donde está toda la complejidad.

---

## 1. Tablas consultadas (ninguna nueva)

| Tabla | Rol en el informe |
|---|---|
| `ventas` | filas del libro IVA Ventas |
| `venta_items` | origen del desglose por alícuota de una venta |
| `venta_conceptos` | percepciones (IVA/IIBB) e impuestos internos de una venta |
| `compras` | filas del libro IVA Compras; aporta `mes_imputacion_iva` (campo "Contador") |
| `compra_items` | origen del desglose por alícuota de una compra |
| `compra_conceptos` | percepciones e impuestos internos de una compra |
| `notas_credito_debito` | filas de NC/ND en **ambos** libros; aporta `mes_imputacion` |
| `comprobantes_fiscales` | clasificación ARCA vs. manual (sólo IVA Ventas) |
| `clientes` / `proveedores` | contraparte, CUIT, provincia |
| `condiciones_iva` | columna y filtro "Condición de IVA" |
| `cobros` / `pagos` | filtro "Medio de Cobro" / "Medio de Pago" |
| `cuentas_tesoreria` | opciones de ese filtro |
| `provincias` | opciones del filtro Provincia |

**Migraciones necesarias: ninguna.**

---

## 2. Resolución del período fiscal

Es la regla de negocio central del informe (FR-008, FR-009, FR-009a). El período **no** sale siempre de
la misma columna:

| Origen de la fila | Expresión de período | Notas |
|---|---|---|
| Venta | `ventas.fecha_emision` | una venta no tiene mes de imputación propio — el campo "Contador" es exclusivo de Compras |
| Compra | `COALESCE(compras.mes_imputacion_iva, compras.fecha_emision)` | `mes_imputacion_iva` es nullable (el campo es opcional en el formulario) |
| NC/ND (venta o compra) | `notas_credito_debito.mes_imputacion` | `NOT NULL`; se precarga con el mes de `fecha_emision` al crear la nota (spec 045) |

La comparación es **por año y mes**, no por rango de fechas: `mes_imputacion` / `mes_imputacion_iva` se
persisten con día fijo `01`, así que un `BETWEEN` funcionaría por accidente pero expresaría mal la
intención (research, Decisión 3).

> **Invariante a testear**: una compra emitida el 28/07 con imputación a agosto aparece **sólo** en
> agosto. Una compra sin imputación cargada aparece en el mes de su emisión.

---

## 3. Clasificación ARCA vs. manual (sólo IVA Ventas)

```
firme  ⟺ EXISTS (comprobantes_fiscales cf
                 WHERE cf.comprobantable_type = Venta
                   AND cf.comprobantable_id   = ventas.id
                   AND cf.estado              = 'aprobado'
                   AND cf.deleted_at IS NULL)

manual ⟺ NOT (firme)
```

Las dos clases particionan el universo: excluyentes y exhaustivas (FR-017), lo que hace verificable
SC-004.

> **Gotcha heredado (`modelo_datos.md`, incidente Venta 24447)**: la cardinalidad es **1→N**, no 1→1 — una
> venta reintentada tiene una fila `rechazado` y una `aprobado`. **No usar** la relación `morphOne`
> `comprobanteFiscal()` para clasificar: devuelve el rechazo más viejo y hace parecer que la venta no
> tiene CAE. El `EXISTS` de arriba resuelve la cardinalidad y satisface FR-018 (cuenta una sola vez).

En **IVA Compras** esta clasificación no aplica: el comprobante lo emite el proveedor (FR-014a).

---

## 4. Columnas del libro (19, en orden — FR-020)

| # | Columna | Origen |
|---|---|---|
| 1 | Id | `ventas.id` / `compras.id` / `notas_credito_debito.id` |
| 2 | Emisión | `fecha_emision` de la fila (la real, **no** el mes de imputación) |
| 3 | Tipo | tipo de comprobante (`FEA`, `FEB`, `FA`, `NCA`, `NDA`, …) |
| 4 | N° de Comprobante | número fiscal si hay CAE aprobado; si no, `nro_comprobante` |
| 5 | Cliente / Proveedor | `clientes.nombre` / `proveedores.nombre` |
| 6 | CUIT / DNI | `clientes.cuit` / `proveedores.cuit` |
| 7 | Condición de IVA | `condiciones_iva.nombre` de la contraparte |
| 8 | Importe Neto No Gravado | `SUM(DesgloseImpositivo*::sqlNeto('no_gravado', …))` |
| 9 | Importe Neto Exento | `SUM(… sqlNeto('exento', …))` |
| 10 | Importe Neto Gravado | `SUM(… sqlNeto('gravado', …))` |
| 11–15 | IVA 2,5% / 5% / 10,5% / 21% / 27% | `SUM(… sqlIva($alicuota, …))`, una por alícuota |
| 16 | Perc. IVA | total del concepto por comprobante (**sin prorratear**) |
| 17 | Perc. IIBB | ídem |
| 18 | Imp. Internos | ídem |
| 19 | Imp. Municipales | **constante `0`** — brecha documentada (research, Decisión 10) |

> **Por qué "sin prorratear"**: `DesgloseImpositivoVenta::sqlConceptoProrateado()` reparte el concepto
> entre las líneas con funciones de ventana, porque el informe de Ventas trabaja a nivel ítem. Acá la
> fila **es** el comprobante, así que se toma el total del concepto directo. Sumar prorrateos daría lo
> mismo pero pasando por un redondeo intermedio que puede meter centavos, justo lo que FR-011 prohíbe.

### Notas de crédito/débito — el punto más delicado del informe

Una NC/ND aporta **una fila** (no una por ítem). Sus importes llevan el signo del tipo:

```
signo = CASE notas_credito_debito.tipo WHEN 'credito' THEN -1 ELSE 1 END
```

Mismo criterio que `ComprasInformeQuery` y `VentasInformeQuery`, para que los tres informes concilien
(FR-022).

**Columna "Tipo"** (FR-020a): `notas_credito_debito.tipo_comprobante` guarda sólo la **letra** del
comprobante original (`A`, `B`, …), pero el libro muestra `NCA` / `NDA`. Se compone:
`(tipo = 'credito' ? 'NC' : 'ND') || tipo_comprobante`.

#### Desglose impositivo de la nota (FR-022c, FR-022d)

> ⚠️ **Acá no se puede copiar el criterio de la spec 067.** `ComprasInformeQuery::queryNotas()` emite las
> columnas impositivas de las notas **en cero**, porque `nota_credito_debito_items` es un pivot de
> `producto_id + cantidad + precio` y **no guarda `iva_pct`**. Para el informe de Compras eso alcanza. Para
> un **Libro IVA no alcanza**: dejar las notas sin discriminar subdeclara IVA — crédito fiscal perdido en
> Compras, débito fiscal no declarado en Ventas.
>
> **Y las capturas lo confirman**: en `05_iva_compras_...jpg` la NCA `0058-00365767` muestra
> `Importe Neto Gravado $30.577,03` **e** `IVA 21% $6.421,18`. La aritmética cierra:
> `30.577,03 × 1,21 = 36.998,21 = 30.577,03 + 6.421,18`. Contagram parte el monto por la alícuota.

Orden de precedencia a implementar:

| # | Condición | Desglose |
|---|---|---|
| 1 | La nota tiene entradas de IVA en `impuestos` (JSON, conectado a la UI por la spec 061) | se usan esas — es el dato cargado por el usuario y el fiscalmente cierto |
| 2 | No tiene, pero se identifica el comprobante ajustado (`venta_id` / `compra_id`) con **una sola** alícuota | hereda esa alícuota; `neto = monto / (1 + a)`, `iva = monto − neto` |
| 3 | El comprobante ajustado combina **varias** alícuotas | el monto se reparte entre ellas en proporción al neto de cada alícuota en el comprobante original |
| 4 | No hay comprobante ajustado identificable | el monto entero va a **No Gravado** — conservador: no inventa crédito ni débito fiscal |

Las percepciones e impuestos internos de la nota salen de su JSON `impuestos`
(`{tipo, concepto, monto}`), clasificados con las mismas palabras clave que ya usa
`DesgloseImpositivoVenta::PALABRAS_PERCEPCION`, para no divergir en la clasificación.

**Cada una de las cuatro ramas necesita test propio** (FR-022d).

---

## 5. Totales del período (FR-010, FR-011, FR-011a)

Cuatro agregados en SQL sobre el conjunto filtrado completo, más uno derivado en PHP:

| Total | Cálculo |
|---|---|
| No Gravados / Exentos | `SUM(neto_no_gravado) + SUM(neto_exento)` |
| Gravados | `SUM(neto_gravado)` |
| IVA Total | `SUM(iva_2_5 + iva_5 + iva_10_5 + iva_21 + iva_27)` |
| Perc. IVA/IIBB Total | `SUM(perc_iva) + SUM(perc_iibb)` |
| **Total Facturado** | **suma en PHP de los cuatro anteriores, ya redondeados a 2 decimales** |

**Imp. Internos e Imp. Municipales quedan fuera de la ecuación** (FR-011a): se listan por comprobante
pero no suman a ningún total. Verificado contra las capturas — en IVA Compras los cuatro componentes dan
exactamente el Total Facturado mostrado.

> **Por qué Total Facturado se calcula en PHP y no como un quinto `SUM`**: para que la ecuación cierre
> **por construcción**. Contagram lo calcula por separado y arrastra 1 centavo de diferencia en la
> captura de IVA Ventas (`2.669.509,27 + 560.596,95 = 3.230.106,22` vs. `3.230.106,21` mostrado). Esta
> spec diverge deliberadamente (Clarifications).

---

## 6. Filtros (FR-026)

| Filtro | Columna / criterio | Coincidencia |
|---|---|---|
| Id | `id` de la fila | exacta |
| Tipo de Comprobante | tipo de comprobante | exacta (multi-valor) |
| N° de Comprobante | número del comprobante | parcial (`LIKE`) |
| Cliente / Proveedor | FK a `clientes` / `proveedores` | exacta (multi-valor, Select2 con `ajax`) |
| N° de CUIT | `cuit` de la contraparte | parcial (`LIKE`) |
| Condición de IVA | FK a `condiciones_iva` | exacta (multi-valor) |
| Medio de Cobro / Pago | `EXISTS` sobre `cobros`/`pagos` por `cuenta_tesoreria_id` | exacta |
| Provincia | `COALESCE(provincia_fiscal, provincia)` de la contraparte | exacta |

Todos se combinan con `AND` y siempre **dentro** del período (FR-027).

> **Medio de Cobro/Pago va con `EXISTS`, nunca con `JOIN`**: una venta con 3 cobros aparecería 3 veces y
> triplicaría sus importes en los totales (research, Decisión 11).

> **Provincia usa la fiscal con respaldo en la comercial**: es la que corresponde a un libro de IVA.
> `clientes` guarda el **nombre** de la provincia como string, no la FK (`modelo_datos.md`), así que la
> comparación es por nombre.

---

## 7. Exclusiones

- Comprobantes con borrado lógico (`deleted_at`) quedan **fuera** (FR-022b), igual que en los informes de
  Ventas y Compras — así los tres concilian.
- Presupuestos no participan: no son comprobantes fiscales.

---

## 8. Brechas de modelo detectadas

| Brecha | Impacto | Resolución en esta spec |
|---|---|---|
| No existe concepto de **impuesto municipal** diferenciado | columna 19 del libro | se emite `0`; queda anotada en `docs/documentacion_principal_crm.md §5` |

Ninguna otra columna de la pantalla relevada carece de respaldo en el modelo.
