# Implementation Plan: Vendedores como catálogo propio

**Branch**: `020-vendedores` | **Date**: 2026-07-30 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/020-vendedores/spec.md`

## Summary

Reemplazar el "Vendedor" actual de Ventas y Presupuestos —hoy un `vendedor_id` que apunta a la tabla
`users` y se autocompleta en silencio con el usuario logueado, sin select en el formulario— por un
catálogo propio `vendedores` (sólo nombre), con ABM inline desde un select en ambos formularios
(mismo patrón exacto que Categorías: crear/renombrar/eliminar sin salir del formulario, bloqueo de
borrado si está en uso). Se migran los datos existentes generando un vendedor por cada usuario que
hoy aparece como vendedor de al menos una Venta/Presupuesto, preservando el historial. Se agrega
"vendedor por defecto" a las configuraciones de Tiendanube y MercadoLibre, calcado del mecanismo ya
existente de "categoría de venta por defecto" (`categoria_venta_id`), y se usa en la conversión
automática de órdenes a Venta.

El enfoque técnico reutiliza en un 90% patrones ya construidos y probados en el proyecto: el
controlador/JS/modal de ABM inline de Categorías (`CategoriaController`, bloque "Categoría de ventas"
de `resources/js/ventas.js`/`presupuestos.js`), y el patrón de FK opcional "por defecto" en
`tn_configuracion`/`ml_configuracion` (`categoria_venta_id` → replicado como `vendedor_id`). No hay
piezas de dominio nuevas de verdad: es una entidad plana + su ABM inline + su enchufe en dos
formularios y dos pantallas de configuración ya existentes.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent · Yajra DataTables (server-side, sin cambios de forma —las
columnas "vendedor" ya existen) · Select2 · Bootstrap 5 modales + Toastr — todas ya en uso, ninguna
nueva.

**Storage**: MySQL. Tabla nueva: `vendedores` (`id`, `nombre` único, timestamps). Cambios de FK:
`ventas.vendedor_id` y `presupuestos.vendedor_id` pasan de referenciar `users` a referenciar
`vendedores` (con migración de datos). Columnas nuevas: `tn_configuracion.vendedor_id` y
`ml_configuracion.vendedor_id` (FK nullable → vendedores).

**Testing**: PHPUnit (Feature tests). Cobertura: ABM inline de vendedores (crear/renombrar/eliminar,
incluido bloqueo por uso), migración de datos (comando/migración con datos de prueba), asignación de
vendedor por defecto al convertir órdenes de Tiendanube/MercadoLibre.

**Target Platform**: aplicación web monolítica existente (Laravel + Blade), single-tenant. Sin
componentes de infraestructura nuevos.

**Project Type**: aplicación web monolítica (Laravel + Blade).

**Performance Goals**: N/A — catálogo de bajo volumen (decenas de vendedores), sin implicancia de
performance distinta de Categorías.

**Constraints**: sin pantalla de administración propia (decisión de negocio, spec FR-012): el único
punto de entrada de ABM es el select inline. Campo opcional en todos lados (nunca obligatorio).

**Scale/Scope**: 1 tabla nueva, 2 tablas existentes con FK retargeteada + migración de datos, 2
formularios existentes extendidos (Venta, Presupuesto), 2 pantallas de configuración existentes
extendidas (Tiendanube, MercadoLibre), 1 controlador nuevo (`VendedorController`, calcado de
`CategoriaController`).

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa (con acción) | `docs/modelo_datos.md` documenta hoy "vendedor_id \| FK → usuarios" para ventas/presupuestos — es la corrección de fidelidad que motiva la spec (el informe de relevamiento real distingue Vendedor de Usuario). Se corrige `docs/modelo_datos.md` y `docs/documentacion_principal_crm.md` antes de `/speckit-tasks`. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 020 escrita y clarificada (ambigüedades resueltas antes de escribirla) antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | Vendedor no participa del cálculo de IVA, tipo de comprobante ni CAE — es un dato descriptivo. Ventas/Presupuestos siguen con soft delete sin cambios. |
| **IV. Testing donde hay dinero o impacto fiscal** | N/A parcial | Vendedor no mueve dinero ni stock. Igual se testea: (a) integridad de la migración de datos (ningún registro pierde su vendedor), por ser una migración irreversible sobre datos reales; (b) bloqueo de borrado si está en uso, por ser una regla de integridad referencial. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Tabla `vendedores`, columna `vendedor_id`, controlador `VendedorController`, rutas en español, snake_case, sin `empresa_id`. |

Sin contradicciones que resolver: no hay brecha arquitectónica, el patrón de FK-opcional-configurable
ya existe (dos veces: `deposito_id`/`categoria_venta_id`) y sólo se replica una tercera vez.

### Re-evaluación post-Fase 1

✅ Pasa. El diseño de la Fase 1 no introduce ningún patrón que el proyecto no use ya: la tabla
`vendedores` es estructuralmente idéntica a un catálogo plano ya conocido (más simple que
`Categoria`, que tiene jerarquía/tipo/es_sistema); el ABM inline reutiliza el patrón de
`CategoriaController` línea por línea; el "vendedor por defecto" reutiliza el patrón de
`categoria_venta_id` línea por línea. La migración de datos (usuarios → vendedores) es el único paso
sin precedente exacto en el proyecto, pero es una migración de datos simple (agrupar por
`vendedor_id` existente, crear un vendedor por cada `user.name` distinto usado, actualizar FKs) sin
riesgo fiscal ni de stock.

## Project Structure

### Documentation (this feature)

```text
specs/020-vendedores/
├── plan.md              # Este archivo
├── research.md          # Fase 0 — decisiones técnicas
├── data-model.md         # Fase 1 — entidades, columnas, migración de datos
├── quickstart.md         # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md # Fase 1 — contrato de endpoints del CRM
├── checklists/
│   └── requirements.md
└── tasks.md              # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Models/
│   ├── Vendedor.php                            # NUEVO — calcado de Categoria pero sin jerarquía/tipo
│   ├── Venta.php                                # EXTENDER — vendedor(): BelongsTo(Vendedor::class) en vez de User
│   └── Presupuesto.php                          # EXTENDER — idem Venta
├── Models/Integraciones/
│   ├── TiendanubeConfiguracion.php               # EXTENDER — vendedor_id, vendedor(): BelongsTo
│   └── MercadoLibreConfiguracion.php             # EXTENDER — idem
├── Http/Controllers/
│   ├── VendedorController.php                   # NUEVO — store/update/destroy, calcado de CategoriaController
│   ├── VentaController.php                       # EXTENDER — vendedor_id sale de $datos (form), no de $request->user()
│   └── PresupuestoController.php                 # EXTENDER — idem
├── Http/Controllers/Integraciones/
│   ├── TiendanubeConfiguracionController.php     # EXTENDER — pasa $vendedores a la vista, guardarVentas() ya cubre vendedor_id
│   └── MercadoLibreConfiguracionController.php    # EXTENDER — idem
├── Http/Requests/
│   ├── StoreVentaRequest.php / UpdateVentaRequest.php               # EXTENDER — vendedor_id nullable|exists:vendedores,id
│   ├── StorePresupuestoRequest.php / UpdatePresupuestoRequest.php    # EXTENDER — idem
│   ├── Integraciones/GuardarConfiguracionVentasTiendanubeRequest.php   # EXTENDER — vendedor_id nullable|exists:vendedores,id
│   └── Integraciones/GuardarConfiguracionVentasMercadoLibreRequest.php # EXTENDER — idem
├── Services/Tiendanube/ConversorOrdenAVenta.php  # EXTENDER — asigna vendedor_id desde TiendanubeConfiguracion::actual()->vendedor_id
└── Services/MercadoLibre/ConversorOrdenAVenta.php # EXTENDER — idem

