# Implementation Plan: Conversión manual obligatoria para órdenes de Mercado Libre en estado excepcional

**Branch**: `066-ml-conversion-manual-excepciones` | **Date**: 2026-08-14 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/066-ml-conversion-manual-excepciones/spec.md`

## Summary

Cuatro situaciones —orden cancelada, reclamo en mediación, reembolso parcial y alerta de fraude— dejan de
poder convertirse en Venta sin que una persona lo decida, y pasan a poder convertirse **sólo** a mano con
una confirmación explícita que queda registrada.

El enfoque técnico se apoya en que `EvaluadorConvertibilidad` ya es el **único** punto que deriva el estado
de conversión: agregar ahí los dos casos que faltan (mediación y reembolso parcial) los excluye de una vez
del cron y del lote, porque ambos operan sobre órdenes en `Lista`. Sobre eso se agrega un camino de
conversión forzada en `ConversorOrdenAVenta`, cerrado por defecto y que sólo se abre con un parámetro
explícito que el controlador exige en la petición.

Dos datos nuevos en `ml_ordenes` sostienen todo: si la orden tiene un reclamo en mediación (hoy se pierde,
vive sólo en el payload crudo) y con qué motivo se forzó la conversión (necesario para auditar y para no
duplicar el aviso de la spec 063).

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, Bootstrap 5 (NexaDash), DataTables server-side, Toastr, Select2

**Storage**: MySQL/MariaDB — tabla `ml_ordenes` (2 columnas nuevas + 2 de auditoría)

**Testing**: PHPUnit (`tests/Feature/Integraciones/`)

**Target Platform**: Aplicación web Laravel single-tenant

**Project Type**: Web application (Blade + controladores JSON, sin SPA)

**Performance Goals**: sin impacto — la detección de mediación se resuelve sobre el payload que la
sincronización ya trae, sin llamadas adicionales a la API de Mercado Libre

**Constraints**: la conversión forzada emite un comprobante fiscal; aplica el principio III de la
constitución. La confirmación tiene que ser imposible de saltear desde el cliente.

**Scale/Scope**: 1 migración, 1 enum extendido, 4 servicios tocados, 1 controlador, 1 FormRequest, 2 vistas,
1 archivo JS

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Cómo se cumple |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ Pendiente | Se leyó §3.2 de `documentacion_principal_crm.md` y §`ml_ordenes` de `modelo_datos.md` antes de especificar. **Ambos hay que actualizarlos antes de `/speckit-tasks`**: la regla de negocio es nueva y la tabla suma columnas. |
| **II. Desarrollo spec-driven** | ✅ | Es una feature de negocio y va por la cadena completa. No aplica la excepción de cambio trivial. |
| **III. Corrección fiscal innegociable** | ✅ | Forzar una conversión **emite un comprobante**. El diseño no toca la derivación del tipo de comprobante ni la obtención del CAE, y mantiene el soft delete. La confirmación se valida en el servidor, no sólo en la UI. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ | Cubierto con tests obligatorios: exclusión del automático y del lote, rechazo sin confirmación, conversión forzada exitosa, y no duplicación del aviso. Ver Phase 1. |
| **V. Convenciones Laravel + dominio en español** | ✅ | Columnas `en_mediacion`, `forzada_motivo`, `forzada_por_id`, `forzada_en`. Migración versionada, FormRequest para la validación, enums existentes reusados. |

**Resultado del gate**: pasa, con la obligación explícita de actualizar los dos documentos de dominio antes
de generar las tareas.

### Re-evaluación post-diseño (Phase 1)

Sin violaciones nuevas. No se agregan proyectos, capas ni patrones: el diseño usa los puntos de extensión
que el módulo ya tiene (`EvaluadorConvertibilidad` como único derivador de estado, `MercadoLibreOperacionLog`
como bitácora de la integración, `MotivoRequiereAtencion` como catálogo de motivos). La sección de
Complexity Tracking queda vacía a propósito.

## Project Structure

### Documentation (this feature)

```text
specs/066-ml-conversion-manual-excepciones/
├── plan.md              # Este archivo
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/
│   └── conversion-forzada.md
├── checklists/
│   └── requirements.md
└── tasks.md             # Lo genera /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/MercadoLibre/
│   └── MotivoExcepcional.php             # NUEVO — única definición de la precedencia de motivos
├── Enums/MercadoLibre/
│   ├── EstadoConversion.php              # sin cambios (no se agrega un sexto estado)
│   └── MotivoRequiereAtencion.php        # + esExcepcional() / motivosExcepcionales()
├── Models/Integraciones/
│   └── MercadoLibreOrden.php             # + casts y helper enEstadoExcepcional()
├── Services/MercadoLibre/
│   ├── EvaluadorConvertibilidad.php      # + mediación y reembolso parcial (núcleo del cambio)
│   ├── ConversorOrdenAVenta.php          # + camino forzado y conteo de excluidas
│   ├── SincronizadorOrdenes.php          # + persistir en_mediacion
│   ├── TraductorOrdenes.php              # sin cambios (tieneMediacion() ya existe)
│   └── DetectorCancelaciones.php         # + no repetir el aviso del motivo forzado
├── Http/
│   ├── Controllers/Ingresos/
│   │   └── MercadoLibreVentaController.php   # habilitar GET y exigir confirmación en POST
│   └── Requests/Integraciones/
│       └── ConvertirOrdenRequest.php     # + forzar_conversion
database/migrations/
└── 2026_08_20_060001_add_mediacion_y_conversion_forzada_a_ml_ordenes.php

