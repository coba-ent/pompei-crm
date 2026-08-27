# Implementation Plan: Corte de seguridad para las bajadas de precio hacia Mercado Libre

**Branch**: `084-corte-bajada-precios-ml` | **Date**: 2026-08-26 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/084-corte-bajada-precios-ml/spec.md`

## Summary

Interponer una evaluación obligatoria entre "el CRM decidió un precio" y "el precio sale hacia
Mercado Libre". Hoy no hay nada en el medio: `SincronizadorPrecios::enviarUno()` recibe un importe y
lo manda. La pieza nueva se mete **exactamente ahí**, en el único punto por el que ya pasan los tres
caminos de envío, de modo que ninguno pueda saltearla.

Alrededor de ese corte, tres refuerzos: confirmación con números antes de republicar una lista
entera, un chequeo diario CRM ↔ API visible en `/monitoreo`, y el cierre de las dos ventanas por las
que una publicación Premium puede cotizar barata en silencio.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent; `ClienteMercadoLibre` (HTTP + reintentos + log de operaciones);
`SincronizadorPrecios` (spec 016 + 050); `PrecioProductoObserver` (spec 016/018/074); NexaDash
(Bootstrap 5) + DataTables + Select2 + Toastr en el frontend

**Storage**: MariaDB. Tablas existentes a extender: `ml_publicacion_producto`, `ml_configuracion`.
Tabla nueva: una sola, para el historial de retenciones.

**Testing**: PHPUnit (`tests/Feature/Integraciones/`), con `Http::fake()` como en
`SincronizadorPreciosTest`. **Obligatorio** por el principio IV de la constitución: esto es lógica
de dinero.

**Target Platform**: VPS Linux (producción en uso real)

**Project Type**: Aplicación web Laravel monolítica

**Performance Goals**: el corte no puede agregar una llamada HTTP por publicación en el camino de
envío — una importación masiva mueve miles de precios. Ver Decisión 1.

**Constraints**: producción está en uso real; el rollout no puede frenar la sincronización normal ni
retener en masa el primer día. Ver Decisión 5.

**Scale/Scope**: 270 vínculos vigentes (240 Clásicas, 30 Premium), ~9.000 productos, 11 listas de
precios.

## Constitution Check

*GATE: revisado antes de Phase 0 y después de Phase 1.*

| Principio | Cómo lo cumple |
|-----------|----------------|
| **I — Docs como fuente de verdad** | `documentacion_principal_crm.md` ya tiene §3.2.ter.quinquies y §3.2.ter.sexies escritas con los dos incidentes. Falta agregar el corte y el umbral configurable, y `modelo_datos.md` con la tabla nueva y las columnas. **Se hace antes de `/speckit-tasks`.** |
| **II — Spec-driven** | Esta es la spec. La implementación no arranca sin ella. |
| **III — Corrección fiscal (ARCA)** | No aplica: no toca comprobantes ni CAE. |
| **IV — Testing donde hay dinero** | **Aplica de lleno.** Cada regla del corte lleva test. Además, todo test nuevo debe fallar contra el código actual, como se hizo con `PrecioProductoObserverPremiumTest`. |
| **V — Laravel + español** | Nombres del dominio en español (`retenciones_precio_ml`, `precio_publicado`, `umbral_caida_precio_pct`), Observers/Services para la lógica, migraciones versionadas. |

**Sin desvíos que justificar.** No se agrega ninguna capa ni patrón que el proyecto no use ya.

## Project Structure

### Documentation (this feature)

```text
specs/084-corte-bajada-precios-ml/
├── spec.md
├── plan.md              # este archivo
├── research.md          # decisiones técnicas y por qué
├── data-model.md        # tabla nueva y columnas nuevas
├── quickstart.md        # cómo verificarlo a mano
├── contracts/
│   └── retenciones-api.md
├── checklists/
│   └── requirements.md
└── tasks.md             # lo genera /speckit-tasks
```

### Source Code

```text
app/
├── Console/Commands/
│   └── ChequearPreciosMercadoLibre.php        # nuevo — US3, corrida diaria y a demanda
├── Http/
│   ├── Controllers/Integraciones/
│   │   ├── MercadoLibreConfiguracionController.php   # modificado — US2, previa antes de aplicar
│   │   └── MercadoLibreRetencionPrecioController.php # nuevo — listar, aprobar, rechazar
│   ├── Controllers/Monitoreo/
│   │   └── MonitoreoController.php            # modificado — US3, panel de precios
│   └── Requests/Integraciones/
│       └── ResolverRetencionPrecioRequest.php # nuevo
├── Models/Integraciones/
│   ├── MercadoLibreConfiguracion.php          # modificado — umbral
│   ├── MercadoLibrePublicacionProducto.php    # modificado — precio publicado, scope retenidas
│   └── MercadoLibreRetencionPrecio.php        # nuevo
├── Observers/
│   └── PrecioProductoObserver.php             # modificado — US4, no publicar sin tipo conocido
└── Services/MercadoLibre/
    ├── EvaluadorCambioPrecio.php              # NUEVO — el corte. Pieza central
    ├── ChequeoPreciosPublicados.php           # nuevo — US3
    ├── PrevisualizadorCambioLista.php         # nuevo — US2
    └── SincronizadorPrecios.php               # modificado — pasa por el evaluador

