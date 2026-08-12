# Data Model — spec 064

Dos tablas nuevas, campos nuevos en una existente, y **una corrección de nulabilidad** que hoy tiene
rota la mitad de la funcionalidad.

## `transportistas` — tabla nueva

| Campo | Tipo | Para qué |
|---|---|---|
| `id` | bigint PK | |
| `nombre` | string | único atributo — fidelidad al alta rápida de Contagram (captura 04) |
| `created_at` / `updated_at` | timestamps | |

**Regla**: un nombre ya existente se reutiliza en vez de duplicarse (FR-023). Sin CUIT, patente ni
contacto: Contagram no los pide.

## `remito_items` — tabla nueva

La pieza que falta hoy: sin esto el remito no dice qué se entrega.

| Campo | Tipo | Para qué |
|---|---|---|
| `id` | bigint PK | |
| `remito_id` | FK → `remitos`, cascade on delete | dueño |
| `producto_id` | FK → `productos`, **nullable** | null para ítems libres de la Venta (líneas escritas a mano) |
| `codigo` | string, nullable | snapshot del código al momento de remitir; vacío en ítems libres |
| `descripcion` | string | snapshot del nombre del producto (sobrevive a la baja del producto) |
| `observacion` | string, nullable | texto libre por línea (FR-003) |
| `cantidad` | decimal(14,3) | debe ser > 0 (FR-009) |

**Sin precio, sin IVA, sin subtotal.** El remito es logístico (FR-012).

**Por qué snapshot de código y descripción**: el edge case de "producto dado de baja" exige que el
remito se pueda imprimir igual — documenta una entrega que ocurrió. Guardar el texto evita depender de
que el producto siga existiendo.

## `remitos` — campos nuevos

| Campo | Tipo | Para qué |
|---|---|---|
| `transportista_id` | FK → `transportistas`, nullable | quién traslada (FR-021) |
| `domicilio_entrega` | string, nullable | precargado y editable, sin tocar la ficha de origen (FR-005) |
| `nota` | text, nullable | "Nota para el Cliente" |
| `monto_asegurado` | decimal(14,2), **nullable** | `null` = interruptor apagado. Dato interno: **no se imprime** (FR-007) |
| `tipo` | string(1), default `X` | letra del comprobante. Informativa: el remito no es fiscal |

**`total_bultos` NO se persiste**: se deriva de la suma de `remito_items.cantidad`. Un total guardado
puede desincronizarse de sus líneas, y con este volumen no hay razón de performance para
denormalizarlo.

## `remitos` — corrección de nulabilidad (bug preexistente)

Estado actual en producción:

```
venta_id    bigint unsigned  NOT NULL      ← el problema
compra_id   bigint unsigned  NULL
```

`docs/modelo_datos.md` ya documenta la regla correcta —*"exactamente uno de los dos"*— pero **la base
no la cumple**: `venta_id` es NOT NULL. Como `CompraController::remitoStore()` crea el remito seteando
sólo `compra_id`, **crear un remito desde una Compra falla**. Nunca se detectó porque nunca se probó.

Es **el mismo bug**, en otra tabla, que el ya registrado en `docs/importacion_casos_a_revisar.md` §0:

> `notas_credito_debito.venta_id` era NOT NULL aunque el código lo declara nullable → **emitir una
> NC/ND de una compra fallaba**; nunca se había probado.

**Corrección**: `venta_id` pasa a nullable. Las 2 filas históricas que quedan (tras borrar el N° 3)
tienen ambas `venta_id` cargado, así que la migración no las altera.

## Ciclo de vida

```
crear remito      → escribe SOLO en remitos + remito_items
editar remito     → idem. Ningún campo bloqueado (FR-016)
eliminar remito   → borrado REAL (no soft delete): no es documento fiscal ni contable
eliminar Venta    → sus remitos se eliminan con ella (FR-018)
```

**En ningún punto del ciclo se tocan**: stock, movimientos de tesorería, cobros, cuenta corriente,
comprobantes fiscales, ni los totales de la Venta/Compra de origen (FR-010).

## Relaciones

```
Venta   ──1:N──┐
               ├──> Remito ──1:N──> RemitoItem ──N:1──> Producto (nullable)
Compra  ──1:N──┘        │
                        └──N:1──> Transportista (nullable, reutilizable)
```

Exactamente uno de `venta_id` / `compra_id` está seteado en cada remito.