database/migrations/
├── xxxx_create_vendedores_table.php
├── xxxx_add_vendedor_id_to_tn_configuracion_table.php
├── xxxx_add_vendedor_id_to_ml_configuracion_table.php
└── xxxx_migrar_vendedor_id_de_users_a_vendedores.php   # datos (DB::transaction real) + retarget de FK (DDL separado, no atómico con lo anterior en MySQL — research.md R2)

resources/views/
├── ventas/form.blade.php                         # EXTENDER — select Vendedor (Select2) + modales crear/renombrar/eliminar
├── presupuestos/form.blade.php                    # EXTENDER — idem
├── ventas/_modal_categoria.blade.php → nuevo _modal_vendedor.blade.php (o incluido inline, ver research.md)
├── presupuestos/_modal_categoria.blade.php → idem
├── configuracion/tiendanube/index.blade.php       # EXTENDER — select "Vendedor por defecto"
└── configuracion/mercadolibre/index.blade.php     # EXTENDER — idem

resources/js/
├── ventas.js                                      # EXTENDER — bloque "Vendedor" calcado del bloque "Categoría de ventas"
├── presupuestos.js                                # EXTENDER — idem
├── tiendanube.js                                  # EXTENDER — vendedor_id en el payload de guardarVentas
└── mercadolibre.js                                # EXTENDER — idem

routes/web.php                                     # EXTENDER — rutas vendedores (store/update/destroy, sin sufijo -venta: research.md R4)

docs/documentacion_principal_crm.md                # EXTENDER — corrige "Vendedor: FK usuarios" → catálogo propio
docs/modelo_datos.md                                # EXTENDER — tabla vendedores, corrige FKs de ventas/presupuestos/tn_configuracion/ml_configuracion