database/migrations/
├── ..._agrega_umbral_caida_precio_a_ml_configuracion.php
├── ..._agrega_precio_publicado_a_ml_publicacion_producto.php
└── ..._crea_retenciones_precio_ml.php

resources/
├── js/
│   ├── mercadolibre.js                        # modificado — US2 confirmación
│   ├── mercadolibre-vinculaciones.js          # modificado — US1 retenidas
│   └── monitoreo.js                           # modificado — US3 panel
└── views/
    ├── integraciones/mercadolibre/            # modales de confirmación y de retención
    └── monitoreo/                             # panel de precios

tests/Feature/Integraciones/
├── EvaluadorCambioPrecioTest.php              # nuevo — el corazón del corte
├── RetencionPrecioFlujoTest.php               # nuevo — aprobar / rechazar / reemplazar
├── CambioListaConfirmacionTest.php            # nuevo — US2
├── ChequeoPreciosPublicadosTest.php           # nuevo — US3
└── PrecioProductoObserverPremiumTest.php      # existente — se le agregan casos de US4
```

**Estructura elegida**: la del proyecto, sin variantes. Todo cae en carpetas que ya existen.

## Phase 0 — Investigación y decisiones

Detalle completo en [research.md](./research.md). Resumen de las decisiones que gobiernan el diseño:

1. **De dónde sale "el precio publicado"** → columna `precio_publicado` en el vínculo, escrita en
   cada envío exitoso y refrescada por el chequeo diario. **No** se consulta la API en el camino de
   envío.
2. **Dónde se intercepta** → dentro de `SincronizadorPrecios::enviarUno()`, antes del PUT. Es el
   embudo por el que ya pasan los tres caminos.
3. **Cómo se modela la retención** → tabla propia con historial, más un puntero a la retención
   abierta en el vínculo.
4. **Relación con `precio_pendiente`** → una retención **no** es un pendiente. Se distinguen.
5. **Rollout sin retener 270 publicaciones el primer día** → backfill de `precio_publicado` desde la
   API antes de activar el corte.

## Phase 1 — Diseño

- [data-model.md](./data-model.md) — una tabla nueva, dos columnas nuevas en tablas existentes.
- [contracts/retenciones-api.md](./contracts/retenciones-api.md) — endpoints de listado, aprobación,
  rechazo y previa de cambio de lista.
- [quickstart.md](./quickstart.md) — cómo reproducir los dos incidentes contra el sistema nuevo y
  verificar que quedan retenidos.

### Constitution Check (post-diseño)

Sin cambios: el diseño no introduce patrones nuevos ni desvíos. La única tabla nueva está
justificada por el requisito de historial (FR-015, FR-031).

## Riesgos y cómo se mitigan

| Riesgo | Mitigación |
|--------|-----------|
| El corte retiene en masa al activarse, porque `precio_publicado` está vacío | Backfill previo desde la API (Decisión 5). El corte se activa recién con los 270 poblados |
| El corte frena una bajada legítima y nadie la aprueba: el precio queda viejo | Las retenidas se ven en Vinculaciones **y** en el monitoreo, con conteo visible |
| `precio_publicado` queda desactualizado si alguien cambia el precio en Mercado Libre | El chequeo diario lo refresca. Y si difiere, la comparación se hace contra la realidad, que es lo correcto |
| Sumar una condición en `enviarUno()` rompe los cortes existentes (sólo lectura, función desactivada) | El evaluador corre **después** de `verificarCortes()`, no lo reemplaza. Los tests existentes de `SincronizadorPreciosTest` tienen que seguir en verde sin tocarlos |
| El chequeo diario consume cuota de API | 270 llamadas por día, una por publicación, en horario de baja actividad. Medido: la corrida completa tarda ~1 minuto |

## Complexity Tracking

Sin desvíos de la constitución que requieran justificación.
