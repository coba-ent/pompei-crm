# Implementation Plan: Vinculación automática de Mercado Libre por catálogo en vivo

**Branch**: `023-mercadolibre-catalogo-vivo` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/023-mercadolibre-catalogo-vivo/spec.md`

## Summary

Reemplaza por completo la fuente del SKU que usa `App\Services\MercadoLibre\VinculadorAutomatico` (spec 021):
en vez de leer `ml_orden_items.sku_vendedor` (snapshot de órdenes ya sincronizadas, sólo existe si la
publicación vendió alguna vez), recorre el catálogo en vivo del vendedor conectado — modo `scan` de
`/users/{seller_id}/items/search` (sin el tope de 1000 resultados del paginado por `offset`, necesario
porque el catálogo real tiene miles de publicaciones) + multiget `GET /items?ids=...` (hasta 20 por
llamada) para traer `attributes[SELLER_SKU]`, `status` y `variations[]` de cada una. Mismo criterio de
matching que hoy (`Producto::find((int) $sku)`), mismo formato de resumen, misma ruta HTTP — el cambio es
enteramente interno al servicio.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: `App\Services\MercadoLibre\ClienteMercadoLibre` (ya existente, spec 011 — su método
genérico `obtener()` alcanza, sin agregar ningún método nuevo al cliente) — sin dependencias nuevas.

**Storage**: MySQL. Sin migraciones: `ml_publicacion_producto` se sigue poblando con los mismos campos.
`ml_orden_items.sku_vendedor` deja de leerse para este mecanismo, pero no se toca su esquema ni su
sincronización (spec.md Assumptions).

**Testing**: PHPUnit (Feature test), `Http::fake()` simulando `api.mercadolibre.com/users/*/items/search`
(modo `scan`, con y sin `scroll_id`) y `api.mercadolibre.com/items?ids=*` (multiget), mismo patrón que
`MercadoLibreVinculacionTest`/`MercadoLibreVinculacionAutomaticaTest` ya existentes.

**Target Platform**: mismo hosting compartido que el resto del CRM — sin tareas programadas nuevas, sigue
siendo un botón manual (spec.md Clarifications).

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: catálogo real de miles de publicaciones — cientos de llamadas encadenadas (scan
paginado + multiget de a 20), muy por debajo del rate limit documentado (~1500 req/min por vendedor, spec
021 research.md R1). La corrida puede tardar varios minutos; confirmado como aceptable (spec.md
Clarifications) — sin background ni indicador de progreso independiente.

**Constraints**: el buscador paginado clásico de Mercado Libre (`offset`/`limit`) topea en 1000 resultados
alcanzables — inválido para el volumen real; obliga al modo `scan` (cursor `scroll_id`, verificado en vivo,
ver research.md R1). Si la corrida falla a mitad de camino (tras agotar los reintentos ya existentes de
`ClienteMercadoLibre`), se aborta sin crear ningún vínculo (spec.md Assumptions).

**Scale/Scope**: 1 servicio existente reescrito internamente (`VinculadorAutomatico`, mismo método público
`ejecutar()`) — sin cambios de controlador, ruta, vista ni JS (la UI ya construida en spec 021 sigue igual:
mismo botón, mismo endpoint, mismo formato de resumen).

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | `docs/documentacion_principal_crm.md` §5.2 y `docs/modelo_datos.md` se actualizan antes de `/speckit-tasks` reemplazando la referencia al mecanismo basado en órdenes por el de catálogo en vivo. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 023 (corrección de la 021) escrita, clarificada con 3 preguntas resueltas —incluida una verificación empírica en vivo contra la cuenta real conectada (modo `scan` confirmado funcionando)— antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | Sin impacto: no toca comprobantes, CAE, ni importes. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Mismo impacto que spec 021 (qué producto se descuenta de stock al convertir una orden) — se testea con el mismo rigor: no-sobrescritura, resolución exacta, exclusión de variantes, motivos de fallo, y ahora también el recorrido `scan` completo y el aborto ante fallo a mitad de camino. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Se reescribe `VinculadorAutomatico` en su lugar (mismo namespace, mismo método público) — no se crea una clase paralela ni se duplica lógica. |

Sin contradicciones que resolver.

### Re-evaluación post-Fase 1

✅ Pasa. El diseño de la Fase 1 reutiliza infraestructura 100% existente: `ClienteMercadoLibre::obtener()`
para las dos únicas llamadas necesarias (scan search y multiget, ambas confirmadas en vivo contra la
cuenta real), sin cliente HTTP nuevo, sin tabla nueva, sin ruta nueva. `VinculadorAutomatico` mantiene su
firma pública (`ejecutar(?User $usuario): array`), así que el controlador, la ruta, la vista y el JS de la
spec 021 no requieren ningún cambio.

## Project Structure

### Documentation (this feature)

```text
specs/023-mercadolibre-catalogo-vivo/
├── plan.md              # Este archivo
├── research.md          # Fase 0 — decisiones técnicas (scan mode, multiget)
├── data-model.md        # Fase 1 — sin cambios de esquema; documenta el nuevo flujo de resolución
├── quickstart.md        # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md # Fase 1 — confirma que el contrato HTTP no cambia
├── checklists/
│   └── requirements.md
└── tasks.md              # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/MercadoLibre/
│   ├── VinculadorAutomatico.php    # REESCRITO — deja de leer ml_orden_items, recorre el catálogo en vivo (scan + multiget)
│   └── Excepciones/
│       └── VinculacionAutomaticaFallidaException.php  # NUEVO — catálogo en vivo falló a mitad de la corrida
├── Http/Controllers/Ingresos/
│   └── MercadoLibreVinculacionController.php  # EXTENDER — vincularAutomaticamente() captura VinculacionAutomaticaFallidaException → 502 JSON
tests/Feature/Integraciones/
│   └── MercadoLibreVinculacionAutomaticaTest.php  # REESCRITO — Http::fake() de scan/multiget en vez de fixtures de ml_orden_items
docs/documentacion_principal_crm.md # ACTUALIZAR — §5.2
docs/modelo_datos.md                # ACTUALIZAR — nota de cambio de fuente del SKU
```

**Structure Decision**: corrección quirúrgica sobre la spec 021 ya implementada — el servicio se reescribe
en su lugar, se agrega una excepción de dominio nueva, y el controlador suma un `catch` puntual. Sin
cambios en `routes/web.php`, la vista Blade ni el JS: el contrato externo (botón → endpoint → resumen) es
idéntico al de la spec 021 en el camino exitoso — sólo se agrega el camino de error por catálogo caído.

## Enfoque técnico

`VinculadorAutomatico::ejecutar()`:

1. Excluir publicaciones ya vinculadas: mismo criterio que hoy, `whereNotIn` sobre
   `MercadoLibrePublicacionProducto::pluck('ml_item_id')`.
2. Recorrer el catálogo completo del vendedor conectado con `ClienteMercadoLibre::obtener()` contra
   `/users/{seller_id}/items/search` en modo `scan` (research.md R1): primera llamada sin `scroll_id`,
   llamadas siguientes con el `scroll_id` devuelto por la respuesta **anterior** (no el original — verificado
   que cambia en cada página), hasta que `results` vuelve vacío. `ClienteMercadoLibre::obtener()` nunca
   lanza por fallas del proveedor (siempre devuelve `RespuestaMercadoLibre`) — si `fallo()` es `true` en
   cualquier llamada (tras agotar los reintentos ya existentes del cliente), `VinculadorAutomatico` lanza
   `VinculacionAutomaticaFallidaException` (nueva, `App\Services\MercadoLibre\Excepciones\`) y aborta toda
   la corrida sin crear ningún vínculo (spec.md Assumptions) — los ids ya recolectados en memoria se
   descartan.
3. Filtrar de esa lista los ids ya vinculados (paso 1) antes de pedir el detalle — evita multiget
   innecesario sobre publicaciones que no se van a procesar.
4. Pedir el detalle de los ids restantes con multiget `GET /items?ids=id1,...,id20` (research.md R2), en
   chunks de 20. Por cada entrada: excluir si `status === 'closed'` (spec.md FR-003) y si `variations` no
   está vacío (FR-007); resolver el SKU desde `attributes[]` con `id === 'SELLER_SKU'` → `value_name`
   (research.md R3).
5. Por cada publicación con SKU: mismo flujo de validación y creación que la spec 021 (`sin_sku`,
   `producto_no_encontrado`, `ya_vinculado` con detalle `sku`/`producto`, o creación del vínculo) —
   `Producto::find((int) $sku)` sin excluir inactivos.
6. Mismo formato de resumen (`total`/`vinculadas`/`fallidas`/`detalle_fallidas`) que ya devuelve el
   controlador — sin cambios en `MercadoLibreVinculacionController::vincularAutomaticamente()`.

## Complexity Tracking

*(vacío — sin violaciones que justificar; ver Constitution Check)*
