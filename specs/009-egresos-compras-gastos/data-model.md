# Data Model — Módulo Egresos

Deriva de `spec.md`, `research.md`, `docs/informe_contagram_egresos.md` y `docs/modelo_datos.md §7`
(donde ya están documentadas como pendientes de implementar). Convenciones: español, snake_case,
single-tenant, `id` bigIncrements, timestamps. Reutiliza tablas existentes: `proveedores`, `productos`,
`categorias`, `cuentas_tesoreria` (spec 007), `usuarios`, y la tabla `retenciones` (documentada en spec
008/modelo_datos.md §5, sin flujo que la poblara hasta ahora — se crea junto con esta spec).

## `compras`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| proveedor_id | FK → proveedores | |
| categoria_id | FK → categorias (tipo=compra), nullable | autocompletada desde `proveedores.categoria_id` (FR-002) |
| tipo_comprobante | string, nullable | "Tipo" — **dato sin emisión fiscal** (watermark). Análogo a `ventas.tipo_comprobante` |
| nro_comprobante | string, nullable | secuencia interna simple por tipo |
| fecha_emision | date | |
| fecha_vto_pago | date, nullable | "Vto. del Pago" |
| servicio_desde / servicio_hasta | date, nullable | |
| mes_imputacion_iva | date, nullable | campo **"Contador"**, exclusivo de Compras — independiente de `fecha_emision` |
| subtotal_sin_descuento, descuento, subtotal_con_descuento, total | decimal(14,2) | snapshot calculado por `CalculoComprobante` (reutilizado, ver research §1) |
| nota_interna | text, nullable | |
| deleted_at | timestamp, nullable | **SoftDeletes** (Principio III) |

`total` se congela como snapshot; `pagado` y `a_pagar` son **derivados** (research §3, Clarifications):
`pagado = Σ pagos`; `a_pagar = total + Σ ND − Σ NC − pagado`. Índices:
`unique(tipo_comprobante, nro_comprobante)`, `index(proveedor_id)`, `index(fecha_emision)`.

No existe columna `estado`: el badge Pagado/A Pagar se deriva en la consulta del listado (mismo criterio
que `ventas.estado_cobro`, pero sin persistirlo siquiera como enum — ver research §3).

## `compra_items`

id, compra_id (FK cascade), producto_id (FK → productos, nullable — ítem libre), descripcion (string),
cantidad (decimal(14,3)), precio_unitario (decimal(14,2)), descuento_pct (decimal(5,2), nullable),
**iva_pct (string(12), nullable, SIN default** — a diferencia de `venta_items.iva_pct`, ver research §2),
subtotal (decimal(14,2)), subtotal_con_iva (decimal(14,2), nullable mientras `iva_pct` sea null).

## `compra_conceptos` (Percepciones / Impuestos Internos / Intereses)

Idéntico a `venta_conceptos`/`presupuesto_conceptos` (spec 008): id, compra_id (FK cascade), tipo (enum
`percepcion`,`impuesto_interno`,`interes`), concepto (string), monto (decimal(14,2)). Múltiples por tipo.

## `pagos`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| compra_id | FK → compras (cascade lógico vía soft delete) | |
| fecha | date | |
| cuenta_tesoreria_id | FK → cuentas_tesoreria (spec 007) | "Medio de Pago" |
| monto | decimal(14,2) | ≤ saldo A Pagar de la compra (`StorePagoRequest`) |
| nota | text, nullable | |
| nro_comprobante | string, nullable | autogenerado al confirmar (ej. "X 0001-00000005") |
| deleted_at | timestamp, nullable | SoftDeletes |

Al crear, `Services/Egresos/Pagos` registra un `MovimientoTesoreria` (`tipo=pago`, `monto=-`,
`origen=pago`) en la cuenta. Al soft-delete, ese movimiento se anula (research §5).

