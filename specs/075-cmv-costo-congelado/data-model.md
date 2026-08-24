# Data Model: Costo congelado en el ítem de venta (spec 075)

**Fecha**: 2026-08-24

Cambio de esquema mínimo: **dos columnas nuevas, ninguna tabla nueva, ninguna relación nueva.**

---

## 1. `venta_items.costo_unitario` (nueva)

| Propiedad | Valor |
|---|---|
| Tipo | `decimal(14, 2)` |
| Nullable | **Sí** |
| Default | **Ninguno** (queda `NULL`) |
| Índice | No |

Costo del producto vigente en el momento en que se creó la venta. Inmutable: ninguna edición
posterior de la venta ni de la ficha del producto lo recalcula (FR-001, FR-004, FR-009).

### Por qué nullable y sin default

Es la decisión de diseño más importante del cambio, y es la que hace posible que el fallback funcione:

- **`NULL`** = "esta línea no tiene costo congelado" ⇒ el CMV cae al promedio ponderado de compras
  (FR-003). Es el estado de las ~1M de líneas históricas.
- **`0`** = "esta línea tiene costo congelado y vale cero" ⇒ el CMV es 0 (FR-007). Es el caso del
  producto sin costo cargado (227 productos hoy) o de la línea sin producto asociado.

Un `default 0` haría estos dos casos indistinguibles y el fallback nunca se activaría: toda venta
histórica pasaría a aportar 0 al CMV, que es exactamente la regresión que la User Story 2 prohíbe.

Precisión `decimal(14, 2)`, alineada con `productos.costo` y con `venta_items.precio_unitario`.

### Reglas de asignación

| Situación | Valor asignado |
|---|---|
| Alta de venta, línea con producto | `productos.costo` vigente al crear |
| Alta de venta, línea sin `producto_id` | `0` |
| Alta de venta, producto con costo nulo o 0 | `0` |
| Edición, línea preexistente | **se conserva** el valor que ya tenía |
| Edición, línea agregada en esa edición | `productos.costo` vigente al momento de editar |
| Venta creada desde Mercado Libre / Tiendanube | `productos.costo` vigente al crear la venta en el CRM |
| Venta creada desde presupuesto | `productos.costo` vigente al crear la **venta** (no el presupuesto) |
| Comandos de migración histórica | **no se toca** ⇒ queda `NULL` ⇒ fallback |

---

## 2. `nota_credito_debito_items.costo_unitario` (nueva)

Misma definición: `decimal(14, 2)`, nullable, sin default.

### Reglas de asignación (FR-008)

> **Corregido el 24/08/2026, durante la implementación.** Esta tabla estaba escrita en función de
> la columna `origen`, y eso era un error: en `NotaCreditoDebitoController` `origen` **no**
> distingue "revierte la venta original" de "ajuste nuevo", distingue si la nota afecta stock o no.
> Una NC que anula una venta entera sin tocar stock guarda todas sus líneas como `nuevo`, así que
> la regla por `origen` le aplicaba el costo de hoy y anular una venta dejaba un residuo en el
> Resultado — exactamente lo que FR-008 prohíbe. La regla implementada mira la venta de origen y el
> producto, no `origen`. Hay test dedicado sobre el endpoint real.

| Nota con `venta_id` | Producto en la venta de origen | Valor asignado |
|---|---|---|
| Sí | Sí | Copia del `costo_unitario` de esa línea (primera no consumida, en orden de `id`) |
| Sí | Sí, pero esa línea tiene `NULL` | `NULL` ⇒ fallback (la venta es histórica; el neto sigue dando 0) |
| Sí | No | `productos.costo` vigente al emitir la nota |
| No (`venta_id` nulo) | — | `productos.costo` vigente al emitir la nota |
| — | Línea sin `producto_id` | `0` |

`nota_credito_debito_items` **no** guarda referencia al `venta_item` de origen; la correspondencia se
resuelve por `notas_credito_debito.venta_id` + `producto_id`. Si la venta original tiene varias líneas
del mismo producto, se toma la primera no consumida (mismo criterio que la edición de ventas).

El signo lo sigue aportando la cantidad, no el costo: el costo se guarda **en positivo** y la
expresión del informe lo multiplica por la cantidad ya firmada. Así se mantiene "una sola fórmula sin
ramas por tipo de comprobante" (FR-016 de la spec 068).

---

## 3. Entidades sin cambios

- **`productos`**: su `costo` sigue siendo el costo vigente y sigue alimentando "Costo Actual"
  (FR-006). Ahora además es la **fuente** del valor que se congela.
- **`compra_items`**: sin cambios. Sigue alimentando el promedio ponderado que actúa de fallback.
- **`presupuesto_items`**: sin cambios. Un presupuesto no congela costo; recién lo hace la venta que
  se genera a partir de él.
- **`movimientos_stock`**: sin cambios. Sigue sin costo histórico; esta spec no lo necesita.

---

## 4. Migración

- Una migración `add_costo_unitario_to_venta_items_and_nota_items` que agrega las dos columnas.
- **No hay `UPDATE` de datos**: todas las filas existentes quedan en `NULL` a propósito, que es lo que
  activa el fallback y garantiza cero regresión histórica (SC-003).
- `down()` elimina las dos columnas. Reversible sin pérdida de datos de negocio previos al cambio.

---

## 5. Expresión de cálculo del informe

Una sola expresión, sin ramas por tipo de comprobante:

```
CMV_linea = COALESCE(<tabla>.costo_unitario, <promedio_compras>.costo_promedio, 0) × <cantidad_firmada>
```

El `leftJoinSub` de `CostoMercaderiaVendida` se conserva tal cual: ya no es la regla, es el segundo
término del `COALESCE`.

Invariante que debe fijar un test: **`costo_unitario = 0` gana sobre el promedio de compras.** Si el
`COALESCE` se escribiera sobre un valor no-nulo mal elegido (por ejemplo `NULLIF(costo_unitario, 0)`),
una venta nueva de un producto sin costo tomaría el promedio de compras y dejaría de reproducir a
Contagram, que en ese caso muestra 0.
