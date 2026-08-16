# Data Model — Módulo Informes, Tanda 1

**Feature**: `067-informes-compras-gastos-ctacte-proveedores`
**Fecha**: 2026-08-14

> **Sin migraciones.** Esta spec no crea, altera ni borra ninguna tabla ni columna. Lo que sigue
> documenta (a) qué tablas se leen, (b) qué vistas derivadas arma cada informe, y (c) cómo se
> calcula cada valor mostrado, para que los tests puedan verificarlo.

---

## 1. Tablas leídas

| Tabla | Rol en esta spec |
|-------|------------------|
| `compras` | Cabecera del comprobante de egreso: proveedor, categoría, depósito, `fecha_emision`, `fecha_vto_pago`, `tipo_comprobante`, `nro_comprobante`, `subtotal_sin_descuento`, `descuento`, `subtotal_con_descuento`, `total`, `nota_interna`, `creado_por_id`. Soft delete respetado. |
| `compra_items` | Una fila del Informe de Compras por cada fila de esta tabla. Aporta `producto_id`, `descripcion`, `cantidad` (puede ser negativa), `precio_unitario`, `descuento_pct`, **`iva_pct`**, `subtotal`, `subtotal_con_iva`. |
| `compra_conceptos` | Importes adicionales: `tipo ∈ {percepcion, impuesto_interno, interes}`, `concepto` (texto), `monto`. Origen de las columnas de percepciones e impuestos internos. |
| `notas_credito_debito` | NC/ND de compra (`compra_id`, `tipo ∈ {credito, debito}`, `monto`). Entran a la ecuación de KPIs y a los saldos. |
| `pagos` | Cancelaciones contra una compra; determinan Pagado / A Pagar y son filas propias en Movimientos. |
| `gastos` | Única fuente del Informe de Gastos: `fecha`, `monto`, `categoria_id`, `cuenta_tesoreria_id`, `descripcion`, `pendiente`, `usuario_id`. |
| `categorias` | `tipo ∈ {venta, compra, gasto, ingreso}` + `categoria_padre_id`. Provee la "Categoría de Compra" (tipo `compra`) y el árbol Categoría→Subcategoría de Gastos (tipo `gasto`) — **dos catálogos distintos sobre la misma tabla**. |
| `proveedores` | Fila del tab Saldos, contenido del modal de ficha, y `saldo_inicial`. |
| `cuentas_tesoreria` | "Medio de Pago" en los tres informes. |
| `productos`, `tipos_producto` | Código, tipo de producto y marca de control de stock para las columnas opcionales de Compras. |
| `etiquetas` (+ pivote polimórfico) | Columna "Etiquetas" y filtro por etiqueta en Compras. |
| `users` | Filtro "Usuario" en Compras y Gastos. |

---

## 2. Vista derivada — Fila del Informe de Compras

Granularidad: **una fila por `compra_items`** (clarificación de sesión). Las NC/ND aportan sus
propias filas con el mismo criterio.

### Columnas por defecto

| Columna | Origen |
|---------|--------|
| Id | `compras.id` (o `notas_credito_debito.id` en filas de NC/ND) |
| Fecha | `compras.fecha_emision` |
| Comprobante | `compras.tipo_comprobante` + `compras.nro_comprobante` |
| Proveedor | `proveedores.nombre` |
| Producto/Servicio | `compra_items.descripcion` (histórica — sobrevive al soft delete del producto) |
| Cant. | `compra_items.cantidad` (admite negativa) |
| Precio | `compra_items.precio_unitario` |
| Total Comprobante | `compras.total` — **repetido** en cada fila de la misma compra |

> **Invariante**: "Total Comprobante" NO puede sumarse por fila. Los KPIs se calculan sobre una
> query agrupada por comprobante. Cubierto por test.

### Columnas opcionales (selector de columnas, FR-014)

