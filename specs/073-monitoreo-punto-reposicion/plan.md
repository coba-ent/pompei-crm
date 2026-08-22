# Implementation Plan: Monitoreo, Punto de Reposición y Notificaciones

**Branch**: `073-monitoreo-punto-reposicion` | **Date**: 2026-08-21 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/073-monitoreo-punto-reposicion/spec.md`

## Summary

Se toma la pantalla de monitoreo que hoy es una URL secreta autocontenida y se la convierte en una
pantalla del producto: permiso propio, link e indicador en la barra superior, y las seis reglas de
diseño obligatorias del proyecto (DataTables server-side, modales AJAX, Toastr, Select2, fechas
`data-fecha-ar`, PDFs en el modal compartido — estos dos últimos no aplican acá porque la pantalla
no tiene ni filtros por fecha ni impresos).

El cambio de fondo es el **punto de reposición**: deja de ser una lista de precios disfrazada (donde
lo dejó la importación de datos reales) y pasa a ser una columna de `productos`, editable desde la
ficha del producto y desde el propio panel. Ese número reemplaza el umbral `3` escrito a mano en el
controlador y alimenta **dos controles distintos** con un mismo dato: **A reponer** (stock en Local,
todo el catálogo → "¿le compro al proveedor?") y **Riesgo de publicación** (stock Local + Full, sólo
publicados en Mercado Libre → "¿se me cae la publicación?"). Ojo: `ml_configuracion.deposito_id`
**es** el Local, así que el segundo control **no** puede definirse "contra el depósito de ML" —
daría la misma lista. Lo que los distingue es Full.

Sobre eso se monta la capa proactiva: la campanita del template —hoy oculta y con datos de demo— se
activa con notificaciones calculadas sobre el estado vigente, sin tabla de historial, persistiendo
únicamente el "leído" por usuario con una clave que identifica el **episodio** del problema para que
una alerta resuelta y vuelta a aparecer no quede silenciada para siempre.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent, `yajra/laravel-datatables` (ya en uso en Informes, Auditoría,
Productos), Bootstrap 5 (template NexaDash), jQuery, Toastr, Select2, Vite

**Storage**: MySQL. Una columna nueva (`productos.punto_reposicion`), una tabla nueva
(`notificaciones_leidas`), dos permisos nuevos, y una fila de `listas_precio` eliminada tras migrar
sus datos

**Testing**: PHPUnit (Feature). Foco obligatorio por constitución §IV en la migración de datos
(toca datos reales del negocio) y en la regla de reposición (movimientos de stock)

**Target Platform**: aplicación web, single-tenant, servida desde el VPS del negocio

**Project Type**: aplicación web Laravel monolítica (Blade + endpoints JSON)

**Performance Goals**: el endpoint `monitoreo/resumen` se llama desde **todas** las pantallas del
sistema y cada 5 minutos con la pestaña abierta → sólo conteos indexados y muestra de 5 por bloque.
Las tablas del panel resuelven server-side sobre un catálogo de ~8.400 productos con los tiempos de
cualquier otro listado del sistema (SC-005)

**Constraints**: la migración de datos no puede perder ni duplicar el punto de reposición ya cargado
del archivo real del negocio; el borrado de la lista de precios no puede dejar referencias rotas en
precios de venta reales

**Scale/Scope**: ~8.400 productos, ~1.340 publicaciones de Mercado Libre, 3 depósitos. Una pantalla
rediseñada, dos widgets de barra superior, un campo nuevo en el ABM de Productos, un comando de
migración

## Constitution Check

*GATE: pasa antes de Phase 0 y se re-evalúa después de Phase 1.*

| Principio | Estado | Cómo se cumple |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ | La spec parte de la brecha ya anotada en `documentacion_principal_crm.md` §2 ("Punto de Reposición… queda pendiente de un spec futuro de Productos que agregue el campo y su regla de negocio de alerta de stock bajo") y de la bitácora de importación. **Antes de `/speckit-tasks`** hay que actualizar `documentacion_principal_crm.md` (cerrar esa brecha, documentar la pantalla de Monitoreo que hoy no figura por haber nacido interna) y `modelo_datos.md` (columna nueva, tabla nueva, baja de la lista de precios) — FR-038 |
| **II. Desarrollo spec-driven** | ✅ | Esta es la spec. El panel actual es exactamente el caso de "código sin spec asociada es deuda a regularizar" que el principio contempla: se regulariza acá |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ N/A | No toca comprobantes, CAE, numeración ni condición de IVA. **Sí toca un borde fiscal indirecto**: eliminar una lista de precios podría afectar precios de venta y comprobantes históricos — por eso FR-007 exige verificar `ventas.lista_precio_id` y `presupuestos.lista_precio_id` antes de borrar, y el comando aborta sin modo forzado |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ | Tests obligatorios en: el comando de migración (8 casos fijados en `contracts/migracion-punto-reposicion.md`), la regla de punto de reposición sobre `movimientos_stock`/`stocks`, y el ciclo de vida de las notificaciones (que una alerta resuelta y vuelta a aparecer cuente como no leída) |
| **V. Convenciones Laravel + dominio en español** | ✅ | `productos.punto_reposicion`, `notificaciones_leidas`, `monitoreo.*`, todo en español y snake_case. Middleware `permiso:` y patrón `modulo.accion` existentes, con precedente exacto en `integraciones.ver`/`integraciones.gestionar`. Sin `empresa_id` (single-tenant) |

**Tensión detectada y resuelta** — el comentario de cabecera del controlador actual declara un
principio de aislamiento ("no usa servicios, observers ni vistas del resto de la app… es el precio
de duplicar algo de lógica, pagado a propósito") que choca de frente con la regla de diseño
obligatoria de `CLAUDE.md`. Se resuelve **conservando el aislamiento de lógica** (consultas
directas, sin servicios compartidos) y **abandonando el aislamiento visual** (pasa a
`layouts.default`). Ver `research.md` Decisión 1. El rediseño además *mejora* el aislamiento real
que la spec pide en FR-024: hoy una excepción en cualquier bloque deja la pantalla en blanco, porque
todo viaja en una sola respuesta.

**Sin violaciones que justificar** → no se completa Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/073-monitoreo-punto-reposicion/
├── plan.md                                   # Este archivo
├── spec.md
├── research.md                               # 7 decisiones técnicas
├── data-model.md                             # Columna, tabla, permisos, bajas
├── quickstart.md                             # Validación end-to-end
├── contracts/
│   ├── monitoreo-api.md                      # Endpoints del panel y de la barra superior
│   └── migracion-punto-reposicion.md         # Comando de migración de datos
├── checklists/
│   └── requirements.md
└── tasks.md                                  # (lo genera /speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
│   └── MigrarPuntoReposicion.php             # NUEVO — migracion:punto-reposicion
├── Http/Controllers/
│   ├── Monitoreo/
│   │   ├── MonitoreoController.php           # REESCRITO — endpoints por bloque
│   │   └── MonitoreoResumenController.php    # NUEVO — barra superior y notificaciones
│   └── ProductoController.php                # + punto_reposicion en validación y guardado
├── Models/
│   ├── Producto.php                          # + punto_reposicion en fillable/casts
│   └── NotificacionLeida.php                 # NUEVO
└── Support/Monitoreo/
    └── Alertas.php                           # NUEVO — arma el conjunto de alertas vigentes
                                              #   y sus claves de episodio (lo comparten
                                              #   panel y barra superior)

database/
├── migrations/
│   ├── ..._agregar_punto_reposicion_a_productos.php        # NUEVO
│   └── ..._crear_notificaciones_leidas.php                 # NUEVO
└── seeders/
    └── PermisoSeeder.php                     # + módulo monitoreo (ver, gestionar)

resources/
├── js/
│   ├── monitoreo.js                          # NUEVO — la pantalla
│   └── monitoreo-topbar.js                   # NUEVO — indicador + campanita
└── views/
    ├── monitoreo/
    │   ├── index.blade.php                   # REESCRITO — extiende layouts.default
    │   └── _modal_punto_reposicion.blade.php # NUEVO
    ├── elements/
    │   └── header.blade.php                  # campanita activada + indicador de Monitoreo
    ├── layouts/default.blade.php             # carga monitoreo-topbar.js (con @can)
    └── productos/_modal_form.blade.php       # + campo Punto de Reposición

config/dz.php                                 # + pagelevel 'monitoreo'
vite.config.js                                # + los dos JS nuevos
routes/web.php                                # grupo monitoreo con permiso: y rutas nuevas

tests/Feature/Monitoreo/
├── MigracionPuntoReposicionTest.php          # NUEVO — 8 casos del contrato
├── PuntoReposicionTest.php                   # NUEVO — regla de negocio, dos depósitos
├── MonitoreoAccesoTest.php                   # NUEVO — permisos ver/gestionar
└── NotificacionesTest.php                    # NUEVO — leído/no leído y episodios
```

