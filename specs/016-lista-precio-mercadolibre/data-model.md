# Data Model: Gestión de precios de Mercado Libre desde una Lista de Precios del CRM

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md) · **Research**: [../research.md](../research.md)

No se crean tablas nuevas. Se extienden dos tablas ya existentes desde las specs 011/012/013.

## `ml_configuracion` (ya existente)

| Campo nuevo | Tipo | Notas |
|---|---|---|
| `lista_precio_id` | unsignedBigInteger, nullable, FK → `listas_precio.id`, `nullOnDelete()` | Lista de Precios que gestiona los precios de las publicaciones vinculadas de Mercado Libre. Opcional: `null` = sin sincronización de precio (comportamiento actual, sin cambios). No tiene reserva "por defecto del CRM" — mismo criterio que `categoria_venta_id` (spec 012), a diferencia de `deposito_id`. Sin validación de "activa" al usarse (research.md R9). |

Sin columnas de "última sincronización" a nivel de configuración (research.md R8) — a diferencia de
`stock_ultima_sync_en`/`stock_ultima_sync_resultado`, no hay corrida programada en segundo plano cuyo
resultado haya que persistir aquí; el estado vive por-vínculo (ver abajo) y la acción manual informa su
resultado en el momento por notificación.

**Relación Eloquent nueva** en `MercadoLibreConfiguracion`: `listaPrecio(): BelongsTo` (mismo patrón que
`deposito()`/`categoriaVenta()` ya presentes en ese modelo).

## `ml_publicacion_producto` (ya existente, extendida por la spec 013 con estado de stock)

| Campo nuevo | Tipo | Notas |
|---|---|---|
| `precio_pendiente` | boolean, default `false` | Análogo exacto de `stock_pendiente` (spec 013), pero para precio. `true` cuando hay un precio vigente en la Lista de Precios configurada que todavía no se confirmó como enviado a Mercado Libre (recién cambiado, o el envío anterior falló, o el vínculo se creó después de un cambio de precio ya ocurrido — research.md R4). |
| `precio_sincronizado_en` | dateTime, nullable | Fecha/hora del último envío de precio exitoso. |
| `precio_error` | string(255), nullable | Motivo concreto del último rechazo de Mercado Libre al intentar actualizar el precio. `null` cuando el último intento fue exitoso o todavía no hubo ninguno. |
| `precio_error_en` | dateTime, nullable | Fecha/hora del último rechazo. |

Migración: `add_precio_fields_to_ml_publicacion_producto_table`, calcada de
`2026_08_03_060001_add_stock_fields_to_ml_publicacion_producto_table.php` (spec 013) pero para los cuatro
campos de arriba, `after('stock_error_en')`.

**Scope nuevo** en `MercadoLibrePublicacionProducto`: `scopePendientesPrecio(Builder $query): Builder`
(`where('precio_pendiente', true)`), análogo a `scopePendientes()` (stock) ya existente.

**Estado derivado para la UI** (igual criterio que `stockEstado()` en
`MercadoLibreVinculacionController::stockEstado()`, calcado para precio en un método `precioEstado()`
análogo):

- `precio_error` no vacío → **"error"**.
- si no, `precio_pendiente` → **"pendiente"**.
- si no → **"sincronizado"**.

## Sin entidades nuevas

- **Envío de precio**: no es una fila persistente propia; es la operación registrada en
  `ml_operaciones_log` (ya existente, spec 011) con `operacion = 'sincronizar_precio'`, `sentido =
  'escritura'`.
- **`Venta`**: sin cambios. Esta spec no toca `venta.lista_precio_id` en ningún escenario de Mercado Libre
  (ver Nota de revisión en spec.md) — el borrador anterior de esta misma spec sí lo hacía; esa parte queda
  descartada.

## Ciclo de vida de `precio_pendiente` / `precio_error*`

```text
[cambio de precio en la Lista de Precios configurada, producto vinculado]
        │
        ▼
  SincronizadorPrecios::enviarUno($vinculo, $precio)
        │
        ▼
  precio_pendiente = true   (primer paso, incondicional — research.md R4)
        │
        ▼
  ¿bloqueado? (función desactivada / sólo lectura / conexión caída)
        │
   ┌────┴────┐
   │ sí       │ no
   ▼          ▼
corta acá:     PUT /items/{id} (ClienteMercadoLibre)
precio_pendiente        │
permanece true,    ┌────┴────┐
precio_error        │ éxito   │ rechazo de Mercado Libre
sin tocar           ▼         ▼
              precio_pendiente = false   precio_pendiente permanece true
              precio_sincronizado_en     precio_error = motivo
                = now()                  precio_error_en = now()
              precio_error = null
              precio_error_en = null
```

Un vínculo con `precio_error` no vacío es, por definición, también `precio_pendiente = true` — nunca se
llega a `precio_error` no vacío con `precio_pendiente = false` (invariante que cubren los tests de
FR-009/FR-010). El corte por bloqueo (kill-switch) **no** setea `precio_error`: es una condición distinta
de un rechazo de Mercado Libre — el vínculo simplemente conserva el estado de error que ya tuviera (o
ninguno), sólo con `precio_pendiente = true` garantizado.