| Columna | Derivación |
|---------|-----------|
| Vencimiento | `compras.fecha_vto_pago` |
| CUIT/DNI, Tipo | `proveedores` |
| Tipo de Comprobante, Punto de Venta, N° Factura | descomposición de `compras.tipo_comprobante` / `nro_comprobante` |
| Código, Tipo de producto, Afecta Stock | `productos` / `tipos_producto` vía `compra_items.producto_id` |
| Costo | `productos.costo` **actual** × `compra_items.cantidad` (no histórico — ver §5) |
| Subtotal sin Descuento / Descuento en $ / Subtotal con Descuento | `compras.*` |
| Importe Neto Gravado | Σ `compra_items.subtotal` de ítems con `iva_pct > 0` |
| Importe Neto No Gravado | Σ `compra_items.subtotal` de ítems con `iva_pct IS NULL` |
| Importe Neto Exento | Σ `compra_items.subtotal` de ítems con `iva_pct = 0` |
| IVA 2,5% / 5% / 10,5% / 21% / 27% | Σ (`subtotal_con_iva` − `subtotal`) de los ítems de esa alícuota, una columna por alícuota |
| Perc. IVA / Perc. IIBB / Otras Percepciones | `compra_conceptos` con `tipo='percepcion'`, clasificados por texto de `concepto` (§4) |
| Imp. Internos | Σ `compra_conceptos.monto` con `tipo='impuesto_interno'` |
| Total Compra | `compras.total` |
| Etiquetas | relación polimórfica de etiquetas |

> **Invariante fiscal**: para toda compra,
> `Neto Gravado + Neto No Gravado + Neto Exento + Σ(IVA por alícuota) + Σ(percepciones) + Imp. Internos + intereses = Total Compra`.
> Test obligatorio (constitución III).

### KPIs

```
Total Compras = Total Compras Creadas + Total Nota de Débito − Total Nota de Crédito
Cantidad Compras Creadas = COUNT(DISTINCT compras.id) en el rango filtrado
Cantidad Prod./Serv.     = Σ compra_items.cantidad   (suma de cantidades, no de líneas)
Compra Promedio          = Total Compras ÷ Cantidad Compras Creadas   (0 si el divisor es 0)
Costo Actual             = Σ (productos.costo × compra_items.cantidad)
```

---

## 3. Vista derivada — Fila del Informe de Gastos

Granularidad: **una fila por `gastos`**, ordenada por Categoría → Subcategoría → Fecha, agrupada en
la UI con RowGroup.

| Columna | Origen |
|---------|--------|
| Categoría (grupo nivel 1) | `categorias.nombre` de la categoría raíz (`categoria_padre_id IS NULL`) |
| Subcategoría (grupo nivel 2) | `categorias.nombre` de la categoría hija |
| Id, Fecha, Descripción | `gastos.id`, `gastos.fecha`, `gastos.descripcion` |
| Medio de Pago | `cuentas_tesoreria.nombre` |
| Total | `gastos.monto` |

**Gastos sin subcategoría**: un gasto cuya `categoria_id` apunta directo a una categoría raíz se
agrupa bajo esa Categoría con el rótulo de subcategoría **"Sin subcategoría"**. Un gasto sin
categoría cae en **"Sin categoría" / "Sin subcategoría"**. Nunca se omite (edge case de la spec).

**Subtotales**: se calculan en el servidor (`GastosInformeQuery::subtotales()`), agrupando por
categoría y subcategoría sobre **todo el conjunto filtrado**, no sobre la página visible.

**Invariante**: `Σ subtotales de Categoría = Gasto Total` (FR-026). Test obligatorio.

---

## 4. Clasificación de percepciones (FR-015b)

`compra_conceptos.tipo` no distingue percepción de IVA de percepción de IIBB. La clasificación vive
en un único punto (`DesgloseImpositivoCompra::clasificarPercepcion()`), por coincidencia de texto
sobre `concepto`, insensible a mayúsculas y acentos:

| Coincide con | Columna |
|--------------|---------|
| `iibb`, `ingresos brutos`, `ing. brutos` | **Perc. IIBB** |
| `iva` | **Perc. IVA** |
| cualquier otra cosa | **Otras Percepciones** |

**Invariante**: `Perc. IVA + Perc. IIBB + Otras Percepciones = Σ monto de conceptos tipo percepcion`.
Ninguna percepción se descarta. Test obligatorio.