**Structure Decision**: monolito Laravel existente; no se introduce ninguna estructura nueva. Lo
único que se agrega fuera de los directorios habituales es `app/Support/Monitoreo/Alertas.php`,
donde vive el cálculo de alertas vigentes y sus claves de episodio — compartido entre el panel y la
barra superior porque duplicarlo garantizaría que las claves se desincronicen y el "leído" deje de
funcionar. Sigue sin depender de servicios del resto de la app (consultas directas), que es la parte
del aislamiento que se conserva.

## Orden de implementación sugerido

Las historias de la spec son independientes, pero la migración de datos manda sobre el resto:

1. **Fundaciones** — columna, modelo, permisos, tabla de lecturas, rutas y pagelevel.
2. **Migración de datos** (P2) — comando con dry-run, tests, y recién después correrlo. **La
   eliminación de la lista de precios se ejecuta con OK explícito del usuario**, no como parte
   automática del deploy: toca datos reales y hay una memoria del proyecto sobre exactamente este
   tipo de comando.
3. **Punto de reposición en el ABM de Productos** (P2) — campo en el modal, validación, guardado.
4. **Panel rediseñado** (P1 + P3) — empezando por el bloque de publicaciones ML, que es el que el
   negocio marcó como imprescindible y el único que ya se usa a diario.
