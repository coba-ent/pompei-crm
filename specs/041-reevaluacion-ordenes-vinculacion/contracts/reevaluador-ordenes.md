# Contrato: `ReevaluadorOrdenes` (interno, uno por canal)

No es una API pública ni un endpoint HTTP — es el contrato interno entre los dos puntos de
disparo (Observer, `datatable()`) y la lógica de negocio ya existente (`EvaluadorConvertibilidad`,
`ResolutorCliente`, `ConversorOrdenAVenta`). Se documenta igual porque es la interfaz que
`tasks.md` va a implementar y testear, y para que ambos canales queden simétricos (FR-008).

Dos implementaciones idénticas en forma, una por canal:
- `App\Services\MercadoLibre\ReevaluadorOrdenes`
- `App\Services\Tiendanube\ReevaluadorOrdenes`

## Métodos

### `reevaluarUna(MercadoLibreOrden $orden, ?int $usuarioId = null): void`
*(TN: `reevaluarUna(TiendanubeOrden $orden, ?int $usuarioId = null): void`)*

Reevalúa una única orden puntual y persiste el resultado. Uso: el Observer, tras resolver qué
órdenes están afectadas por la vinculación tocada, llama esto por cada una.

**Precondición**: la orden NO debe tener `venta_id` seteado. Si lo tiene, el método no hace nada
(no-op) — no lanza excepción, para que el llamador no tenga que filtrar dos veces (defensa en
profundidad además del filtro en la query, FR-005).

**Comportamiento**:
1. Resuelve `clienteEsAmbiguo` vía `ResolutorCliente::buscarExistente($orden)` (igual que hace
   `SincronizadorOrdenes::procesarOrden()` hoy).
2. Llama a `EvaluadorConvertibilidad::evaluar($orden, $clienteAmbiguo)` → `[estado, motivo, detalle]`.
3. Persiste `estado_conversion`, `motivo`, `motivo_detalle` en la orden (`update()`).
4. Si `$estado === EstadoConversion::Lista` y la configuración del canal tiene
   `creacion_automatica` activo, intenta `ConversorOrdenAVenta::convertir($orden, $usuarioId, automatica: true)`
   dentro de un `try/catch` — ante excepción, marca la orden `RequiereAtencion` /
   `MotivoRequiereAtencion::ErrorConversion` con el detalle del error (mismo comportamiento que
   `SincronizadorOrdenes::intentarCreacionAutomatica()`, FR-004).

**Postcondición**: la orden queda en un estado consistente con
`EvaluadorConvertibilidad::evaluar()` al momento de la llamada; si pudo convertirse
automáticamente, queda `Convertida` con `venta_id` seteado.

### `reevaluarAfectadasPorPublicacion(string $mlItemId, ?int $usuarioId = null): int`
*(TN: `reevaluarAfectadasPorVariante(string $variantId, ?int $usuarioId = null): int`)*

Uso: llamado por el Observer tras `saved`/`deleted` de la vinculación. Busca las órdenes
afectadas (ver `data-model.md` — no convertidas, `estado_conversion` en `[requiere_atencion, lista]`,
con algún ítem cuyo `ml_item_id`/`variant_id` coincida) y llama `reevaluarUna()` por cada una.

**Retorna**: cantidad de órdenes reevaluadas (para logging/tests, no para UI).

### `reevaluarPendientesDelCanal(?int $usuarioId = null): int`

Uso: llamado desde `datatable()` de cada controlador de ventas, antes de listar. Reevalúa **todas**
las órdenes no convertidas en `estado_conversion = requiere_atencion` del canal (no filtra por
ítem/publicación específica — es la barrida completa, FR-006/FR-007).

**Retorna**: cantidad de órdenes reevaluadas.

## Invariantes comunes a los 3 métodos (ambos canales)

- Nunca modifican una orden con `venta_id` no nulo (FR-005).
- Nunca modifican una orden `cancelada` (fuera del alcance de `EvaluadorConvertibilidad`, que ya
  no la contempla como transición válida).
- Reusan `EvaluadorConvertibilidad`/`ConversorOrdenAVenta`/`ResolutorCliente` tal cual existen hoy
  — este servicio no reimplementa ninguna regla de convertibilidad (FR-003).
- Se pueden llamar dentro o fuera de una transacción abierta por el llamador; el propio
  `ConversorOrdenAVenta::convertir()` maneja su propio candado (`Cache::lock`) y transacción
  atómica, como ya hace hoy.