tests/Feature/
├── VendedorTest.php                                # NUEVO
├── VendedorMigracionDatosTest.php                  # NUEVO
└── Integraciones/VendedorPorDefectoTest.php         # NUEVO
```

**Structure Decision**: se respeta la organización vigente. `Vendedor` vive en `app/Models/` (no en
`Integraciones/`, es una entidad de dominio del CRM, igual nivel que `Categoria`/`Cliente`).
`VendedorController` vive en `app/Http/Controllers/` (igual nivel que `CategoriaController`, no
anidado bajo Ventas/Presupuestos porque se comparte entre ambos formularios). Las extensiones a
Tiendanube/MercadoLibre siguen exactamente la ubicación de sus contrapartes de `categoria_venta_id`.

## Enfoque técnico por área

### 1. Modelo y tabla `vendedores`

Tabla plana: `id`, `nombre` (string, unique), timestamps. Sin `activo`, sin `tipo`, sin jerarquía —
a diferencia de `Categoria`, porque no hay caso de uso que la requiera (spec, Assumptions). Modelo
`Vendedor` sin scopes especiales.

### 2. Migración de esquema + datos (retarget de FK)

Una migración agrupa los pasos siguiendo FR-008/SC-002 (ningún historial se pierde). El paso de datos
va en un `DB::transaction()` real; el retarget de FK (DDL) es un paso separado posterior, no atómico
con el anterior en MySQL/MariaDB — si falla, los datos ya migrados quedan a salvo (research.md R2,
corregido en análisis):

1. Crear tabla `vendedores`.
2. Por cada `id` distinto de `users` que aparece en `ventas.vendedor_id` o
   `presupuestos.vendedor_id` (unión de ambos conjuntos), insertar un registro en `vendedores` con
   `nombre = users.name` de ese usuario, guardando el mapeo `user_id → vendedor_id` nuevo.
3. Actualizar `ventas.vendedor_id`/`presupuestos.vendedor_id` con el `vendedor_id` mapeado; luego
   dropear la FK hacia `users` y crear la nueva FK hacia `vendedores` (nullable, `onDelete: restrict`
   para reforzar FR-006 a nivel de base también, no sólo en el controlador).

Ver `data-model.md` para el detalle exacto de columnas/índices y el manejo del edge case de nombres
de usuario duplicados (spec, Assumptions: se crea un vendedor por usuario igual, sin deduplicar).

### 3. ABM inline (`VendedorController`)

Calcado línea por línea de `CategoriaController::crear()/update()/destroy()`, sin la complejidad de
`tipo`/`es_sistema`/jerarquía que no aplica: `store()` (nombre único), `update()` (renombrar,
`Rule::unique(...)->ignore($id)`), `destroy()` (intenta `delete()`, captura `QueryException` de la FK
`restrict` y devuelve 422 "está en uso" — cubre tanto Ventas/Presupuestos como la FK de
`vendedor_id` en `tn_configuracion`/`ml_configuracion`, ya que las tres apuntan a la misma tabla con
la misma restricción).

### 4. Formularios de Venta y Presupuesto

Se agrega el bloque "Vendedor" a `ventas/form.blade.php` y `presupuestos/form.blade.php`: un
`<select id="f-vendedor">` + los mismos 2 botones (renombrar/eliminar) + el mismo modal de
crear/renombrar y el mismo modal de confirmación de borrado que ya existen para Categoría — se
sigue el patrón visual exacto (ver `resources/js/ventas.js:159-428`, bloque "Categoría de ventas"),
reemplazando "categoría" por "vendedor" y `categorias.venta.*` por las rutas nuevas de
`vendedores.*`. `VentaController::store()`/`PresupuestoController::store()` dejan de asignar
`vendedor_id` desde `$request->user()?->id` y pasan a tomarlo de `$datos['vendedor_id'] ?? null`
(validado en el FormRequest como `nullable|exists:vendedores,id`).

### 5. Vendedor por defecto (Tiendanube / MercadoLibre)

Se agrega `vendedor_id` a `tn_configuracion`/`ml_configuracion` (FK nullable → vendedores),
relación `vendedor(): BelongsTo` en ambos modelos de configuración —calcado de `categoriaVenta()`—,
y el campo se incluye en `GuardarConfiguracionVentas*Request` (`nullable|exists:vendedores,id`) y en
la vista de configuración (select "Vendedor por defecto", mismo bloque visual que "Categoría de
venta por defecto", con su propio ABM inline reutilizando el mismo `VendedorController`). En
`ConversorOrdenAVenta` de ambas integraciones, la línea que hoy asigna
`'categoria_id' => TiendanubeConfiguracion::actual()->categoria_venta_id` se acompaña de
`'vendedor_id' => TiendanubeConfiguracion::actual()->vendedor_id` (análogo en MercadoLibre).

### 6. Listados, filtros, detalle, PDF

Sin cambios de forma: `VentaController`/`PresupuestoController` ya hacen `->with(['vendedor:id,name'])`
y `->addColumn('vendedor', fn ($v) => optional($v->vendedor)->name)` — sólo cambia que `vendedor`
pasa a ser `BelongsTo(Vendedor::class)` en vez de `BelongsTo(User::class)`, y la columna seleccionada
pasa de `id,name` a `id,nombre` (columna del modelo `Vendedor`). Las vistas Blade que imprimen
`$venta->vendedor->name` se corrigen a `$venta->vendedor->nombre`.

## Complexity Tracking

*(vacío — sin violaciones que justificar; ver Constitution Check)*