## `gastos`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| fecha | date | default hoy en el formulario |
| monto | decimal(14,2) | |
| categoria_id | FK → categorias (tipo=gasto) | jerárquico vía `categoria_padre_id`; árbol independiente del de Proveedores (research §7) |
| cuenta_tesoreria_id | FK → cuentas_tesoreria, nullable | "Elija un medio de pago" (null si pendiente) |
| descripcion | text, nullable | |
| pendiente | boolean, default false | "Marcar como pendiente": si true, NO genera movimiento (FR-015) |
| usuario_id | FK → usuarios, nullable | |
| deleted_at | timestamp, nullable | soft delete simple, sin Observer (research §6) |

Genera `MovimientoTesoreria` (`tipo=gasto`, `monto=-`, `origen=gasto`) sólo si `pendiente=false`. Sin
tabla de ítems ni de conceptos — documento atómico, sin ficha de detalle propia.

## `retenciones` (tabla de spec 008, poblada por primera vez desde esta spec)

Ya documentada en `modelo_datos.md §5`: id, cobro_id (FK → cobros, nullable), pago_id (FK → pagos,
nullable), fecha, monto, tipo_retencion (string — Ganancias/IVA/Seguridad Social/Sellos/Ingresos Brutos
por jurisdicción, confirmado en informe_contagram_egresos.md §2.5), nro_comprobante (nullable),
descripcion (nullable). Constraint de aplicación: exactamente uno de `cobro_id`/`pago_id` seteado.

## `notas_credito_debito` (spec 008, extendida)

Se agrega `compra_id` (FK → compras, nullable) junto al ya existente `venta_id` (nullable). Constraint
de aplicación: exactamente uno de `venta_id`/`compra_id` seteado. El resto de columnas (tipo,
afecta_stock, fecha_emision, monto, tipo_comprobante, descripcion, impuestos) se reutiliza sin cambios.

## `nota_credito_debito_items` (spec 008, sin cambios)

Reutilizada tal cual: producto_id (FK), cantidad, precio, origen. Aplica igual si la NC/ND es de Compra
o de Venta.

## Cálculos clave (derivados)

- **Total de comprobante** (compra): `Σ items.subtotal_con_iva − descuento_general + Σ conceptos.monto`
  (mismas reglas de `CalculoComprobante` que Venta/Presupuesto). Se congela en `total`.
- **Pagado** = `Σ pagos.monto` (no soft-deleted).
- **A Pagar** = `total + Σ ND.monto − Σ NC.monto − pagado`.
- **Estado de pago** (badge, no persistido): `pagado = 0` → a_pagar; `0 < pagado < total±notas` →
  parcial; `≥` → pagado. Siempre derivado (Clarifications).
- **KPIs de Compras** (barra de 5): Cantidad de Compras, Pagado, A Pagar, Vencido, Total Compras —
  agregaciones sobre `pagado`/`a_pagar`/`fecha_vto_pago`.

## Impacto en Tesorería (spec 007)

- Cada `Pago` / `Gasto` no-pendiente → `MovimientoTesoreria` (`monto` negativo, origen polimórfico).
- El saldo de la cuenta (derivado en 007) baja automáticamente; soft-delete del pago (o delete del
  gasto) lo excluye.
- **No** se crea ningún catálogo de medios de pago propio: se leen `cuentas_tesoreria` visibles — mismo
  catálogo que Ventas/Otros Ingresos (spec 008).

## Relaciones (resumen)

```
compras 1───N compra_items, compra_conceptos, pagos, notas_credito_debito(compra_id), remitos
compras N───1 proveedores, categorias(compra)
pagos N───1 cuentas_tesoreria (medio) ; 1───1 movimientos_tesoreria (origen) ; 1───N retenciones (pago_id)
gastos N───1 categorias(gasto), cuentas_tesoreria ; 1───1 movimientos_tesoreria (origen, si no pendiente)
notas_credito_debito 1───N nota_credito_debito_items ───1 productos (afecta_stock → movimientos_stock)
```

## Actualización de documentación (al cierre — Principio I)

- `docs/modelo_datos.md §7`: marcar estas tablas como implementadas (hoy "pendiente de implementar");
  actualizar §5 (`retenciones`) para reflejar que ya tiene flujo real que la puebla.
- `docs/documentacion_principal_crm.md §4`: cambiar "documentado, pendiente de implementar" por
  implementado; actualizar §4.3 (dependencias) reflejando que Tesorería ya provee los medios de pago.
