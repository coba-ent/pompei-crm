# Implementation Plan: Orden de cuentas de tesorería por drag & drop

**Branch**: `085-orden-cuentas-tesoreria` | **Date**: 2026-08-27 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/085-orden-cuentas-tesoreria/spec.md`

## Summary

Dar interfaz de edición al campo `orden` de `cuentas_tesoreria`, que hoy sólo se puede tocar a mano
en la base. En el modal "Ajustes Cuentas Tesorería" (la ruedita de Tesorería → Saldos) cada fila
suma un handle de arrastre; las filas de un mismo tipo se pueden reordenar con `sortable()` de
jQuery UI, y al soltar se persiste el bloque entero vía `PATCH /tesoreria/cuentas/orden`, que
reasigna `orden = 1..N` en una transacción. Las cards de saldos se repintan con el mecanismo de
refresco que ya existe.

No hay migración, no hay entidad nueva y no se toca el scope de lectura `ordenadas()`: por eso las
cards, los selectores de cuenta y el filtro del informe heredan el orden nuevo sin trabajo extra.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12; JavaScript ES2015+ (sin transpilar, vía Vite)

**Primary Dependencies**: Eloquent; Bootstrap 5 (modal); Toastr (notificaciones); **jQuery UI
`sortable`** — ya vendorizado en `public/vendor/jqueryui/js/jquery-ui.min.js` y ya cargado por el
pagelevel de "Arma tu Informe"; se agrega al pagelevel `tesoreria` de `config/dz.php`

**Storage**: MySQL — tabla `cuentas_tesoreria`, columna `orden` (`smallInteger` nullable) **ya
existente**. Sin migración.

**Testing**: PHPUnit (Feature tests para el endpoint); verificación manual en navegador para el
drag & drop, según la nota de `MEMORY.md` sobre que la suite verde en SQLite no garantiza el
comportamiento en MySQL ni el del front

**Target Platform**: Aplicación web, navegador de escritorio

**Project Type**: Web (Laravel monolítico + Blade + JS por Vite, vendors por pagelevel)

**Performance Goals**: Reflejar el orden nuevo en modal y cards en < 2 s desde el drop (SC-005)

**Constraints**: Sin recarga de página (regla de diseño obligatoria de CLAUDE.md); operación
atómica por bloque; ningún importe puede cambiar

**Scale/Scope**: Decenas de cuentas como máximo en total, repartidas en 4 tipos. 1 endpoint nuevo,
1 Form Request nuevo, 1 método de controlador, cambios en 1 vista Blade, 1 archivo JS, 1 entrada de
`config/dz.php` y 1 ruta.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Justificación |
|-----------|--------|---------------|
| **I. Documentación de dominio como fuente de verdad** | ✅ PASA | Se leyó el módulo de Tesorería antes de especificar. La feature no introduce reglas de negocio nuevas: le da UI a un campo ya documentado. Se actualiza `docs/documentacion_principal_crm.md` (pantalla de configuración de cuentas) antes de `/speckit-tasks`; `docs/modelo_datos.md` no cambia porque el esquema no cambia. |
| **II. Desarrollo spec-driven** | ✅ PASA | La cadena completa `specify → clarify → plan → checklist → tasks → analyze` se ejecuta antes de tocar código. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ N/A | La feature no toca comprobantes, CAE, tipos de comprobante ni condición de IVA. El reordenamiento es puramente de presentación y no modifica ningún registro contable ni fiscal (FR-011). |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ PASA | No hay cálculo de importes involucrado, pero sí una escritura masiva sobre una tabla de configuración de tesorería. Se cubre con Feature tests: reordenamiento feliz, rechazo por id de otro tipo, rechazo por conjunto incompleto, atomicidad, permiso, y un test explícito de que los saldos no cambian (SC-003). |
| **V. Convenciones Laravel + dominio en español** | ✅ PASA | Form Request (`ReordenarCuentasRequest`), método de controlador, ruta con nombre en el grupo existente, nombres de dominio en español (`reordenarCuentas`, `tipo`, `ids`, `orden`). |

**Reglas de diseño obligatorias de CLAUDE.md**:

| Regla | Cumplimiento |
|-------|--------------|
| 1. Tablas con DataTables + AJAX | ✅ N/A — el listado del modal es un catálogo chico ya renderizado sin DataTables, con el mismo patrón que Depósitos, tal como documenta el encabezado de `resources/js/tesoreria.js`. Esta feature no cambia esa decisión. |
| 2. Alta/edición/eliminación por modal + AJAX, sin recargar | ✅ El reordenamiento ocurre dentro del modal existente y persiste por AJAX; la página nunca se recarga (FR-010). |
| 3. Notificaciones con Toastr | ✅ Éxito y error por `toast()`, el helper que ya existe en `tesoreria.js`. |
| 4. PDFs en el modal compartido | ✅ N/A — no hay PDF. |
| 5. Select2 en selects dinámicos | ✅ N/A — no se agrega ningún select. |
| 6. Inputs de fecha con `data-fecha-ar` | ✅ N/A — no se agrega ningún campo de fecha. |

**Post-Phase 1 re-check**: sin violaciones nuevas. El diseño no introdujo dependencias no
justificadas (jQuery UI ya está vendorizado y ya se usa para drag & drop en el proyecto), ni
estructuras de datos nuevas, ni excepciones a las reglas de diseño.

## Project Structure

### Documentation (this feature)

```text
specs/085-orden-cuentas-tesoreria/
├── plan.md                                # Este archivo
├── spec.md                                # /speckit-specify + /speckit-clarify
├── research.md                            # Fase 0
├── data-model.md                          # Fase 1
├── quickstart.md                          # Fase 1
├── contracts/
│   └── reordenar-cuentas-api.md           # Fase 1
├── checklists/
│   └── requirements.md
└── tasks.md                               # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── TesoreriaController.php        # MODIFICADO: + reordenarCuentas()
│   └── Requests/
│       └── ReordenarCuentasRequest.php    # NUEVO: valida tipo + ids
└── Models/
    └── CuentaTesoreria.php                # SIN CAMBIOS (scopes ordenadas/porTipo ya existen)

