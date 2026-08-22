# Quickstart: validación end-to-end

**Feature**: 073-monitoreo-punto-reposicion

Cómo comprobar que la feature funciona de verdad. Recordá la lección del proyecto: **la suite verde
no garantiza nada** — los tests corren en SQLite y MySQL es estricto con `ONLY_FULL_GROUP_BY`. Los
escenarios de navegador de acá abajo no son opcionales.

## Prerrequisitos

```bash
php artisan migrate
php artisan db:seed --class=PermisoSeeder
php artisan db:seed --class=RolSeeder      # Admin recibe los permisos nuevos
npm run build                              # o npm run dev
```

Usuario Admin según `CREDENCIALES_ACCESO.txt`. **Si para probar creás o reseteás algún acceso,
anotalo ahí en el mismo cambio.**

## Escenario 0 — Migración del punto de reposición (hacer PRIMERO, y con cuidado)

```bash
php artisan migracion:punto-reposicion                    # dry-run: no escribe nada
```

Verificá en el resumen: cuántos productos tienen valor, cuántos se redondean, cuántos no se pueden
interpretar, y que la verificación de referencias dé 0 en las 7 columnas.

```bash
php artisan migracion:punto-reposicion --aplicar          # escribe la columna, NO borra la lista
```

Comprobá contra la base que un puñado de productos conocidos conserve exactamente el valor que tenía
en la lista. Recién entonces, y **con OK explícito del usuario**:

```bash
php artisan migracion:punto-reposicion --aplicar --eliminar-lista
```

> Correr el borrado sin haber leído el dry-run es el error que esta feature está diseñada para
> evitar. No lo automatices en el deploy.

**Esperado**: la lista "Punto Reposición" desaparece del selector de listas de precios de Clientes,
de la configuración de Mercado Libre y de Tiendanube, y su columna desaparece sola del listado de
Productos y de su export (el listado genera columnas dinámicas por lista activa).

## Escenario 1 — Punto de reposición en el ABM de Productos (US2)

1. Base de Datos → Productos → editar un producto.
2. El campo **Punto de Reposición** está presente. Cargá `5` y guardá.
3. **Esperado**: toast de éxito, sin recarga de página, valor persistido al reabrir el modal.
4. Probá `-1` y una letra → **esperado**: error de validación en el modal, sin recarga, valor previo
   conservado.
5. Editá un **servicio** → **esperado**: el campo no aplica / no genera control de stock.

## Escenario 2 — Publicaciones de Mercado Libre que no actualizan stock (US1, la crítica)

Preparación: dejá una publicación con `stock_error` y `stock_intentos_fallidos > 0`.

1. Entrá a `/monitoreo`. El bloque de publicaciones la lista con item, título, stock actual, último
   publicado, intentos, desde cuándo y el error.
2. Poné en otra publicación un error que contenga `under_review` → **esperado**: se marca como
   moderación de Mercado Libre y **no** ofrece Destrabar.
3. En una bloqueada, ejecutá **Reactivar** → **esperado**: toast de éxito, la fila se actualiza en el
   lugar, **la página no se recarga** (miralo en la pestaña Network: no hay navegación).
4. Limpiá todos los errores y recargá → **esperado**: estado vacío explícito, no una tabla en blanco.

## Escenario 3 — Los dos controles de stock, separados (US3)

> Los únicos depósitos son **Local (5)** y **Full (6)**, y `ml_configuracion.deposito_id` es el
> Local. Lo que separa los dos bloques es **Full**, no un segundo depósito.

1. Producto publicado en ML con punto de reposición `6`. Dejalo con `2` en **Local** y `50` en
   **Full**.
2. **Esperado**: aparece en **A reponer** (hay que reponer el Local) y **no** en riesgo de
   publicación (con 52 vendibles la publicación no corre peligro). **Este es el caso que prueba que
   los dos bloques no son el mismo.**
3. Dejalo con `1` en Local y `0` en Full → **esperado**: aparece en **los dos** bloques.
4. Dejalo con `20` en Local y `0` en Full → **esperado**: en **ninguno**.
5. Poné su punto de reposición en `0` o vacío → **esperado**: desaparece de ambos, aunque su stock
   sea bajo.