---

## 5. "Costo Actual" — semántica explícita

`Costo Actual = productos.costo (valor vigente hoy) × cantidad`. **No** es el costo al que se compró:
si el costo del producto se editó después de la compra, este KPI cambia retroactivamente. Es la misma
semántica que Contagram, y la razón por la que su Informe de Stock puede mostrar totales negativos
(§9.2 del relevamiento). Se replica a propósito porque es un indicador de valorización actual, y
FR-012 obliga a explicarlo en un tooltip en pantalla para que nadie lo confunda con el costo real de
compra.

---

## 6. Vista derivada — Cuenta Corriente Proveedores

### 6.1 Fila de "Saldos Proveedores"

Producida por `CuentaCorriente::porCliente('proveedor')` — **servicio existente, no se modifica**.

| Columna | Origen |
|---------|--------|
| `proveedor_id`, `proveedor_nombre` | entidad |
| `a_vencer` | documentos con vencimiento futuro |
| `vencido_0_30`, `vencido_31_60`, `vencido_61_90`, `vencido_mas_90` | buckets por antigüedad del vencimiento |
| Total | suma de los cinco buckets |

Reglas ya implementadas en el servicio y que el informe hereda tal cual:
- Se descartan los documentos con saldo dentro de la tolerancia de cero.
- Los saldos **negativos** (a favor, por NC) **se conservan y se listan**.
- El `saldo_inicial` del proveedor crea la fila aunque no tenga ninguna compra, y suma con su signo.

### 6.2 Fila de "Movimientos"

UNION SQL de cuatro orígenes + una fila sintética, con `NULL` donde la columna no aplica:

| Operación | Origen | Total Compra | Pagado | A Pagar | Medio de Pago |
|-----------|--------|--------------|--------|---------|---------------|
| `compra` | `compras` | ✅ | Σ pagos | total + ND − NC − pagado | — |
| `pago` | `pagos` | — | ✅ | — | ✅ |
| `nota_credito` | `notas_credito_debito` (`tipo=credito`) | — | — | ✅ (negativo) | — |
| `nota_debito` | `notas_credito_debito` (`tipo=debito`) | — | — | ✅ | — |
| `saldo_inicial` | `proveedores.saldo_inicial` (fila sintética, una por proveedor con saldo ≠ 0) | — | — | ✅ | — |

Columnas de la vista: `id`, `fecha_emision`, `proveedor`, `operacion`, `categoria`, `total_compra`,
`pagado`, `a_pagar`, `nro_comprobante`, `medio_pago`, `descripcion`.

**Invariante de consistencia** (FR-036): para cada proveedor,
`Σ a_pagar de filas 'compra' + fila 'saldo_inicial' = Total de ese proveedor en Saldos`.
Test obligatorio — es el mismo invariante que ya cubre el informe de clientes.

### 6.3 Ficha de proveedor (modal, sólo lectura)

Campos expuestos: Proveedor, Nombre, Apellido, Email, Teléfono, Cel., Página Web, Domicilio,
Localidad, Provincia, C.P., Condición de IVA, Comprobante por defecto, Nota. **Sin ningún campo
editable ni acción de escritura** — el endpoint es `GET` y devuelve JSON de lectura.

---

## 7. Deuda de modelo de datos anotada (no se resuelve acá)

| Deuda | Impacto | Dónde va |
|-------|---------|----------|
| `compra_conceptos` no distingue percepción de IVA vs IIBB | obliga a clasificar por texto (§4) | spec propia si "Otras Percepciones" resulta ser el caso mayoritario en datos reales |
| `CuentaCorriente::porCliente()` mal nombrado ahora que sirve a proveedores | sólo legibilidad | renombrar tocaría Dashboard + tests; queda anotado |
| Agregación y bucketing del tab Saldos ocurren en PHP, sin `LIMIT` en SQL | riesgo de memoria con volumen alto; ahora en dos pantallas en vez de una | brecha ya documentada en `documentacion_principal_crm.md §6.4`; spec propia |
| `compra_items` sin `variante_id` | los informes no pueden desagregar por variante | brecha ya documentada en §4.3 |
