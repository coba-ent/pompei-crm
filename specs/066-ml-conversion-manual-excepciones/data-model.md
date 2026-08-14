# Phase 1 — Modelo de datos

## `ml_ordenes` — columnas nuevas

| Columna | Tipo | Nulo | Default | Para qué |
|---|---|---|---|---|
| `en_mediacion` | `boolean` | no | `false` | Hay un reclamo en mediación sobre algún pago de la orden. Lo escribe la sincronización leyendo `payments[].status`; hoy ese dato se pierde y por eso el cron convierte órdenes en mediación (FR-004, FR-005). |
| `forzada_motivo` | `varchar(40)` | sí | `null` | Motivo excepcional que la persona asumió al forzar la conversión. Valor de `MotivoRequiereAtencion`. Sostiene FR-011 y, sobre todo, FR-018: es contra esto que el detector compara para no repetir el aviso. |
| `forzada_por_id` | `foreignId → users` | sí | `null` | Quién forzó la conversión, **como comodidad de lectura**. `nullOnDelete`: si el usuario se borra, esta columna queda en `null` pero la auditoría **no se pierde** — vive en `ml_operaciones_log`, que es la fuente de verdad de FR-011. No invertir esa relación: la orden no es una bitácora. |
| `forzada_en` | `timestamp` | sí | `null` | Cuándo se forzó (FR-011). |

Los tres campos `forzada_*` se escriben juntos o quedan los tres en `null`; no hay estado intermedio válido.

**Índice**: `en_mediacion` entra en el índice compuesto que ya sirve al listado filtrado por estado de
conversión, no en uno propio — un booleano solo tiene selectividad demasiado baja para justificar su índice.

**Sin backfill**: las órdenes existentes quedan con `en_mediacion = false` y se corrigen solas en la
sincronización siguiente, que reevalúa todas las órdenes de su ventana. No hace falta un comando de
migración de datos.

## Transiciones de estado

Sin estados nuevos: la spec decidió no agregar un sexto valor a `EstadoConversion`. Lo que cambia es **qué
estado le toca** a dos situaciones que hoy pasan de largo.

```
Orden pagada, sin Venta
├── con reclamo en mediación      → RequiereAtencion (orden_en_mediacion)      ← NUEVO
├── con reembolso parcial         → RequiereAtencion (orden_reembolso_parcial) ← NUEVO
├── con alerta de fraude          → RequiereAtencion (alerta_fraude)              ya existía
└── sin nada de lo anterior       → Lista / RequiereAtencion (motivos de datos)  sin cambios

Orden cancelada                   → Cancelada                                    sin cambios
```

**Precedencia** (idéntica a la que ya aplica `DetectorCancelaciones`, extraída a un único lugar compartido):

```
mediación → cancelada → reembolso parcial → alerta de fraude
```

La mediación va primero porque puede convivir con cualquier estado de orden: se lee del pago, no de la orden.

**Vuelta atrás (FR-007)**: no necesita nada especial. El evaluador corre completo en cada sincronización, así
que una orden cuyo reclamo se resolvió vuelve a `Lista` sola. Los campos `forzada_*` **no** se limpian: son
un hecho histórico, no un estado.

## Definición de "estado excepcional"

Es un concepto derivado, no una columna. Una orden está en estado excepcional cuando se cumple al menos una:

| Condición | Origen del dato |
|---|---|
| `estado_orden = Cancelada` | `status` de la orden |
| `en_mediacion = true` | `payments[].status` (columna nueva) |
| `estado_orden = ReembolsoParcial` | `status` de la orden |
| `tiene_alerta_fraude = true` | columna existente |

Vive como método en el modelo `MercadoLibreOrden` y como conjunto de motivos en `MotivoRequiereAtencion`,
para que exista una sola definición y no cuatro condiciones sueltas repartidas por el código.

## Enums

**`MotivoRequiereAtencion`** — no se agregan casos, se agrega comportamiento:

```
motivosExcepcionales(): [OrdenEnMediacion, OrdenCancelada, OrdenReembolsoParcial, AlertaFraude]
esExcepcional(): bool
```

Es distinto de `motivosDeCancelacionPosterior()`, que ya existe y **no incluye** la alerta de fraude: aquel
conjunto responde "qué avisos post-conversión existen", este responde "qué frena una conversión". Se parecen
pero no son lo mismo, y unificarlos rompería la spec 063.

**`EstadoConversion`** — sin cambios. `habilitaCrearVenta()` sigue devolviendo `true` sólo para `Lista`, que
es lo que mantiene al cron y al lote afuera.

## Entidades no persistidas

**Registro de conversión forzada** — se apoya en `ml_operaciones_log`, que ya existe:

| Campo | Valor |
|---|---|
| `operacion` | `convertir_orden_forzada` |
| `sentido` | `escritura` |
| `resultado` | `ok` / `error` |
| `usuario_id` | quién confirmó |

El detalle del motivo queda además en la orden, porque el detector lo necesita en cada corrida y consultar la
bitácora para eso sería frágil.