6. Un producto **no publicado en ML** con `2` en Local → **esperado**: está en A reponer y nunca en
   riesgo de publicación.
7. Un producto con `0` en Local y `4` en Full → **esperado**: está en A reponer, pero **no** figura
   como "sin stock para vender".

## Escenario 4 — Rendimiento con el catálogo real (SC-005)

Sobre la base con los ~8.400 productos reales:

1. Abrí `/monitoreo` y cronometrá la carga inicial.
2. Paginá y buscá en la tabla A reponer.
3. **Esperado**: tiempos comparables a cualquier otro listado del sistema; la carga inicial **no**
   trae el catálogo entero.
4. En la pestaña Network, mirá `monitoreo/resumen` al navegar entre pantallas cualesquiera del CRM →
   **esperado**: respuesta liviana (conteos + muestra de 5), no un dump.

## Escenario 5 — Editar el punto de reposición desde el panel (US2 / FR-003)

1. En el bloque A reponer, editá el punto de reposición de una fila.
2. **Esperado**: se guarda, la fila se reevalúa en el lugar y —si el producto deja de estar en punto
   de reposición— desaparece de la tabla. Sin recarga.
3. **Esperado**: su notificación asociada también deja de figurar en la campanita.

## Escenario 6 — Barra superior (US4)

1. Con problemas activos, abrí el desplegable del indicador de Monitoreo.
2. **Esperado**: exactamente tres bloques (publicaciones ML, productos en punto de reposición,
   estado de sincronizaciones), cada uno con conteo y muestra. **No** debe aparecer "órdenes sin
   venta".
3. Clic en un caso → **esperado**: llega a `/monitoreo` posicionado en ese bloque.
4. Resolvé todo → **esperado**: "todo en orden" y el indicador sin resaltar.

## Escenario 7 — Notificaciones y episodios (US5, el caso que más se rompe)

1. Bajá un producto por debajo de su punto de reposición → **esperado**: notificación en la
   campanita con producto, stock actual y punto de reposición; el contador sube.
2. Marcala como leída → **esperado**: el contador baja.
3. Con **otro usuario** con permiso → **esperado**: para él sigue contando como no leída.
4. Repone el stock por encima del punto → **esperado**: la notificación desaparece sola, sin que
   nadie la descarte.
5. **El caso clave**: volvé a bajar el stock del mismo producto → **esperado**: la notificación
   reaparece **como NO leída**. Si aparece como leída, la clave de episodio está mal armada y el
   producto quedaría silenciado para siempre.
6. Esperá 5 minutos con la pestaña quieta → **esperado**: el contador se refresca solo, sin recargar.

## Escenario 8 — Permisos (SC-009)

| Usuario | Esperado |
|---|---|
| Sin `monitoreo.ver` | No ve el indicador, ni la campanita, ni el link. `/monitoreo` por URL → rechazado. `monitoreo/resumen` → 403. Ninguna llamada al endpoint en Network |
| Con `monitoreo.ver` y **sin** `monitoreo.gestionar` | Ve todo, pero Destrabar / Reactivar / Sincronizar / editar punto de reposición → rechazados. Los botones no deberían siquiera renderizarse |
| Con `monitoreo.ver` y sin `productos.editar` | **Sí** puede editar el punto de reposición desde el panel si tiene `monitoreo.gestionar` (clarificación 5) |
| Con `monitoreo.ver` | Puede marcar notificaciones como leídas (no requiere gestionar) |

## Escenario 9 — Aislamiento de fallas (FR-024)

Forzá un error en un bloque (por ejemplo, dejando `ml_configuracion.deposito_id` en `null`).

**Esperado**: sólo ese bloque muestra su error; el resto de la pantalla sigue funcionando. Este es
el comportamiento que hoy **no** existe: con la respuesta monolítica actual, una excepción en
cualquier bloque deja la pantalla en blanco.

## Tests automatizados

```bash
php artisan test --filter=Monitoreo
```

Cubren: los 8 casos del contrato de migración, la regla de reposición sobre los dos depósitos, el
acceso por permisos, y el ciclo de vida de las notificaciones (incluido el escenario 7.5).
