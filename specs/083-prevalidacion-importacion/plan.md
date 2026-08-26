# Implementation Plan: Prevalidación y confirmación previa de la importación

**Branch**: `083-prevalidacion-importacion` | **Date**: 2026-08-26 | **Spec**: [spec.md](./spec.md)

## Summary

Al pedir importar, el asistente abre un **modal de confirmación** que analiza el archivo completo
contra el mapeo elegido **sin escribir nada**, y muestra cuántas altas, cuántas actualizaciones,
**qué campos se van a modificar y a cuántos registros**, y qué filas fallan. Si hay una sola fila con
error, no deja confirmar. Los 3 pasos del asistente **no cambian**: el modal se inserta entre el
mapeo y la escritura.

Junto con eso se corrigen los cuatro defectos que salieron al importar la planilla real de Ferrum:
fórmulas de Excel que entraban como texto, "Precio venta" que no automapeaba, mensajes de error en
inglés con nombres internos, y un resumen que podía informar el resultado de una corrida anterior.

**Sin cambios de esquema.** La prevalidación es estado transitorio, igual que el archivo y el mapeo.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: `maatwebsite/excel` (PhpSpreadsheet) — se pasa a usar PhpSpreadsheet directo
para el volcado, para poder pedir el **valor calculado** de las fórmulas. Sin dependencias nuevas.

**Storage**: sin cambios de esquema. La prevalidación vive en disco junto al NDJSON de la spec 082, o
en sesión si es chica — se decide en Fase A. Nunca en base de datos.

**Testing**: PHPUnit (Feature + Unit). La suite de importación de las specs 026/027/074/078/082 es el
contrato de no-regresión.

**Target Platform**: Linux (VPS, nginx + PHP-FPM 8.2) y XAMPP local.

**Project Type**: monolito Laravel + Blade.

**Performance Goals**: prevalidar 10.000 filas con progreso visible, en tandas que respeten el mismo
margen de ~60 s del proxy que la spec 082. Cálculo de fórmulas medido: ~3,3 s para 9.632 filas.

**Constraints**: la prevalidación no puede escribir. Tiene que usar las mismas reglas que la
importación real. Cero regresiones sobre 006/026/027/074/078/082.

**Scale/Scope**: hasta 10.000 filas, 3 entidades.

## Constitution Check

| Principio | Estado | Notas |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ | Se leyó §2.4 antes de especificar. La feature agrega un **modal de confirmación** y **revierte la tolerancia por fila**: §2.4 debe actualizarse **antes de `/speckit-tasks`**. `modelo_datos.md` no cambia. |
| **II. Desarrollo spec-driven** | ✅ | Esta cadena. No se implementa hasta que el usuario lo pida. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ N/A | No toca comprobantes, CAE ni numeración. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ | La importación toca precios, costos y stock. Tests obligatorios. El defecto de "Precio venta" dejó 124 productos en 0: exactamente el riesgo que el principio protege. |
| **V. Convenciones Laravel + dominio en español** | ✅ | Servicios en `app/Services/Import/`, nombres en español. |

⚠️ **Gate pendiente**: actualizar §2.4 de `docs/documentacion_principal_crm.md` **antes** de
`/speckit-tasks`.

**Nota sobre fidelidad a Contagram (regla de oro)**: el modal de confirmación previa **no** existe en
Contagram real. Es una divergencia deliberada, pedida por el usuario a raíz de un incidente con datos
reales. Se documenta como tal en §2.4 para que una sesión futura no la "corrija" creyendo que es un
desvío accidental. A favor: **no** altera la estructura de pasos del asistente (sigue siendo subir →
mapear → resumen) y respeta la regla de diseño #2 del proyecto, que pide modales + AJAX para las
operaciones.

## Project Structure

### Documentation (this feature)

