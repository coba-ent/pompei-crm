# Data Model — spec 063

No se crean tablas nuevas. Se amplían dos existentes y dos enums.

## `ml_ordenes` — sin cambios de esquema

Ya tiene todo lo necesario: `estado_conversion`, `motivo`, `motivo_detalle`, `venta_id`,
`estado_ml`, `estado_orden`. Lo que cambia es **cómo se usan** para una orden ya convertida.

| Campo | Uso nuevo |
|---|---|
| `estado_conversion` | pasa a `requiere_atencion` cuando la orden se cancela después de convertida |
| `motivo` | recibe uno de los tres motivos nuevos (ver enum abajo) |
| `motivo_detalle` | texto con el estado informado por el marketplace y el importe reembolsado si aplica |

**Regla de identidad**: el aviso es la propia orden en estado `requiere_atencion` con un motivo de
cancelación. No hay entidad separada, así que no puede duplicarse (FR-005 se cumple por
construcción: `updateOrCreate` sobre `ml_order_id`).

## `ml_publicacion_producto` — campos nuevos

| Campo | Tipo | Para qué |
|---|---|---|
| `stock_intentos_fallidos` | entero, default 0 | intentos consecutivos con el mismo error (FR-014) |
| `stock_error_desde` | fecha/hora, nullable | primera falla de la racha actual (FR-014) |
| `stock_requiere_intervencion` | booleano, default false | corta el reintento (FR-015, FR-016) |
| `ultimo_stock_publicado` | entero, nullable | última cantidad confirmada en Mercado Libre — sostiene la diferencia CRM/publicado del panel (FR-018) sin llamar a la API en cada carga. No estaba en el diseño original de esta tabla; se agregó al implementar T026 porque no existía ningún dato que respaldara "lo publicado". |

**Ciclo de vida**:

```
sincronización OK        → intentos = 0, error_desde = null, requiere_intervencion = false
falla (mismo error)      → intentos++, error_desde se fija en la primera
falla (error distinto)   → intentos = 1, error_desde = ahora
intentos alcanza 5       → requiere_intervencion = true
reactivación manual      → intentos = 0, requiere_intervencion = false
```

**Regla**: mientras `stock_requiere_intervencion` sea verdadero, la publicación **no se incluye** en
la selección de pendientes del sincronizador. Ahí está el ahorro de las ~305 llamadas fallidas.

## `EstadoOrden` — dejar de colapsar tres estados

Hoy:

```php
'cancelled', 'pending_cancel', 'partially_refunded' => self::Cancelada,
```

Se separan, y se contempla la mediación, que **no viene en el estado de la orden sino en el del
pago** (`payments[].status = in_mediation`). Esto exige que el traductor lea también el pago.

| Estado en el origen | Motivo resultante |
|---|---|
| `cancelled`, `pending_cancel` | cancelada |
| `partially_refunded` | reembolso parcial |
| pago en `in_mediation` | en mediación |

## `MotivoRequiereAtencion` — tres casos nuevos

Se suman a los ocho existentes:

| Caso | Etiqueta |
|---|---|
| `OrdenCancelada` | La orden fue cancelada en Mercado Libre después de facturarse |
| `OrdenReembolsoParcial` | Mercado Libre informó un reembolso parcial |
| `OrdenEnMediacion` | Hay un reclamo en mediación; el desenlace todavía no está definido |

## `EstadoConversion` — una transición nueva

Hoy la máquina de estados permite:

```php
self::Convertida => [self::Cancelada],
```

Hay que admitir `Convertida → RequiereAtencion`, y desde ahí volver a `Convertida` (cuando el aviso
se descarta o la mediación se resuelve a favor) o pasar a `Cancelada` (cuando se confirma la
anulación).

```
Convertida ──────► RequiereAtencion ──────► Cancelada        (se anuló la venta)
     ▲                     │
     └─────────────────────┘                                 (se descartó / se resolvió a favor)
```

## Reversión — sin modelo nuevo

La reversión es una **nota de crédito por el total, con ajuste de stock**, sobre el circuito que ya
existe (`notas_credito_debito` + `nota_credito_debito_items`). No se agregan campos: la nota se crea
como cualquier otra, con `venta_id` apuntando a la venta revertida y `afecta_stock = true`, que es lo
que dispara la reposición del inventario.

**La Venta no cambia de estado ni se elimina.** Queda vigente y compensada por su nota de crédito;
las dos conviven en la historia fiscal. Ver `research.md` §R1.
