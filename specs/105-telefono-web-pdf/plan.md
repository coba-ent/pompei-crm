# Implementation Plan: Teléfono y sitio web en el encabezado de los comprobantes impresos

**Branch**: `105-telefono-web-pdf` | **Date**: 2026-09-18 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/105-telefono-web-pdf/spec.md`

## Summary

Se agregan dos columnas opcionales a la fila única `datos_empresa` (`telefono` y `sitio_web`), se las
expone en la pantalla Configuración & Ajustes → Empresa (ficha de lectura + modal de edición +
validación + `$fillable`), y se las imprime en el partial compartido
`resources/views/pdf/partials/encabezado-emisor.blade.php`.

El punto central del diseño es que **el encabezado del emisor ya es un partial único compartido por
los cinco PDFs**, así que el requisito de que los cinco muestren lo mismo (FR-004) no se cumple
repitiendo el cambio cinco veces, sino cambiando un solo archivo. Los cinco controladores ya le pasan
`$datosEmpresa` a su vista; no hay que tocar ningún controlador de comprobantes.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent; `barryvdh/laravel-dompdf` (DomPDF) para la generación de los PDFs;
Blade para el partial compartido; Bootstrap 5 (NexaDash) + AJAX para el modal de Empresa

**Storage**: MySQL — tabla `datos_empresa`, fila única (single-tenant)

**Testing**: PHPUnit (`php artisan test`). Los tests de PDF existentes del proyecto son *smoke tests*
(afirman que el documento se genera, no su contenido); para esta feature se agrega además aserción
de contenido renderizando el partial como vista (ver research.md, Decisión 3).

**Target Platform**: aplicación web Laravel sobre Linux (VPS de producción) y XAMPP local

**Project Type**: aplicación web monolítica (Blade server-side), single-tenant

**Performance Goals**: sin objetivos propios. Dos columnas más en una tabla de una sola fila y dos
`@if` más en un partial no cambian el tiempo de generación de un PDF de forma perceptible.

**Constraints**:
- La generación de comprobantes NO puede degradarse ni fallar por estos campos (FR-006).
- Ningún dato fiscal puede cambiar: importes, tipo de comprobante, numeración, CAE, QR (FR-009).
- La migración corre sobre una base de **producción con datos reales** (CAE de ARCA emitidos), así
  que debe ser puramente aditiva y reversible.

**Scale/Scope**: 1 tabla (fila única), 1 partial compartido, 5 PDFs afectados, 1 pantalla de
configuración. Sin impacto en el circuito fiscal.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa, con acción obligatoria | `docs/modelo_datos.md` §`datos_empresa` debe actualizarse con las dos columnas nuevas **antes de `/speckit-tasks`**. Al leerlo se detectaron además dos desactualizaciones previas que se corrigen en el mismo cambio: dice que el encabezado lo consumen los PDFs "de Venta y de NC/ND" cuando en realidad son **5**, y le falta la columna `mail_contador` (agregada por migración el 27/08/2026). |
| **II. Desarrollo spec-driven** | ✅ Pasa | Esta feature toca negocio (modelo de datos + comprobantes impresos), así que no entra en la excepción de "cambios triviales". Va por el flujo completo. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ Pasa | El encabezado del emisor es metadata de **presentación**: no participa del circuito WSAA/WSFEv1 ni de la obtención del CAE. FR-009 lo fija como requisito explícito y SC-004 como criterio verificable. No se toca `comprobantes_fiscales` ni el QR. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | No hay cálculo de importes, IVA, stock ni saldos. Por el principio, el CRUD simple podría no requerir tests estrictos; aun así se agregan tests de persistencia y de render del encabezado, porque FR-004 (los 5 comprobantes iguales) y FR-005 (nada se imprime si está vacío) son justamente el tipo de regresión silenciosa que nadie mira hasta que un cliente recibe el PDF mal. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Columnas nuevas en español y snake_case: `telefono`, `sitio_web`. Migración versionada, `$fillable` del modelo, validación en el controlador. Sin `empresa_id` (single-tenant). |

**Desviaciones que requieran justificación**: ninguna.

> Nota para el paso de documentación: la constitución (Principio V) menciona *"la fila única de la
> tabla `empresa`"*, pero la tabla real se llama `datos_empresa` (`empresa` figura en
> `docs/modelo_datos.md` §9 como tabla **descartada**). Es una imprecisión de redacción de la
> constitución, no un conflicto de diseño, y no bloquea esta feature.

## Project Structure

### Documentation (this feature)

```text
specs/105-telefono-web-pdf/
├── plan.md              # Este archivo
├── spec.md              # Qué y por qué
├── research.md          # Fase 0: decisiones técnicas
├── data-model.md        # Fase 1: las dos columnas nuevas
├── quickstart.md        # Fase 1: cómo validar que funciona
├── checklists/
│   └── requirements.md  # Calidad de la spec
└── contracts/
    └── encabezado-emisor.md   # Contrato del partial compartido
```

### Source Code (repository root)

```text
app/
├── Models/
│   └── DatosEmpresa.php                    # + 'telefono', 'sitio_web' en $fillable
└── Http/Controllers/
    └── MiPerfilController.php              # + reglas de validación en guardar()

database/migrations/
└── 2026_09_18_XXXXXX_add_telefono_sitio_web_to_datos_empresa_table.php   # NUEVA

resources/views/
├── pdf/partials/
│   └── encabezado-emisor.blade.php         # + 2 @if — ÚNICO cambio que cubre los 5 PDFs
└── configuracion/mi-perfil/
    └── index.blade.php                     # + 2 campos en ficha y en modal

tests/Feature/
├── MiPerfilTest.php                        # + persistencia de los campos nuevos
└── EncabezadoEmisorPdfTest.php             # NUEVO: render del encabezado y los 5 PDFs

docs/
└── modelo_datos.md                         # §datos_empresa actualizado (obligatorio, principio I)
```

**Archivos que NO se tocan** (y por qué importa): los cinco PDFs
(`ventas/pdf.blade.php`, `presupuestos/pdf.blade.php`, `notas-credito-debito/pdf.blade.php`,
`remitos/pdf.blade.php`, `recibos/pdf.blade.php`) y sus controladores. Todos ya hacen
`@include('pdf.partials.encabezado-emisor')` y ya reciben `$datosEmpresa`. Si la implementación
termina necesitando editarlos, es señal de que se rompió el partial compartido — justo lo que la
decisión de alcance quiso evitar.

## Complexity Tracking

No hay violaciones de la constitución que justificar. La feature es deliberadamente aditiva: dos
columnas nullable, dos `@if` en un partial, dos inputs en un modal.
