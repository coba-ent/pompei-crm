# Data Model — Módulo Ingresos

Deriva de `spec.md`, `research.md`, `docs/informe_contagram_ingresos.md` y `docs/modelo_datos.md §5`
(donde ya están documentadas como pendientes de implementar). Convenciones: español, snake_case,
single-tenant, `id` bigIncrements, timestamps. Reutiliza tablas existentes: `clientes`, `productos`,
`categorias`, `listas_precio`, `movimientos_stock`, `cuentas_tesoreria` (spec 007), `usuarios`.

## `etiquetas`
id, nombre (unique). Catálogo global reutilizable.

## `etiquetables` (pivot polimórfico)
etiqueta_id (FK), etiquetable_type, etiquetable_id. Reutiliza el mismo catálogo entre `presupuestos` y
`ventas`.

## `presupuestos`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nro_presupuesto | string, único | autogenerado |
| cliente_id | FK → clientes | |
| categoria_id | FK → categorias (tipo=venta), nullable | autocompletada desde `clientes.categoria_id` (FR-003) |
| lista_precio_id | FK → listas_precio, nullable | |
| fecha_emision | date | |
| fecha_validez | date, nullable | "Vencido" = validez pasada + estado pendiente (derivado, FR-005) |
| servicio_desde / servicio_hasta | date, nullable | |
| estado | enum(`pendiente`,`rechazado`,`aceptado`) | default `pendiente` |
| venta_id | FK → ventas, nullable | seteado al "Crear Venta" (no reconvertible — FR-009) |
| descuento_general_pct | decimal(5,2), nullable | 0–100 |
| subtotal_sin_descuento, descuento, subtotal_con_descuento, total | decimal(14,2) | snapshot calculado por `CalculoComprobante` |
| nota_cliente, nota_interna | text, nullable | |
| formas_pago, metodos_envio | string, nullable | texto libre |
| vendedor_id | FK → usuarios, nullable | |

Índices: `unique(nro_presupuesto)`, `index(cliente_id)`, `index(estado)`.

## `presupuesto_items`
id, presupuesto_id (FK cascade), producto_id (FK → productos, nullable — ítem libre), descripcion
(string), cantidad (decimal(14,3)), precio_unitario (decimal(14,2)), descuento_pct (decimal(5,2),
nullable), iva_pct (string(12), misma codificación que `productos.iva_venta_pct`), subtotal
(decimal(14,2)), subtotal_con_iva (decimal(14,2)).

## `presupuesto_conceptos` (Percepciones / Impuestos Internos / Intereses)
id, presupuesto_id (FK cascade), tipo (enum `percepcion`,`impuesto_interno`,`interes`), concepto
(string), monto (decimal(14,2)). Múltiples por tipo.

## `ventas`

Espejo de `presupuestos` (mismos bloques cliente/categoría/lista/descuento/notas/formas/envío/vendedor/
etiquetas/totales) más:

| Campo | Tipo | Notas |
|---|---|---|
| presupuesto_id | FK → presupuestos, nullable | "Creada Desde": null = venta directa |
| tipo_comprobante | enum(`A`,`B`,`C`,`E`) | **dato sin emisión fiscal** (watermark). FR-010 |
| nro_comprobante | string, nullable | secuencia interna por tipo, ej. `0001-00000003` |
| fecha_vto_cobro | date, nullable | "Vto. del Cobro" |
| estado_cobro | enum(`sin_cobrar`,`parcial`,`cobrada`) | **derivado** de cobros/notas (no confiar como fuente) |
| deleted_at | timestamp, nullable | **SoftDeletes** (Principio III) |

`total` se congela como snapshot; `cobrado` y `a_cobrar` son **derivados** (research §2): `cobrado = Σ
cobros`; `a_cobrar = total + Σ ND − Σ NC − cobrado`. Índices: `unique(tipo_comprobante, nro_comprobante)`,
`index(cliente_id)`, `index(presupuesto_id)`, `index(estado_cobro)`.

## `venta_items` / `venta_conceptos`
Idénticos a `presupuesto_items` / `presupuesto_conceptos` con FK `venta_id`.

## `cobros` ("Cobranzas")

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| venta_id | FK → ventas (cascade lógico vía soft delete) | |
| fecha | date | |
| cuenta_tesoreria_id | FK → cuentas_tesoreria (spec 007) | "Medio de Cobro" |
| monto | decimal(14,2) | ≤ saldo A Cobrar de la venta (StoreCobroRequest) |
| nota | text, nullable | |
| deleted_at | timestamp, nullable | SoftDeletes |

