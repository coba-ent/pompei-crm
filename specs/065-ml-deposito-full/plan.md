# Implementation Plan: Depósito para publicaciones y órdenes Full de Mercado Libre

**Branch**: `065-ml-deposito-full` | **Date**: 2026-08-13 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/065-ml-deposito-full/spec.md`

## Summary

Mercado Libre lleva **dos existencias separadas** por producto —la del domicilio del vendedor y la
del centro de distribución de Mercado Libre (Full)— y la segunda **no es escribible por API**. Hoy el
CRM las trata como una sola: usa un único depósito para imputar las Ventas de órdenes de Mercado
Libre y para calcular el stock que le informa a Mercado Libre, sin distinguir el tipo de logística.

Esta feature separa ambos mundos:

1. Persiste el **tipo de logística** de cada publicación vinculada, aprovechando el multiget que ya
   corre a diario para clasificar el tipo de publicación (spec 050). Sin llamadas nuevas.
2. **Excluye** a las publicaciones Full del envío de stock hacia Mercado Libre, que es lo único que
   la API permite escribir.
3. **Invierte el flujo para Full**: lee la existencia vendible del centro de distribución de Mercado
   Libre y la refleja en un **depósito Full configurable** del CRM, deduplicando por inventario.
4. Imputa las **Ventas de órdenes íntegramente Full** a ese depósito, con fallback al general.
5. Muestra el tipo de logística y un badge **FULL** en la grilla de Vinculaciones, con filtro.

La reposición de mercadería hacia Full la gestiona el negocio manualmente, por decisión explícita y
porque Mercado Libre no la expone por API.

**Enfoque técnico**: extender infraestructura existente en lugar de crear paralela. Tres columnas
nuevas en `ml_publicacion_producto`, una en `ml_configuracion`, un servicio nuevo acotado
(`SincronizadorStockFull`) y modificaciones quirúrgicas en cuatro puntos ya existentes.

> ⚠️ **Deuda preexistente detectada en `analyze`**: `SincronizadorTiposPublicacion::sincronizarUno()`
> —el mecanismo que debería clasificar una publicación al vincularla (FR-003)— **no se invoca desde
> ningún punto del repo**. Es código muerto desde la spec 050, lo que significa que hoy `listing_type_id`
> tampoco se determina al vincular. Esta feature lo cablea (T007a), corrigiendo de paso esa deuda.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, Yajra DataTables (server-side), Select2 y Toastr del template
NexaDash, cliente HTTP propio `ClienteMercadoLibre` (con renovación de token y logging de
operaciones ya resueltos)

**Storage**: MySQL/MariaDB. Tablas afectadas: `ml_publicacion_producto` (**3** columnas nuevas:
`logistic_type`, `inventory_id`, `logistica_sincronizada_en`), `ml_configuracion` (1 columna nueva:
`deposito_full_id`). **4 columnas en total**, una sola migración. Ninguna tabla nueva.

**Testing**: PHPUnit / Pest sobre `tests/Feature/Integraciones/`, siguiendo los tests ya existentes
de la integración (`MercadoLibreSincronizacionForzadaTest`, `MercadoLibreOrdenEjecucionTest`,
`MercadoLibreSincronizarTiposPublicacionTest`). API de Mercado Libre mockeada con `Http::fake()`.

**Target Platform**: Aplicación web Laravel. Producción en VPS propio
(`pompeisanitarioscontable.cloud`), único ambiente con cron activo.

**Project Type**: Aplicación web monolítica (Blade + controladores JSON para AJAX)

**Performance Goals**: Sin impacto medible. El reflejo Full agrega **1 llamada HTTP por inventario
Full distinto por corrida** (hoy: 3). La clasificación de logística agrega **0 llamadas** (viaja en
el multiget existente).

**Constraints**:
- La existencia del centro de distribución de Mercado Libre es de **sólo lectura**; ninguna ruta del
  código puede intentar escribirla.
- El "modo sólo lectura" de la integración **no** debe frenar la lectura ML → CRM (FR-014a).
- Las publicaciones de logística propia deben conservar comportamiento **idéntico** al actual
  (SC-007) — es el criterio de no-regresión más importante.

**Scale/Scope**: 270 vinculaciones, de las cuales 3 son Full. 34 inventarios distintos. Volumen
esperado a futuro: unidades a decenas de publicaciones Full.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | Se leyeron `docs/modelo_datos.md` §`ml_configuracion` / `ml_publicacion_producto` y los precedentes de specs 013/016/035/049/050 antes de especificar. **Pendiente obligatorio antes de `/speckit-tasks`**: actualizar `docs/modelo_datos.md` con las 3 columnas nuevas y `docs/documentacion_principal_crm.md` con la regla de negocio de Full. Anotado como tarea bloqueante. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Cadena completa `specify → clarify → plan → checklist → tasks → analyze` antes de implementar. Nada se codea antes. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ No aplica / Pasa | No toca emisión, CAE, tipo de comprobante ni numeración. La Venta sigue creándose exactamente igual; sólo cambia a qué depósito se imputa. FR-022 garantiza que la conversión de órdenes nunca se traba por esta feature, preservando la resiliencia exigida. |
| **IV. Testing donde hay dinero o impacto fiscal** | ⚠️ Exige rigor | **Esto es movimiento de stock** — categoría explícitamente listada como obligatoria de testear. Todo el reflejo ML → CRM, la imputación de depósito de las Ventas y la exclusión del push llevan tests antes de la implementación. No negociable. |
| **V. Convenciones Laravel + dominio en español** | ⚠️ Con excepción documentada | Columnas nuevas en español (`deposito_full_id`, `logistica_sincronizada_en`) salvo `logistic_type` e `inventory_id`, que conservan el nombre crudo de la API. Ver Complexity Tracking. |

**Resultado del gate**: PASA. Sin violaciones que requieran rediseño.

**Re-evaluación post-diseño (Phase 1)**: PASA sin cambios. El diseño no introduce tablas nuevas,
servicios paralelos ni infraestructura duplicada; extiende `SincronizadorTiposPublicacion` y agrega
un único servicio acotado. La única desviación de nomenclatura ya está justificada abajo.

## Project Structure

### Documentation (this feature)

```text
specs/065-ml-deposito-full/
├── plan.md              # Este archivo
├── research.md          # Phase 0 — 10 decisiones técnicas, verificadas contra la API real
├── data-model.md        # Phase 1 — columnas nuevas y reglas de derivación
├── quickstart.md        # Phase 1 — guía de validación end-to-end
├── contracts/
│   ├── rutas-internas.md    # Endpoints del CRM afectados
│   └── api-mercadolibre.md  # Endpoints de ML consumidos, con respuestas reales
├── checklists/
│   ├── requirements.md      # Calidad de la spec (ya generado)
│   └── stock-full.md        # Checklist funcional (/speckit-checklist)
└── tasks.md             # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Models/Integraciones/
│   ├── MercadoLibrePublicacionProducto.php   # MOD: casts, scopes esFull()/noFull(), etiqueta legible
│   └── MercadoLibreConfiguracion.php         # MOD: relación depositoFull() + depositoFullEfectivoONulo()
├── Services/MercadoLibre/
│   ├── SincronizadorTiposPublicacion.php     # MOD: persiste logistic_type + inventory_id (R8)
│   ├── SincronizadorStock.php                # MOD: excluye Full del push; separa cortes lectura/escritura (R6)
│   ├── SincronizadorStockFull.php            # NUEVO: reflejo ML → CRM del depósito Full
│   └── ConversorOrdenAVenta.php              # MOD: resuelve depósito según logística de las líneas (R5)
├── Http/
│   ├── Controllers/Ingresos/
│   │   └── MercadoLibreVinculacionController.php  # MOD: columna + filtro de logística (R10)
│   └── Requests/Integraciones/
│       └── GuardarConfiguracionVentasMercadoLibreRequest.php  # MOD: deposito_full_id + different (R9)
├── Jobs/
│   └── SincronizacionForzadaMercadoLibre.php # MOD: encadena el reflejo Full tras el push
└── Observers/
    └── MovimientoStockObserver.php           # SIN CAMBIOS — ver R7 (el bucle se cierra por FR-017)

