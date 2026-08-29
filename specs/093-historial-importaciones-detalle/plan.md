# Implementation Plan: Historial de importaciones — archivo descargable e informe de qué cambió

**Branch**: `093-historial-importaciones-detalle` | **Date**: 2026-08-28 | **Spec**: [spec.md](./spec.md)

## Summary

Dos agregados sobre el historial de importaciones que ya existe, sin tocar el importador.

El **informe** es puramente una lectura: `importacion_filas_snapshot` ya guarda el estado anterior
completo de cada fila, así que alcanza con un servicio que lo compare contra el producto actual y una
pantalla que lo muestre. Cero migraciones para esta mitad.

El **archivo** sí necesita persistencia: hoy se borra al confirmar (a veces), y hay que conservarlo
asociado a su corrida, poder descargarlo y limpiar lo viejo — incluidos los 23 huérfanos actuales.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent; `ImportacionCorrida` + `ImportacionFilaSnapshot` (spec 078);
`ImportacionController` (historial ya existente); NexaDash + DataTables + Toastr

**Storage**: MariaDB. Tabla existente a extender: `importacion_corridas` (3 columnas). Archivos en
`storage/app/private/imports/`, disco `local`. **Ninguna tabla nueva.**

**Testing**: PHPUnit. El informe es lógica de lectura sobre datos de dinero y stock — principio IV.

**Target Platform**: VPS Linux en uso real

**Project Type**: Aplicación web Laravel monolítica

**Performance Goals**: el informe de la corrida más grande hasta hoy (1.117 filas) tiene que
responder sin paginar el resumen. Ver Decisión de rendimiento abajo.

**Constraints**: no tocar el importador ni el flujo de importación (FR-024); guardar el archivo no
puede hacer fallar una importación (FR-016).

**Scale/Scope**: 5 corridas, 1.605 filas de snapshot, 1,33 MB; 23 archivos huérfanos, 9,2 MB.

## Constitution Check

| Principio | Cómo lo cumple |
|-----------|----------------|
| **I — Docs como fuente de verdad** | Hay que agregar a `modelo_datos.md` las 3 columnas nuevas y —importante— la nota de que **los snapshots no son temporales**: son la fuente del informe y nadie debe purgarlos. **Antes de `/speckit-tasks`.** |
| **II — Spec-driven** | Esta es la spec. |
| **III — ARCA** | No aplica. |
| **IV — Testing donde hay dinero** | Aplica: el informe reporta precios y stock. Un informe que miente sobre qué cambió es peor que no tenerlo. |
| **V — Laravel + español** | `archivo_guardado_ruta`, `archivo_guardado_en`, `archivo_vencido_en`; servicios en `app/Services/Import/`. |

**Sin desvíos.** No se agrega ningún patrón que el proyecto no use.

## Project Structure

```text
app/
├── Console/Commands/
│   └── LimpiarArchivosImportacion.php        # nuevo — US3, corrida diaria
├── Http/Controllers/
│   └── ImportacionController.php             # modificado — conservar, descargar, informe
├── Models/
│   └── ImportacionCorrida.php                # modificado — estado del archivo
└── Services/Import/
    └── InformeCambiosImportacion.php         # NUEVO — el corazón de US1

database/migrations/
└── ..._agrega_archivo_guardado_a_importacion_corridas.php

resources/
├── js/importacion-historial.js               # modificado — columnas y modal
└── views/importacion/
    ├── historial.blade.php                   # modificado
    └── _modal_informe_cambios.blade.php      # nuevo

tests/Feature/
├── InformeCambiosImportacionTest.php         # nuevo — US1
├── ArchivoImportacionDescargaTest.php        # nuevo — US2
└── LimpiezaArchivosImportacionTest.php       # nuevo — US3
```

## Decisiones de diseño

Detalle en [research.md](./research.md). Las que gobiernan la implementación:

1. **El informe mide "qué cambió desde la importación"**, no "qué hizo". Se dice en el título y se
   marcan las filas con actividad posterior, reusando los `limite_*` que el snapshot ya guarda.
2. **"Sin detalle disponible" ≠ "sin cambios"**, y **"nunca se guardó" ≠ "venció"**.
3. **90 días configurables**; la limpieza también barre los huérfanos.
4. **Guardar el archivo no puede hacer fallar la importación** — documenta, no gatea.
5. **La descarga hereda el permiso de importaciones**, no inventa uno nuevo.

### Rendimiento del informe

Comparar 1.117 filas contra el producto actual, sus 11 precios y su stock por depósito son ~3
consultas por fila si se hace ingenuamente: **más de 3.000 queries**. Hay que resolverlo con
consultas agregadas por corrida (productos, precios y stock de todos los ids de una vez) y comparar
en memoria. Es lo que hizo el script manual del 28/08 y tardó segundos.

⚠️ **Trampa ya pisada**: `precios_anteriores` y `stock_anterior` son **arrays de objetos**, no mapas
`id => valor`. Leerlos como mapa reportó "192 productos cambiaron en las 11 listas" cuando la
respuesta correcta era **ninguno**. Un test tiene que fijar esto.

## Riesgos

| Riesgo | Mitigación |
|--------|-----------|
| El informe atribuye a la importación un cambio posterior | Título explícito + marca por fila (Decisión 1). Es el riesgo principal de la feature |
| Leer mal el JSON del snapshot y reportar cambios inexistentes | Test con el formato real de producción, no uno inventado |
| El informe tarda demasiado en la corrida de 1.117 filas | Consultas agregadas, no por fila. Criterio de aceptación medible |
| Guardar el archivo rompe una importación | El guardado va fuera del camino crítico y su fallo sólo se registra |
| La limpieza borra un archivo en uso | Excluye las corridas sin confirmar |

## Complexity Tracking

Sin desvíos que justificar.
