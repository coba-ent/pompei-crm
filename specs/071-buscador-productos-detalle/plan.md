# Implementation Plan: Buscador de productos del detalle con foco persistente

**Branch**: `071-buscador-productos-detalle` | **Date**: 2026-08-19 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/071-buscador-productos-detalle/spec.md`

## Summary

Se reemplaza el `<select>` + Select2 del campo `#f-producto` de Venta, Compra y Presupuesto por un
widget propio (`resources/js/buscador-catalogo.js`): un `<input type="text">` siempre visible más un
panel de sugerencias que se abre y cierra sin afectar el foco del input. El widget se escribe una
sola vez, expone una API mínima (`montar(el, opciones)` con callbacks `buscar`/`formatear`/
`onElegir`) y cada pantalla le pasa su propia lógica de "qué línea armar", que es lo único que
difiere entre ellas. El backend, el endpoint de catálogo y toda la lógica de detalle quedan
intactos.

## Technical Context

**Language/Version**: JavaScript ES2015+ (mismo nivel que el resto de `resources/js/`), PHP 8.2 /
Laravel 12 sólo del lado de las vistas Blade

**Primary Dependencies**: jQuery (ya presente, usado por todo el front del proyecto), Bootstrap 5
(NexaDash) para clases visuales, Vite para el build. **No se agrega ninguna dependencia nueva** — el
widget es código propio. Select2 se sigue usando en el resto del proyecto y **no se desinstala**.

**Storage**: N/A — la feature no persiste nada; sólo lee el catálogo por el endpoint existente.

**Testing**: Node test runner sobre módulos JS puros (ya existe el precedente `tests/js/fecha-ar.test.mjs`)
para la lógica testeable sin DOM (debounce, descarte de respuestas fuera de orden, navegación de
índice resaltado). Verificación manual en navegador para la interacción completa, según `quickstart.md`.

**Target Platform**: Navegador de escritorio (Chrome), app web Laravel + Blade

**Project Type**: Web app monolítica; el cambio es exclusivamente de front-end

**Performance Goals**: Sin degradación respecto de Select2: debounce de 250 ms (idéntico al default de
Select2) y una sola consulta en vuelo relevante por término (SC-005)

**Constraints**: No modificar `ProductoController::opciones` ni ninguna ruta; no cambiar la lógica de
armado de líneas del detalle; no tocar los demás selects de las 3 pantallas ni del resto del sistema

**Scale/Scope**: 1 módulo JS nuevo, 3 archivos JS modificados, 3 vistas Blade modificadas (una línea
cada una), 1 bloque de CSS, más actualización de `CLAUDE.md` §5 y de la documentación de dominio

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: aplica parcialmente. `docs/documentacion_principal_crm.md`
  describe el flujo de carga del detalle pero no la mecánica del widget, así que no hay contradicción
  que resolver. Sí hay dos actualizaciones obligatorias que salen de esta feature: (a) `CLAUDE.md` §5
  prescribe hoy el patrón `setTimeout(() => $el.select2('open'), 0)` para carga en lote — queda
  desactualizado para estos 3 casos; (b) la brecha detectada de "Crear Producto" (la etiqueta lo
  promete y el buscador no lo hace) debe quedar registrada como pendiente. Ambas van como tareas.
  **PASS con acciones pendientes.**
- **II. Desarrollo spec-driven**: cumplido — specify → clarify → plan → checklist → tasks → analyze
  antes de implementar. **PASS.**
- **III. Corrección fiscal innegociable (ARCA)**: no aplica directamente (no se emite nada, no se toca
  CAE ni numeración), **pero** el widget alimenta el detalle de comprobantes que sí son fiscales. Por
  eso FR-006/SC-004 exigen que la línea resultante sea idéntica a la actual, y hay tareas de test
  específicas para eso. **PASS.**
- **IV. Testing donde hay dinero o impacto fiscal**: aplica. La línea que se agrega lleva precio/costo
  e IVA. El widget en sí es interacción, pero el "qué línea se arma" no puede cambiar: se cubre con
  tests JS de la lógica pura y verificación manual comparativa documentada en `quickstart.md`. **PASS.**
- **V. Convenciones Laravel + dominio en español**: el módulo, sus opciones y sus callbacks se nombran
  en español (`buscador-catalogo.js`, `montar`, `onElegir`, `formatear`), igual que el resto del
  front. **PASS.**

**Desviación a registrar (no violación)**: CLAUDE.md §5 obliga a usar Select2 en todo select de datos
dinámicos. Esta feature introduce la primera excepción. No es una violación de la constitución (que no
menciona Select2) sino de la guía operativa, y el propio §5 se actualiza en esta entrega para
documentar la excepción y su motivo. Ver `research.md` Decisión 1.

## Project Structure

### Documentation (this feature)

```text
specs/071-buscador-productos-detalle/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── buscador-catalogo-api.md
├── checklists/
│   ├── requirements.md
│   └── interaccion.md   # generado por /speckit-checklist
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
resources/
├── js/
│   ├── buscador-catalogo.js     # NUEVO: el widget reutilizable (input + panel de sugerencias)
│   ├── ventas.js                # modificado: #f-producto pasa de initSelect2() a BuscadorCatalogo
│   ├── compras.js               # modificado: idem
│   └── presupuestos.js          # modificado: idem
└── views/
    ├── ventas/form.blade.php        # modificado: <select id="f-producto"> → <input type="text">
    ├── compras/form.blade.php       # modificado: idem
    └── presupuestos/form.blade.php  # modificado: idem

public/css/
└── contagram-custom.css         # modificado: estilos del panel de sugerencias

tests/js/
└── buscador-catalogo.test.mjs   # NUEVO: tests de la lógica pura (debounce, orden de respuestas, índice)

CLAUDE.md                        # modificado: §5 "Carga en lote" documenta la excepción
docs/documentacion_principal_crm.md  # modificado: brecha "Crear Producto" en §5 (pendientes)
```

**Structure Decision**: Proyecto Laravel monolítico ya existente. El widget vive en un único módulo
`resources/js/buscador-catalogo.js` importado por los 3 JS de pantalla — no se duplica lógica ni se
crea un paquete nuevo. Se sigue el mismo patrón de módulo global auto-contenido que ya usan
`fecha-ar.js` y `producto-modales.js`.

## Complexity Tracking

*Sin violaciones de la constitución a justificar. La única desviación (excepción a la regla de Select2
de CLAUDE.md §5) está justificada en `research.md` Decisión 1 y se documenta en la propia guía.*