database/migrations/
└── 2026_08_1X_XXXXXX_add_logistica_full_mercadolibre.php  # NUEVO: 3 columnas

resources/
├── views/
│   ├── ingresos/mercadolibre/
│   │   └── vinculaciones.blade.php           # MOD: columna, badge FULL, filtro
│   └── configuracion/mercadolibre/           # ⚠️ la config de ML vive ACÁ, no en ingresos/
│       ├── index.blade.php                   # MOD: selector "Depósito para publicaciones Full" (~L133)
│       └── _tab.blade.php                    # MOD: el MISMO selector, duplicado (~L127) — tocar ambas
└── js/
    └── mercadolibre-vinculaciones.js         # MOD: render del badge + filtro server-side

tests/Feature/Integraciones/
├── MercadoLibreLogisticaFullTest.php         # NUEVO: clasificación + exclusión del push
├── MercadoLibreStockFullTest.php             # NUEVO: reflejo ML → CRM, dedup, sólo lectura
└── MercadoLibreVentaFullDepositoTest.php     # NUEVO: imputación de depósito de la Venta
```

**Structure Decision**: se respeta la estructura MVC existente del proyecto sin introducir capas
nuevas. La feature se apoya en cuatro servicios ya existentes de `app/Services/MercadoLibre/` y suma
uno solo (`SincronizadorStockFull`), acotado a la única responsabilidad que no tiene dueño hoy: leer
existencia de Mercado Libre y reflejarla en un depósito del CRM.

## Enfoque técnico por área

### 1. Clasificación de logística (FR-001..FR-005)

`SincronizadorTiposPublicacion::consultarYPersistir()` ya hace el `GET /items?ids=…` en chunks de 20.
Se le agrega la persistencia de `logistic_type` e `inventory_id` del mismo body, con la misma
política de conservar el último valor conocido ante fallo (`fallo()` → no pisar). Cero llamadas
nuevas, cero cron nuevo, y el backfill inicial sale gratis porque ese servicio ya recorre **todos**
los vínculos.

### 2. Exclusión del push (FR-006..FR-008)

En `SincronizadorStock::procesarVinculos()`, antes de calcular cantidad y enviar: si el vínculo es
Full, se incrementa un contador `omitidos`, se limpia `stock_pendiente` (FR-007) y se hace `continue`.
El resultado suma `omitidos` a los mensajes existentes (FR-008). Las publicaciones no-Full recorren
exactamente el mismo camino que hoy (SC-007).

### 3. Reflejo ML → CRM (FR-009..FR-014a)

`SincronizadorStockFull` nuevo:

1. Corta si no hay depósito Full configurado y activo (FR-014), informando sin abortar.
2. Toma los vínculos Full, agrupa por `inventory_id` distinto (FR-009b).
3. Por cada inventario: `GET /inventories/{id}/stock/fulfillment` → `available_quantity`.
4. Calcula el delta contra `StockService::disponibilidad(producto, null, depositoFull)` y, **sólo si
   difiere** (FR-012), llama a `StockService::ajustar()` con la diferencia con signo, dejando el
   movimiento trazable con origen identificable (FR-010).
5. Usa `verificarCortesLectura()` — sin corte por modo sólo lectura (FR-014a, R6).

Se invoca desde el mismo cron de stock y desde `SincronizacionForzadaMercadoLibre`, después del push.

### 4. Imputación de depósito de la Venta (FR-020..FR-023)

En `ConversorOrdenAVenta`, se reemplaza el `depositoEfectivo()` fijo de la línea 233 por un método que
resuelve: si **todas** las líneas de la orden mapean a vínculos Full y hay depósito Full activo →
depósito Full; en cualquier otro caso → `depositoEfectivo()` (FR-021/FR-022). El mismo depósito debe
usarse en el descuento de stock (`StockDeVenta::aplicarAlta`), que ya lee de la Venta. El criterio
aplicado se registra para auditoría (FR-023) reutilizando `MercadoLibreOperacionLog`.

### 5. UI (FR-015..FR-019, FR-024..FR-026)

Selector Select2 en la configuración con validación `different:deposito_id` (R9), guardado por AJAX
con Toastr y sin recarga (regla obligatoria del proyecto). En Vinculaciones, columna server-side de
logística con badge destacado para Full y filtro por tipo (R10). Aviso en configuración cuando hay
publicaciones Full sin depósito configurado (FR-026).

## Complexity Tracking

| Violación | Por qué es necesaria | Alternativa más simple rechazada porque |
|---|---|---|
| Columnas `logistic_type` e `inventory_id` en inglés, contra el principio V (dominio en español) | Son identificadores crudos de la API de Mercado Libre, con valores crudos (`fulfillment`, `xd_drop_off`). Traducirlos obligaría a un mapeo bidireccional en cada lectura del multiget, con riesgo de perder valores nuevos que Mercado Libre agregue. | Ya existe el mismo precedente aceptado en esta tabla: `listing_type_id` (spec 050) guarda `gold_pro` crudo. Ser consistente con el precedente vale más que la pureza del idioma. Toda la traducción a español ocurre en la capa de presentación (R10). |
| Un servicio nuevo (`SincronizadorStockFull`) en vez de reutilizar `SincronizadorStock` | Sentido de datos opuesto (lee de Mercado Libre y escribe en el CRM, no al revés), cortes previos distintos (sin modo sólo lectura, R6), fuente de datos distinta (`/inventories/…` en vez de `PUT /items/…`) y criterio de recorrido distinto (todos los Full, no los pendientes, FR-009a). | Meterlo dentro de `SincronizadorStock` obligaría a ramificar el método en dos flujos sin nada en común salvo el nombre, y a debilitar `verificarCortes()` para todos los casos —incluido el push, donde el modo sólo lectura **sí** debe cortar. Sería un riesgo de regresión sobre las 260 publicaciones que hoy funcionan bien. |
