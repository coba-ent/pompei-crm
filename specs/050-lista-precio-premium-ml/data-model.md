# Data Model: Lista de Precios diferenciada para publicaciones Premium de Mercado Libre

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md)

Extiende `ml_publicacion_producto` y `ml_configuracion` (specs 012/013/016). Sin tablas nuevas.

## `ml_publicacion_producto` (columnas nuevas)

| Campo | Tipo | Notas |
|---|---|---|
| `listing_type_id` | string(30), nullable | Valor crudo informado por Mercado Libre (`gold_pro`, `gold_special`, etc. — FR-002). `null` hasta la primera consulta exitosa (publicación recién vinculada antes de que corra el comando, o falla transitoria de ML). |
| `listing_type_sincronizado_en` | datetime, nullable | Último refresco exitoso de `listing_type_id` (R3). Permite diagnosticar publicaciones que quedaron desactualizadas por errores repetidos, mismo espíritu que `stock_sincronizado_en`. |

Mismo patrón que las columnas de estado ya existentes (`stock_pendiente`/`stock_sincronizado_en`,
`precio_pendiente`/`precio_sincronizado_en`): columnas simples sobre el modelo, sin tabla de historial
aparte (eso ya lo cubre `ml_operaciones_log`).

**Comportamiento ante fallo de la API (Edge Case de la spec)**: si la consulta a ML falla, ambas columnas
conservan el último valor conocido — no se pisan con `null` ni con un valor por defecto.

**Método nuevo en el modelo** `MercadoLibrePublicacionProducto`:

```php
public function esPremium(): bool
{
    return $this->listing_type_id === 'gold_pro';
}
```

Único lugar de la app que traduce "tipo crudo de ML" → "Premium sí/no" (research.md §R2) — cualquier
lugar que necesite esa distinción (hoy sólo `SincronizadorPrecios`) llama a este método, no compara el
string directamente.

## `ml_configuracion` (columnas nuevas)

| Campo | Tipo | Notas |
|---|---|---|
| `lista_precio_id_premium` | FK → `listas_precio`, nullable | Lista de Precios para publicaciones Premium (FR-001). Coexiste con `lista_precio_id` ya existente (la lista general). `null` = sin diferenciar, comportamiento actual (FR-008). |
| `tipo_publicacion_ultima_sync_en` | datetime, nullable | Última corrida del comando de actualización de tipos (R3), comparada contra un intervalo fijo de 24 horas en el propio comando — mismo rol que `stock_ultima_sync_en`, pero sin campo de frecuencia configurable en pantalla (la Clarification fijó "diaria" como valor de negocio, no de configuración de usuario). |

Relación nueva en el modelo `MercadoLibreConfiguracion`:

```php
public function listaPrecioPremium(): BelongsTo
{
    return $this->belongsTo(\App\Models\ListaPrecio::class, 'lista_precio_id_premium');
}
```

## Migración

Una única migración agrega las 4 columnas de arriba (2 por tabla) — mismo criterio que las migraciones
de columnas de spec 013/016 (`add_*_to_*_table`), sin migración de datos (el backfill es responsabilidad
del comando, research.md §R4, no de la migración — ver R4 para el porqué).

## Sin cambios en `ml_ordenes` / `ml_orden_items`

Fuera de alcance: el precio de las líneas de Venta creadas al convertir una orden sigue derivándose
exclusivamente del importe pagado en Mercado Libre (regla ya documentada en
`documentacion_principal_crm.md` §3.2.bis) — esta feature no toca esa ruta, sólo la sincronización
CRM → Mercado Libre de precios "de catálogo".