resources/
├── js/
│   └── tesoreria.js                       # MODIFICADO: handle, sortable, persistir, revertir, teclado
└── views/
    └── tesoreria/
        ├── _config_cuentas.blade.php      # OPCIONAL: sólo un comentario; el HTML de las filas lo arma el JS
        └── saldos.blade.php               # MODIFICADO: + ruta cuentasOrden en TesoreriaConfig.rutas

routes/
└── web.php                                # MODIFICADO: + PATCH tesoreria/cuentas/orden

config/
└── dz.php                                 # MODIFICADO: jQuery UI en el pagelevel 'tesoreria'

public/css/
└── contagram-custom.css                   # MODIFICADO: estilos del handle y del placeholder de arrastre

tests/Feature/
└── Tesoreria/
    └── ReordenarCuentasTest.php           # NUEVO
```

**Structure Decision**: se sigue la separación ya establecida en el módulo (documentada en el
docblock de `TesoreriaController`): las vistas globales de Tesorería y sus acciones de configuración
viven en `TesoreriaController`, mientras que el CRUD de la cuenta individual vive en
`CuentaTesoreriaController`. El reordenamiento es una operación sobre el **conjunto** de cuentas de
un tipo, no sobre una cuenta puntual, así que va en `TesoreriaController` junto a `configCuentas()`,
que es el endpoint que alimenta la misma pantalla.

## Diseño de la implementación

### Backend

1. **`ReordenarCuentasRequest`** (`app/Http/Requests/`):
   - `authorize()`: delega en el permiso del middleware.
   - `rules()`: `tipo` → `required|in:efectivo,banco,a_cobrar,a_pagar`; `ids` → `required|array|min:1`;
     `ids.*` → `required|integer|distinct|exists:cuentas_tesoreria,id`.
   - `messages()`: mensajes en español (la memoria del proyecto registra el pedido de que los
     errores no salgan en inglés).

2. **`TesoreriaController::reordenarCuentas()`**:
   - Compara el conjunto recibido contra `CuentaTesoreria::porTipo($tipo)->pluck('id')`.
     Si difieren → `response()->json([...], 409)` sin escribir nada.
   - `DB::transaction(...)`: recorre `$ids` y hace `CuentaTesoreria::whereKey($id)->update(['orden' => $i + 1])`.
   - Devuelve `['ok' => true, 'mensaje' => ..., 'saldos' => $this->tesoreria->saldos()]`.

3. **Ruta**: `Route::patch('cuentas/orden', ...)->middleware('permiso:tesoreria.editar')->name('cuentas.orden')`,
   declarada antes de `cuentas/{cuenta}`.

### Frontend

4. **`config/dz.php`**: agregar `'vendor/jqueryui/js/jquery-ui.min.js'` al pagelevel `tesoreria`,
   antes de `js/custom.js`.

5. **`saldos.blade.php`**: agregar `cuentasOrden: @json(route('tesoreria.cuentas.orden'))` a
   `TesoreriaConfig.rutas`.

6. **`tesoreria.js`** — dentro del bloque "Configuración de cuentas (US2)":
   - `renderGrupos()`: agregar como primera celda de cada fila un
     `<td class="cuenta-handle-col"><button type="button" class="js-mover-cuenta" aria-label="Reordenar {nombre}"><i class="fas fa-grip-vertical"></i></button></td>`,
     y un `<th>` vacío correspondiente. Marcar el `<tbody>` con `data-tipo="{tipo}"`.
   - Tras renderizar, inicializar por cada `<tbody>` con 2+ filas:
     `$tbody.sortable({ handle: 'button.js-mover-cuenta', items: '> tr', axis: 'y', containment: 'parent', placeholder: 'cuenta-orden-placeholder', helper: fijarAnchoDeCeldas, start: capturarOrdenPrevio, update: () => persistirOrden($tbody) })`.
     **Sin `connectWith`** — así el arrastre entre bloques es imposible por construcción (FR-003).
   - `persistirOrden($tbody)`: arma `ids` leyendo `data-id` de cada `<tr>`; si es igual al orden
     previo capturado, no hace nada (FR-005); si no, aborta el request en vuelo de ese tipo y envía
     el PATCH. En éxito: toast + repintar cards con `resp.saldos` (o `TesoreriaSaldos.recargar()`).
     En 409: toast + `cargarConfigCuentas()`. En otro error: toast + restaurar el orden previo en el
     DOM. Si `statusText === 'abort'`: no hacer nada.
   - Handler de teclado sobre `.js-mover-cuenta`: `ArrowUp`/`ArrowDown` mueven el `<tr>` una
     posición dentro de su `<tbody>` (sin salirse), devuelven el foco al botón y llaman a la misma
     `persistirOrden()`.

7. **`contagram-custom.css`**: `cursor: grab` en el handle, botón sin borde ni fondo con el color
   apagado del template, columna angosta, y una regla para `.cuenta-orden-placeholder` (alto de fila
   con fondo tenue) para que se vea dónde va a caer la fila.

### Tests

8. **`tests/Feature/Tesoreria/ReordenarCuentasTest.php`**: reordenamiento feliz persiste 1..N;
   rechazo 409 con un id de otro tipo; rechazo 409 con la lista incompleta; rechazo 422 con id
   repetido; atomicidad (nada escrito ante el rechazo); 403 sin `tesoreria.editar`; los saldos y los
   campos de la cuenta no cambian tras reordenar.

## Complexity Tracking

No hay violaciones de la constitución ni de las reglas de diseño que requieran justificación. La
feature no agrega proyectos, capas, patrones ni dependencias no vendorizadas.
