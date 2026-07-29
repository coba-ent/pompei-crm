# Implementation Plan: Lista de Precios en la configuración de Mercado Libre

**Branch**: `016-lista-precio-mercadolibre` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/016-lista-precio-mercadolibre/spec.md`

## Summary

Agregar un campo "Lista de Precios" a `ml_configuracion`, configurable desde la pantalla Configuración →
Integraciones → Mercado Libre junto a Depósito y Categoría de Venta ya existentes, y usarlo para
etiquetar (no para calcular precio) toda Venta creada al convertir una orden de Mercado Libre.

Es una extensión mínima de infraestructura ya construida en las specs 011/012/013: replica al pie de la
letra el patrón ya usado por `categoria_venta_id` (mismo tipo de campo — FK opcional, sin fallback,
mismo select AJAX/Select2, misma vía de persistencia en `ConversorOrdenAVenta::convertir()`). No hay
pieza nueva de arquitectura: una columna, una relación Eloquent, un `<select>` más en un formulario ya
existente, y una línea más en el array de `Venta::create()`.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent · Bootstrap 5 + NexaDash · Select2 · Toastr (todas ya en uso, ninguna
nueva)

**Storage**: MySQL. Una columna nueva: `ml_configuracion.lista_precio_id`.

**Testing**: PHPUnit (Feature tests), extendiendo `tests/Feature/Integraciones/` (spec 011/012/013) y/o
el test existente de conversión de `ConversorOrdenAVenta`.

**Target Platform**: mismo entorno que specs 011/012/013 (hosting compartido y VPS) — esta spec no toca
ningún proceso programado ni llamada a la API de Mercado Libre, así que las restricciones de
portabilidad de esas specs no aplican aquí.

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: n/a — operación de configuración de baja frecuencia y un `SELECT`/asignación
adicional en un flujo que ya hace varias escrituras por conversión; sin impacto medible.

**Constraints**: ninguna nueva. No debe alterar el cálculo de precios existente (FR-005 del spec) —
restricción de correctitud, no de performance.

**Scale/Scope**: un único negocio. 1 columna nueva, 1 campo de formulario nuevo, 1 línea de wiring en un
service ya existente.

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | Sin contradicción: la spec no inventa nada que `docs/modelo_datos.md` o `docs/documentacion_principal_crm.md` contradigan; sólo agrega un campo. Actualización de ambos documentos programada antes de `/speckit-tasks` (ver spec, "Impacto en la documentación de dominio"). |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 016 escrita y clarificada antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | No toca CAE, tipo de comprobante, ni borrado físico. FR-005 blinda explícitamente que el cálculo de importes/IVA de la Venta no cambia — el requisito más sensible de este principio queda intacto por diseño. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Aunque el campo en sí no mueve dinero, toca el flujo de creación de Venta desde Mercado Libre — se agregan tests que verifican que el total y los precios de línea no cambian (ver spec, Restricciones de diseño y entorno § Testing). |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | `lista_precio_id`, snake_case, español, sin `empresa_id`; mismo patrón que `categoria_venta_id`. |

No hay violaciones — sin Complexity Tracking.

### Re-evaluación post-Fase 1

✅ Pasa. El diseño de la Fase 1 (`data-model.md`) confirma que no se necesita ninguna pieza nueva más
allá de lo previsto en el Summary: una columna, una relación, un campo de formulario y una línea de
wiring, todos calcados de un patrón ya existente en el propio archivo que se modifica.

## Project Structure

### Documentation (this feature)

```text
specs/016-lista-precio-mercadolibre/
├── plan.md              # Este archivo
├── research.md          # Fase 0
├── data-model.md         # Fase 1
├── quickstart.md         # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md # Fase 1 — contrato de la ruta ya existente, extendida
├── checklists/
│   └── requirements.md
└── tasks.md              # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Models/Integraciones/
│   └── MercadoLibreConfiguracion.php          # EXTENDER — fillable + relación listaPrecio()
├── Http/Requests/Integraciones/
│   └── GuardarConfiguracionVentasMercadoLibreRequest.php  # EXTENDER — regla lista_precio_id
├── Http/Controllers/Integraciones/
│   └── MercadoLibreConfiguracionController.php # EXTENDER — pasar $listasPrecio a la vista
└── Services/MercadoLibre/
    └── ConversorOrdenAVenta.php                # EXTENDER — lista_precio_id en Venta::create()

database/migrations/                            # 1 alter: add lista_precio_id a ml_configuracion
resources/views/configuracion/mercadolibre/
    └── index.blade.php                         # EXTENDER — <select> Lista de Precios (Select2)
resources/js/mercadolibre.js                    # EXTENDER — leer/guardar el nuevo campo
tests/Feature/Integraciones/                    # EXTENDER — cobertura de FR-003/004/005/006
```

**Structure Decision**: cero archivos nuevos fuera de la migración. Todo el cambio vive dentro de
archivos que las specs 011/012/013 ya crearon para exactamente este propósito (configuración y
conversión de Mercado Libre) — es la definición misma de "extensión mínima", no una pieza de
arquitectura nueva.

## Enfoque técnico por área

### 1. Dato y persistencia

Migración `add_lista_precio_field_to_ml_configuracion_table` agrega `lista_precio_id` (FK nullable →
`listas_precio.id`, sin `onDelete cascade` — ver [research.md R1](./research.md)). `fillable` y relación
`listaPrecio(): BelongsTo` en `MercadoLibreConfiguracion`, mismo patrón que `deposito()`/`categoriaVenta()`
ya presentes en ese modelo.

### 2. Configuración (pantalla + guardado)

`MercadoLibreConfiguracionController::index()` pasa `$listasPrecio = ListaPrecio::activos()->orderBy('nombre')->get()`
a la vista (siguiendo el mismo query que ya hace para `$categoriasVenta`). El Request
`GuardarConfiguracionVentasMercadoLibreRequest` agrega la regla `'lista_precio_id' => ['nullable', 'exists:listas_precio,id']`,
idéntica a la que ya tiene `categoria_venta_id`. `guardarVentas()` no cambia: ya persiste con
`$configuracion->update($request->validated())`, que recoge el campo nuevo sin tocar el método.

### 3. Interfaz

Un `<select id="ml-lista-precio-id">` más en el mismo `<form>` de la sección "Configuración de Ventas",
junto a Depósito y Categoría de Venta. `resources/js/mercadolibre.js` lo agrega al selector conjunto de
Select2 (línea 106: `$('#ml-deposito-id, #ml-categoria-venta-id, #ml-lista-precio-id').select2(...)`),
a la carga de datos existentes (línea ~134, mismo patrón `.trigger('change.select2')`) y al payload de
guardado (línea ~204).

### 4. Conversión

`ConversorOrdenAVenta::convertir()` agrega una clave al array de `Venta::create()` (línea ~152, junto a
`categoria_id`): `'lista_precio_id' => MercadoLibreConfiguracion::actual()->lista_precio_id`. Sin
condicionales adicionales: si el valor configurado es `null`, `Venta::create()` ya lo persiste como
`null` sin necesidad de una rama especial (FR-004 se cumple por la ausencia de lógica extra, no por una
adición).

## Complexity Tracking

*(vacío — sin violaciones que justificar)*
