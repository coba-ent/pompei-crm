# Implementation Plan: Baja a Clásica sin depender de ML y cambios de tipo detectados desde Mercado Libre

**Branch**: `116-ml-tipo-detectado-y-baja-clasica` | **Date**: 2026-10-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/116-ml-tipo-detectado-y-baja-clasica/spec.md`

## Summary

Cuatro cambios sobre la integración de tipos de publicación (specs 050 y 115):

1. **Bug de la baja a Clásica**: `CambiadorTipoPublicacion::previsualizar()` deja de consultar
   `available_downgrades` (vacío para toda la cuenta, research R1). El rechazo real de ML ya se
   informa en `cambiarTipo()`. La subida sigue validando `available_upgrades`.
2. **Cambio de tipo detectado en ML**: un servicio nuevo `ProcesadorCambioTipoDetectado` recibe un
   vínculo y su tipo vigente en ML, consolida el tipo y publica el precio de la lista del tipo nuevo
   con `SincronizadorPrecios::enviarUno(omitirCorte: true)` (tercera excepción al corte), y registra
   el evento en `ml_cambios_tipo_publicacion` con `origen = mercadolibre`. Lo usan la revisión de tipos
   y el camino "sin cambios" de la confirmación de la spec 115.
3. **Frecuencia**: la revisión de tipos (`SincronizadorTiposPublicacion`) se dispara desde el comando de
   stock de ML, que corre cada minuto, con un freno propio de `frecuencia_sync_minutos` medido desde
   `tipo_publicacion_ultima_sync_en`. Sale de la programación el schedule propio del comando de tipos,
   que queda para uso manual.
4. **Aviso de cuotas**: la previsualización a Clásica devuelve `aviso_pierde_cuotas` con la etiqueta de
   las cuotas sin interés vigentes. El modal individual y el de lote lo muestran.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12; JS ES2015+ (jQuery) compilado con Vite

**Primary Dependencies**: cliente propio `ClienteMercadoLibre`; `SincronizadorPrecios` (único PUT de precio, spec 084); `CampaniasCuotas` (spec 115)

**Storage**: MySQL. Columna nueva `origen` en `ml_cambios_tipo_publicacion`. Reusa `ml_publicacion_producto`, `ml_configuracion.tipo_publicacion_ultima_sync_en` y `frecuencia_sync_minutos`, `precios_producto` y `retenciones_precio_ml`.

**Testing**: PHPUnit Feature tests con `Http::fake()` (SQLite en memoria) + validación manual en navegador local con respuestas simuladas

**Target Platform**: VPS Linux (producción), XAMPP local (desarrollo)

**Project Type**: Web app monolítica Laravel + Blade

**Performance Goals**: la revisión de tipos de ~280 vínculos son ~14 GET multiget (tandas de 20) por período de frecuencia, más un PUT de precio por cada cambio detectado; corre dentro del minuto del cron

**Constraints**: un solo punto de PUT de precio; la excepción al corte acotada a un cambio de tipo verificado contra ML; no correr en paralelo con otra sincronización de precios (candado `ml:sincronizar_precios`); nunca consolidar un tipo nuevo sin haber podido intentar su precio (FR-008); no probar contra la cuenta real del cliente

**Scale/Scope**: 1 servicio nuevo, 3 servicios/comandos modificados, 1 migración, 1 modal (individual + lote), schedule

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Cumple | Nota |
|---|---|---|
| I. Docs de dominio como fuente de verdad | ✅ | Leídos §3.2.ter.quinquies, §3.2.ter.septies (corte 084 y "segunda excepción humana"), §3.2.ter.octies (spec 115) y `modelo_datos.md` (`ml_cambios_tipo_publicacion`, `ml_configuracion`). Se actualizan antes de `tasks`: §3.2.ter.octies (baja sin `available_downgrades`, detección, aviso de cuotas), nota del corte (tercera excepción), clasificación de tipos (ya no diaria) y la columna `origen`. |
| II. Spec-driven | ✅ | Cadena completa specify→analyze. |
| III. Corrección fiscal | N/A | No toca comprobantes ni ARCA. |
| IV. Testing donde hay dinero | ✅ | Toca precios publicados: tests obligatorios de detección, excepción del corte, FR-008 (no consolidar si no se puede enviar), rechazo de precio, camino "sin cambios" y frecuencia. |
| V. Convenciones Laravel + español | ✅ | Servicio/migración en español, mismo patrón que specs 050/084/115. |

Reglas de diseño de `CLAUDE.md`: el aviso de cuotas vive en el modal Bootstrap existente, cargado por AJAX; no hay tablas ni selects nuevos.

**Resultado**: PASA. La tercera excepción a `omitirCorte` contradice la regla vigente de §3.2.ter.septies
("ningún camino automático puede hacerlo"). Es una **decisión explícita del usuario** (Clarifications) y
se resuelve actualizando el doc (principio I), con el alcance acotado de research R3. Re-chequeo
post-diseño: PASA.

## Project Structure

### Documentation (this feature)

```text
specs/116-ml-tipo-detectado-y-baja-clasica/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── contratos-internos.md
├── checklists/
│   ├── requirements.md
│   └── integracion-ml.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Services/MercadoLibre/
│   ├── ProcesadorCambioTipoDetectado.php   # NUEVO: consolida tipo + precio de un cambio hecho en ML
│   ├── SincronizadorTiposPublicacion.php   # detecta cambios, freno por frecuencia, candados
│   ├── CambiadorTipoPublicacion.php        # sin available_downgrades; no consume cambios; aviso de cuotas
│   └── SincronizadorPrecios.php            # docblock de enviarUno(): tercera excepción
├── Console/Commands/
│   ├── SincronizarStockMercadoLibre.php    # dispara la revisión de tipos en cada corrida
│   └── SincronizarTiposPublicacionMercadoLibre.php  # manual; freno = frecuencia_sync_minutos
└── Models/Integraciones/
    └── MercadoLibreCambioTipoPublicacion.php  # + ORIGEN_CRM / ORIGEN_MERCADOLIBRE

bootstrap/app.php                           # sale el schedule propio de tipos

database/migrations/
└── 2026_10_28_000001_add_origen_to_ml_cambios_tipo_publicacion.php

resources/views/ingresos/mercadolibre/vinculaciones.blade.php  # alerta de cuotas en ambos modales
resources/js/mercadolibre-vinculaciones.js                     # mostrar aviso_pierde_cuotas

tests/Feature/Integraciones/
├── CambioTipoPublicacionTest.php           # baja con available_downgrades vacío; sin cambios procesa precio; aviso
├── CambioTipoPublicacionLoteTest.php       # lote a Clásica sin consultar downgrades
├── CambioTipoDetectadoTest.php             # NUEVO: detección, excepción del corte, FR-008/009, primera clasificación
└── RevisionTiposEnCronStockTest.php        # NUEVO: freno por frecuencia, corre con stock bloqueado
```

**Structure Decision**: monolito Laravel existente; todo vive junto a la integración ML (specs 050/084/115).

## Complexity Tracking

| Desvío | Por qué hace falta | Alternativa más simple descartada porque |
|---|---|---|
| Tercera excepción al corte de spec 084 (`omitirCorte` desde un camino automático) | Decisión del usuario: un cambio de tipo hecho en ML es una decisión humana; Premium → Clásica siempre baja 15–31% y quedaría retenido | Respetar el corte retendría casi todos los Premium → Clásica: la publicación sigue con el precio Premium hasta que alguien apruebe. El usuario lo descartó. |