resources/
├── views/ingresos/mercadolibre/
│   ├── index.blade.php                   # motivo en el listado + filtro
│   └── convertir.blade.php               # aviso de confirmación
└── js/mercadolibre.js                    # modal de confirmación + resumen del lote

tests/Feature/Integraciones/
└── MercadoLibreConversionForzadaTest.php
```

**Structure Decision**: se respeta la estructura existente del módulo de Mercado Libre. No se crean
carpetas ni capas nuevas — el cambio es quirúrgico sobre servicios que ya tienen la responsabilidad
correcta asignada.

## Decisiones de diseño

### 1. El evaluador es el único lugar donde se decide

`EvaluadorConvertibilidad::evaluar()` ya lo usan `SincronizadorOrdenes` (en cada corrida) y
`ConversorOrdenAVenta` (en cada intento). Agregar ahí los dos casos faltantes resuelve FR-002 y FR-003 de
una sola vez, **sin tocar el cron ni el botón de lote**, porque los dos filtran por `estado_conversion =
Lista`. No hace falta una lista de exclusión paralela.

### 2. Precedencia de motivos: se reusa la que ya existe

`DetectorCancelaciones::determinarMotivo()` evalúa **la mediación primero**, porque puede convivir con
cualquier estado de orden. El evaluador tiene que usar exactamente ese orden, y lo correcto es extraerlo a
un único lugar compartido en vez de escribirlo dos veces — que dos componentes decidan el motivo con
criterios distintos es cómo se producen las inconsistencias difíciles de rastrear.

Orden resultante: **mediación → cancelada → reembolso parcial → alerta de fraude**.

### 3. La conversión forzada es un parámetro, no un servicio aparte

`ConversorOrdenAVenta::convertir()` suma `bool $forzada = false`. Cerrado por defecto: todo lo que ya lo
llama (cron, lote, conversión normal) mantiene su comportamiento sin tocarse. Sólo el controlador pasa
`true`, y sólo cuando la petición trae la confirmación.

Hay que reordenar las guardas actuales, porque hoy hay dos que bloquean antes de tiempo:

```
if ($orden->estado_orden === EstadoOrden::Cancelada) → rechazo    // bloquea incluso forzando
if ($orden->estado_orden !== EstadoOrden::Pagada)    → rechazo    // una cancelada no está "pagada"
```

Quedan como: si la orden está en estado excepcional y **no** viene forzada, se rechaza; si viene forzada, se
saltean **sólo** esas guardas. Las de datos (publicación sin vincular, cliente ambiguo, moneda, variantes) y
las de contexto (función desactivada, sólo lectura, ya convertida, no pagada por estar pendiente) **siguen
aplicando igual** — FR-013 y FR-014.

### 4. La confirmación se valida en el servidor

FR-010 dice que no se puede saltear. Un modal en el navegador no es una barrera: el `POST` de conversión
existe y se puede llamar directo. Por eso `forzar_conversion` es un campo del `FormRequest` y el
controlador rechaza con 409 cualquier conversión de una orden excepcional que llegue sin él. La UI es
comodidad; la regla vive en el backend.

### 5. Dos datos nuevos, no una tabla nueva

- `en_mediacion`: la sincronización ya tiene el payload crudo en la mano y `TraductorOrdenes::tieneMediacion()`
  ya sabe leerlo. Persistirlo evita que el evaluador dependa de un dato que sólo existe durante la
  sincronización.
- `forzada_motivo` / `forzada_por_id` / `forzada_en`: sostienen FR-011 (auditoría) y FR-018 (no repetir el
  aviso). El detalle completo de la operación va a `MercadoLibreOperacionLog`, que es la bitácora que el
  módulo ya usa; en la orden queda sólo lo que hace falta para decidir.

### 6. Excluidas ≠ fallidas en el resumen del lote

`convertirTodasLasListas()` hoy devuelve `total / convertidas / fallidas`. Suma `excluidas` con su detalle.
Una exclusión es el sistema funcionando como se le pidió; contarla como falla haría que el resumen parezca
un error recurrente y entrenaría a la persona a ignorarlo.

## Complexity Tracking

Sin violaciones que justificar.
