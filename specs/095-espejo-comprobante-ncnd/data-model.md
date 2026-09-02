# Data Model: Espejo del comprobante de origen al crear una NC/ND

**Feature**: 095-espejo-comprobante-ncnd | **Date**: 2026-09-02

> **Sin cambios de esquema.** Todos los campos que este trabajo llena ya existen. Lo que cambia es de
> dónde salen sus valores iniciales al abrir el alta.

## Entidades involucradas

### Comprobante de origen — `ventas` / `compras`

Fuente de todos los valores precargados. Sólo lectura: este trabajo no lo modifica.

| Campo | Uso en la precarga |
| --- | --- |
| `tipo_comprobante` | Tipo de la nota (FR-004) |
| `descuento_general_tipo` | Modo del descuento de la nota: porcentaje o monto (FR-002) |
| `descuento_general_pct` | Descuento de la nota cuando el modo es porcentaje |
| `descuento_general_monto` | Descuento de la nota cuando el modo es monto fijo |
| `fecha_emision` | Emisión de la nota, y respaldo de las otras tres fechas (FR-005) |
| `fecha_vto_cobro` (venta) / `fecha_vto_pago` (compra) | Vencimiento de la nota |
| `servicio_desde` / `servicio_hasta` | Período de servicio de la nota |
| `cliente_id` (venta) / `proveedor_id` (compra) | Tercero mostrado, heredado (FR-006) |
| `categoria_id` | Categoría mostrada, heredada |
| `nro_comprobante` | Referencia del documento que se ajusta (ya se envía hoy) |
| `deposito_id` | Depósito sugerido (ya se envía hoy) |

### Nota de Crédito/Débito — `notas_credito_debito`

Destino de la precarga. **Los campos ya existen**; hoy nacen vacíos en el alta.

| Campo | Estado hoy | Después |
| --- | --- | --- |
| `tipo_comprobante` | vacío | heredado del comprobante, editable con aviso (FR-004a) |
| `descuento_general_tipo` | vacío | heredado |
| `descuento_general_pct` | vacío | heredado si el modo es porcentaje |
| `descuento_general_monto` | vacío | heredado si el modo es monto |
| `fecha_emision` | hoy | heredada del comprobante |
| `impuestos` (JSON) | vacío | percepciones e impuestos internos heredados (FR-007) |

### Conceptos del comprobante — `venta_conceptos` / `compra_conceptos`

Origen de las percepciones e impuestos internos. Se leen y se traducen a la forma que la nota usa en
su columna JSON `impuestos`:

```
{ tipo, concepto, monto }
```

La forma coincide en ambos lados, así que la traducción es directa (research, Decisión 3).

### Ítem de la nota — `nota_credito_debito_items`

**Sin cambios.** La precarga de ítems ya funciona y sigue partiendo de la cantidad **pendiente de
ajuste** por producto (FR-009), que prevalece sobre la coincidencia de totales.

## Reglas de derivación

| Campo de la nota | Regla |
| --- | --- |
| Tipo de comprobante | El del comprobante. Si está vacío o es "Sin Factura", queda vacío — no se inventa (FR-004). |
| Descuento general | El del comprobante, respetando su modalidad. En modo monto se hereda el importe tal cual, sin convertir a porcentaje (FR-002, Q2). |
| Emisión | La del comprobante (FR-005). |
| Vencimiento, Servicio Desde, Servicio Hasta | Los del comprobante; si alguno no está cargado, cae en la Emisión del comprobante (FR-005). |
| Tercero y categoría | Los del comprobante, mostrados como heredados (FR-006). |
| Percepciones / impuestos internos | Los del comprobante (FR-007). |
| Monto y descripción | **Sólo** en notas sin ítems ("afecta stock = No"): quedan vacíos (FR-013). |

## Validaciones

- **Total no negativo** (FR-012): si el descuento general en modo monto pasa a superar el subtotal de
  las líneas que quedaron, se avisa y no se guarda. No se reajusta solo.
- **Tipo cruzado** (FR-004a): si el tipo elegido difiere del comprobante de origen, se advierte antes
  de guardar. Informa, no bloquea.
- **Editable siempre** (FR-008): la precarga propone; lo que se guarda es lo que quedó en pantalla.

## Fuera de alcance

La edición de una nota existente sigue precargando desde la nota guardada, no desde el comprobante
(FR-011). Ninguna de las 860 notas existentes se modifica.