5. **Barra superior** (P4) — indicador y desplegable.
6. **Notificaciones** (P5) — campanita, leído/no leído, episodios.
7. **Documentación** — `documentacion_principal_crm.md` y `modelo_datos.md` (FR-038). Va **antes**
   de `/speckit-tasks` según constitución §I, y se ratifica al cerrar.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Perder el punto de reposición real ya cargado | Dry-run por defecto, resumen verificable, transacción, idempotencia, y no pisar valores cargados a mano (`contracts/migracion-punto-reposicion.md`) |
| Borrar una lista de precios que algo referencia | Verificación previa obligatoria contra 7 columnas, sin modo forzado (FR-007) |
| Que el endpoint de la barra superior pese en todas las pantallas | Sólo conteos + muestra de 5; una sola llamada para los dos widgets; nada si el usuario no tiene el permiso |
| Notificación silenciada para siempre | La marca de lectura se borra al resolverse el problema (research Decisión 3); test dedicado |
| Ruido: re-alertar en cada venta de un producto de alta rotación | La clave **no** lleva timestamp de episodio; test dedicado en T040 |
| Los dos bloques de stock resultan ser la misma lista | Verificado contra la base: `ml_configuracion.deposito_id = 5 = Local`. El segundo bloque suma **Full** y se acota a publicados en ML |
| Ventana de regresión en el MVP: el panel pierde bloques que hoy tiene | T023b — portar pulso/órdenes/ventas/sin stock antes de desplegar la US1 |
| Alertas masivas el primer día | Los productos sin punto de reposición no generan alertas; no se inventa un default para el catálogo |
| Perder capacidades del panel actual en el rediseño | La spec lista explícitamente lo que se conserva (órdenes sin venta, últimas ventas, sin stock, interruptores); el contrato tiene un endpoint por cada una |