```text
specs/083-prevalidacion-importacion/
├── spec.md              # ✅ creado
├── research.md          # ✅ creado (Fase 0)
├── plan.md              # ✅ este archivo
├── data-model.md        # ✅ sin cambios de esquema; documenta el informe de prevalidación
├── quickstart.md        # ✅ verificación manual
├── contracts/
│   └── validador-filas.md   # ✅ contrato del servicio que valida sin escribir
├── checklists/
│   ├── requirements.md  # ✅ creado
│   └── calidad.md       # ⬅ /speckit-checklist
└── tasks.md             # ⬅ /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/Import/
│   ├── ValidadorFilasImportacion.php    # NUEVO: mapea + resuelve alta/actualización + valida. NO escribe.
│   ├── InformePrevalidacion.php         # NUEVO: acumula conteos y errores entre tandas
│   ├── ImportadorFilas.php              # MODIFICAR: delega la validación en el validador; mensajes en español
│   ├── FuenteFilasImportacion.php       # MODIFICAR: vuelca con las fórmulas YA calculadas
│   └── DefinicionCamposImportables.php  # MODIFICAR: alias faltantes (empezando por "Precio venta")
├── Exports/
│   └── ProductosExport.php              # sin cambios (es la referencia contra la que se testea)
└── Http/Controllers/
    └── ImportacionController.php        # MODIFICAR: paso de revisión, bloqueo, resumen atado a la corrida

resources/views/importacion/
├── mapear.blade.php              # MODIFICAR: "Confirmar" abre el modal de prevalidación en vez de importar
├── _modal-confirmacion.blade.php # NUEVO: conteos, campos a modificar, errores, confirmar bloqueado
└── resumen.blade.php             # MODIFICAR: archivo y fecha de la corrida

tests/
├── Unit/
│   └── ValidadorFilasImportacionTest.php     # NUEVO
└── Feature/
    ├── PrevalidacionImportacionTest.php      # NUEVO: conteos, bloqueo, "no escribe nada"
    ├── FormulasExcelImportacionTest.php      # NUEVO: fórmulas calculadas y no evaluables
    ├── RoundTripExportImportTest.php         # NUEVO: FR-014/FR-016, falla si falta un alias
    └── ResumenImportacionTest.php            # NUEVO: el caso 1002 → 2
```

**Structure Decision**: monolito existente. La lógica nueva entra como dos servicios en
`app/Services/Import/`, junto a los que ya viven ahí. En el frontend **no** se agrega una pantalla:
el asistente conserva sus 3 pasos y la confirmación se resuelve con un **modal de Bootstrap + AJAX**,
como pide la regla de diseño #2 del proyecto. El modal reutiliza el mecanismo de tandas y la barra de
progreso que ya existen para la importación.

## Fases de implementación

### Fase A — Extraer la validación (base de todo)

1. `ValidadorFilasImportacion`: recibe una fila cruda + el mapeo y devuelve **qué haría** (alta,
   actualización o error con motivo). **No tiene forma de escribir** — no recibe el `StockService` ni
   toca modelos para persistir.
2. `ImportadorFilas` pasa a usarlo, para que ambos caminos compartan las reglas por construcción (FR-003).
3. Tests de que el validador y el importador coinciden fila por fila sobre los mismos datos.

### Fase B — Fórmulas calculadas

4. `FuenteFilasImportacion::volcar()` lee con PhpSpreadsheet pidiendo el valor calculado.
5. Celda no evaluable → se marca; nunca se guarda el texto de la fórmula.
6. Tests con un `.xlsx` real con fórmulas sin cachear, incluyendo una fórmula rota.

### Fase C — Mensajes en español

7. Mensajes de validación propios del importador + nombres de atributo tomados del mapeo real.
8. Tests de que ningún motivo tiene inglés ni nombres internos.

### Fase D — Prevalidación por tandas y modal de confirmación

9. `InformePrevalidacion` acumulando entre tandas (mismo mecanismo que la spec 082), incluido el
   **conteo de registros afectados por campo** (FR-005b).
10. Endpoint de prevalidación + modal con conteos, campos a modificar, detalle de errores y progreso.
11. Bloqueo del confirmar si hay ≥ 1 error (FR-005), cancelación sin efecto (FR-005c) y verificación
    de huella (FR-009).

### Fase E — Round-trip exportación ↔ importación

12. Alias faltantes en `DefinicionCamposImportables`.
13. Test que compara **todos** los encabezados del export contra etiquetas + alias y falla listando huérfanos.
14. Test de round-trip: exportar → reimportar sin cambios → cero diferencias.

### Fase F — Resumen confiable

15. El acumulado se ata a la importación en curso; una importación nueva descarta lo anterior.
16. El resumen de Productos se arma desde la `ImportacionCorrida` (archivo + fecha + contadores).
17. Para Clientes y Proveedores —que no tienen corrida— un identificador de corrida en sesión,
    generado en el Paso 1 y validado al mostrar el resumen.
18. Test del caso reproducido: 1000 residuales + 2 importados debe informar **2**.

### Fase G — Verificación end-to-end

19. Prueba manual en **local** con el archivo real de Ferrum y con el catálogo de 9.632 filas,
    incluyendo un caso de actualización masiva para ver el listado de campos afectados.
20. Recorrer la checklist de calidad.

## Riesgo declarado

**FR-005 revierte la tolerancia por fila de las specs 006/026.** Con una fila mala en 9.000, no se
importa nada. Es decisión explícita del usuario del 26/08/2026, tomada después de que entraran 124
productos con código y precio incorrectos. Queda registrado en la spec y en §2.4; si en el uso diario
resulta demasiado rígido, la vuelta atrás es un solo requisito (FR-005) y no arrastra al resto.

## Complexity Tracking

> Sin violaciones de la constitución que justificar. La divergencia respecto de Contagram real (el
> paso de revisión) está declarada arriba, con su motivo.