Al crear (no pendiente), `Cobranzas` registra un `MovimientoTesoreria` (`tipo=cobro`, `monto=+`,
`origen=cobro`) en la cuenta. Al soft-delete, ese movimiento se anula (research §4).

## `otros_ingresos`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| fecha | date | |
| monto | decimal(14,2) | |
| categoria_id | FK → categorias (tipo=ingreso) | "Crear Categoría de Ingreso" inline |
| cuenta_tesoreria_id | FK → cuentas_tesoreria, nullable | medio de cobro (null si pendiente) |
| descripcion | text, nullable | |
| pendiente | boolean, default false | "Marcar como pendiente": si true, NO genera movimiento (FR-021) |
| usuario_id | FK → usuarios, nullable | |
| deleted_at | timestamp, nullable | SoftDeletes |

Genera `MovimientoTesoreria` (`tipo=cobro`, `origen=otro_ingreso`) sólo si `pendiente=false`.

## `notas_credito_debito`

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| venta_id | FK → ventas | "Documento que Ajusta" |
| tipo | enum(`credito`,`debito`) | |
| afecta_stock | boolean, default false | |
| fecha_emision | date | Paso 2 del wizard |
| monto | decimal(14,2) | |
| tipo_comprobante | string | igual al de la venta original |
| descripcion | text, nullable | obligatoria si no afecta stock |
| impuestos | json, nullable | conceptos de impuesto (mismo patrón que conceptos) |
| deleted_at | timestamp, nullable | SoftDeletes |

## `nota_credito_debito_items`
id, nota_credito_debito_id (FK cascade), producto_id (FK), cantidad (decimal), precio (decimal),
origen (enum `venta_original`,`nuevo`). Sólo si `afecta_stock=true`; genera `movimientos_stock`.

## `remitos`
id, venta_id (FK → ventas), fecha (date), nro_remito (string, nullable). **Encabezado** en esta spec
(FR-018); detalle de ítems pendiente de relevamiento propio.

## Cálculos clave (derivados)

- **Total de comprobante** (presupuesto/venta): `Σ items.subtotal_con_iva − descuento_general + Σ
  conceptos.monto` (según reglas de `CalculoComprobante`; el descuento general aplica sobre el subtotal
  de ítems). Se congela en `total`.
- **Cobrado** = `Σ cobros.monto` (no soft-deleted).
- **A Cobrar** = `total + Σ ND.monto − Σ NC.monto − cobrado`.
- **Estado de cobro**: `cobrado = 0` → sin_cobrar; `0 < cobrado < total±notas` → parcial; `≥` → cobrada.
- **KPIs de Presupuestos** (barra de 5): Ventas (convertidos), Vencidos/Rechazados, Pendientes,
  Aceptados, Total Posibles — agregaciones sobre estado/venta_id.

## Impacto en Tesorería (spec 007)

- Cada `Cobro` / `OtroIngreso` no-pendiente → `MovimientoTesoreria` (`tipo=cobro`, origen polimórfico).
- El saldo de la cuenta (derivado en 007) sube automáticamente; soft-delete del cobro lo excluye.
- **No** se crea ningún catálogo de medios de cobro propio: se leen `cuentas_tesoreria` visibles.

## Relaciones (resumen)

```
presupuestos 1───N presupuesto_items, presupuesto_conceptos ; N───N etiquetas
presupuestos 1───1 ventas (venta_id, al convertir)
ventas 1───N venta_items, venta_conceptos, cobros, notas_credito_debito, remitos ; N───N etiquetas
cobros N───1 cuentas_tesoreria (medio) ; 1───1 movimientos_tesoreria (origen)
otros_ingresos N───1 categorias(ingreso), cuentas_tesoreria ; 1───1 movimientos_tesoreria (origen, si no pendiente)
notas_credito_debito 1───N nota_credito_debito_items ───1 productos (afecta_stock → movimientos_stock)
```

## Actualización de documentación (al cierre — Principio I)

- `docs/modelo_datos.md §5`: marcar estas tablas como implementadas (hoy "pendiente de implementar").
- `docs/documentacion_principal_crm.md §3`: cambiar "documentado, pendiente de implementar" por
  implementado; actualizar §3.5 (dependencias) reflejando que Tesorería ya provee los medios de cobro.
