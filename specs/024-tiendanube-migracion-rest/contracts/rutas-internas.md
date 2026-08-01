# Contrato — Rutas internas del CRM afectadas

**Feature**: `024-tiendanube-migracion-rest`

## 1. Vinculación (`ingresos/tiendanube/vinculaciones/*`)

| Ruta | Antes | Después |
|---|---|---|
| `GET vinculaciones/pendientes` | `TiendanubeVinculacionController::variantesPendientes()` (fuente: `tn_orden_items`) | **Retirada** — reemplazada por el botón "Vincular automáticamente" |
| `POST vinculaciones` (`store`) | Alta manual desde el selector | **Retirada** |
| `POST vinculaciones/importar` | `ImportadorVinculaciones` (Excel) | **Retirada** |
| `POST vinculaciones/vincular-automaticamente` | — | **Nueva** — `TiendanubeVinculacionController::vincularAutomaticamente()`, mismo contrato de respuesta que `MercadoLibreVinculacionController::vincularAutomaticamente()` (spec 023): `{ok, mensaje, total, vinculadas, fallidas, detalle_fallidas}`, 200 en éxito, 502 si `VinculacionAutomaticaFallidaException`. |
| `PATCH vinculaciones/{vinculacion}` | Sin cambios | Sin cambios |
| `DELETE vinculaciones/{vinculacion}` | Sin cambios | Sin cambios |

## 2. Órdenes y stock (`ingresos/tiendanube/*`)

Sin cambios de contrato externo: `sincronizar`, `sincronizar-stock`, listado, detalle, conversión — mismas
rutas, mismos formatos de request/response. Cambia únicamente qué cliente HTTP usan por dentro.

## 3. Configuración → Tiendanube (retirado en Historia 3)

| Ruta | Antes | Después |
|---|---|---|
| `GET tiendanube` (index) | Muestra apartado MCP + apartado REST | Muestra sólo apartado REST |
| `GET tiendanube/estado` | Estado MCP | **Retirada** |
| `POST tiendanube/desconectar` | Desconecta MCP | **Retirada** |
| `POST tiendanube/modo-solo-lectura` | Sobre `tn_configuracion` | Redirige a editar el campo migrado en `tn_conexion_rest` (mismo endpoint, misma vista, distinto backing) |
| `POST tiendanube/ventas` | Configura `tn_configuracion` (depósito, categoría, cuenta, lista de precios, vendedor) | Configura los mismos campos, ahora en `tn_conexion_rest` |
| `GET tiendanube/historial` | `tn_operaciones_log` | Redirige a `tn_rest_operaciones_log` (ya usado por spec 022) |
| `GET tiendanube/conectar`, `GET tiendanube/callback` | `TiendanubeOAuthController` (MCP) | **Retiradas** |
| `GET tiendanube/conectar-rest`, `GET tiendanube/callback-rest`, `GET tiendanube/estado-rest`, `POST tiendanube/desconectar-rest` | Sin cambios (spec 022) | Sin cambios |

**Nota**: el detalle fino de qué rutas se renombran vs. se retiran directamente (ej. si `modo-solo-lectura`
y `ventas` pasan a vivir bajo el prefijo `-rest` o mantienen su nombre actual apuntando al nuevo backing) se
resuelve en `/speckit-tasks`, siempre preservando el criterio de esta tabla: la funcionalidad de
configuración de negocio sigue disponible, sólo cambia dónde se persiste.
