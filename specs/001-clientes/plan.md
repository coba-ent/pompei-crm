# Implementation Plan: Base de Datos — Clientes

**Branch**: `001-clientes` | **Date**: 2026-07-17 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-clientes/spec.md`

## Summary

Implementar el ABM (alta, baja lógica, modificación) y listado de **Clientes** del negocio, con sus
datos básicos, datos de facturación (CUIT con verificación contra ARCA, condición de IVA, tipo de
comprobante por defecto), datos comerciales por defecto (categoría, lista de precio, descuento
general, saldo inicial) y campos personalizados. Es la primera entidad del módulo Base de Datos y
prerrequisito de Presupuestos/Ventas/Facturación.

Enfoque técnico: modelos Eloquent + migraciones para `clientes`, `condiciones_iva`, `categorias` y
`listas_precio` (las tres últimas como tablas de soporte mínimas necesarias para las FK del cliente);
controlador resource en Laravel; vistas Blade sobre el layout NexaDash existente; validación vía
FormRequests; y un servicio de verificación de CUIT contra ARCA con interfaz desacoplada (stub por
ahora, integración real cuando exista la capa fiscal). Tests de feature/unit en las reglas de negocio
críticas (unicidad de CUIT, apto-para-facturar, no-eliminar-con-operaciones, rango de descuento).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel Framework 12, Eloquent ORM, Blade; template NexaDash (Bootstrap 5)
para UI; Vite para assets. Front del template ya disponible: **DataTables** (+ responsive + buttons
para exportar), **Toastr** (toasts), **jQuery** (global.min.js) y **Bootstrap bundle** (modales). Se
agrega **`yajra/laravel-datatables-oracle`** (composer) para el procesamiento server-side de las
tablas vía AJAX. Sin otras librerías nuevas.

**UX/UI OBLIGATORIO** (reglas del proyecto, ver `CLAUDE.md`): (1) el listado es una **DataTable
responsive con datos por AJAX server-side**; (2) alta/edición/eliminación se hacen en **modales de
Bootstrap enviados por AJAX**, sin recargar nunca la página; (3) toda notificación (éxito/error) usa
**toasts de Toastr**. En consecuencia, el controlador responde **JSON** en las operaciones (no
redirects), y la validación devuelve errores en JSON para mostrarlos en el modal/toast.

**Storage**: MySQL/MariaDB (XAMPP local, DB `contagram`). Migraciones versionadas de Laravel.

**Testing**: PHPUnit 11 sobre SQLite en memoria (config de `phpunit.xml`). Feature tests para flujos
HTTP y reglas de negocio; unit tests para validación de CUIT y lógica "apto para facturar".

**Target Platform**: Aplicación web (servida por `php artisan serve` en dev; navegador de escritorio).

**Project Type**: Web application monolítica Laravel (backend + Blade en el mismo proyecto).

**Performance Goals**: Listado usable con ≥1.000 clientes; búsqueda por nombre/CUIT con respuesta
percibida <5 s (SC-005). Sin metas de alta concurrencia (single-tenant, pocos usuarios internos).

**Constraints**: Single-tenant (sin `empresa_id`). Corrección fiscal innegociable (no habilitar
facturación sin condición de IVA). La verificación de CUIT contra ARCA no debe bloquear la carga si
el servicio no responde.

**Scale/Scope**: Un negocio, pocos usuarios internos concurrentes; cartera esperable de cientos a
pocos miles de clientes. Alcance: 1 entidad principal (Cliente) + 3 tablas de soporte, ~1 controlador
resource, ~4 vistas, 2 FormRequests, 1 servicio de verificación CUIT.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ La spec y este plan se basan en
  `docs/documentacion_principal_crm.md` §5.1 y `docs/modelo_datos.md` tabla `clientes`. El
  data-model.md derivado se mantiene consistente; si algo cambia, se actualizan los docs de dominio
  en el mismo cambio.
- **II. Desarrollo spec-driven**: ✅ Se está siguiendo el flujo specify → plan → tasks → implement.
- **III. Corrección fiscal innegociable (ARCA)**: ✅ El plan hace obligatoria la condición de IVA para
  "apto para facturar" (FR-011/FR-012); la verificación de CUIT es resiliente a caídas (FR-008). No
  se emiten comprobantes en esta feature (sólo se preparan los datos del cliente).
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Se planifican tests para: validación de CUIT,
  unicidad de CUIT, regla "apto para facturar", regla "no eliminar con operaciones" y rango de
  descuento. CRUD trivial de campos no fiscales sin exigencia estricta.
- **V. Convenciones Laravel + dominio en español**: ✅ Tablas/columnas/modelos/rutas/vistas en español
  (`clientes`, `condiciones_iva`, etc.), snake_case; se usan convenciones estándar de Laravel
  (resource controller, FormRequest, migraciones, seeders). Sin `empresa_id`.

**Resultado del gate**: PASS. Sin violaciones que justificar (Complexity Tracking vacío).

## Project Structure

### Documentation (this feature)

```text
specs/001-clientes/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md        # Fase 1 (este comando)
├── contracts/           # Fase 1 (este comando) — contrato de UI/rutas
│   └── clientes-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya creado)
└── tasks.md             # Fase 2 (/speckit-tasks — NO lo crea este comando)
```

### Source Code (repository root)

Monolito Laravel existente. Los archivos nuevos de esta feature se ubican en la estructura estándar
de Laravel:

```text
app/
├── Models/
│   ├── Cliente.php
│   ├── CondicionIva.php
│   ├── Categoria.php
│   └── ListaPrecio.php
├── Http/
│   ├── Controllers/
│   │   └── ClienteController.php          # resource controller
│   └── Requests/
│       ├── StoreClienteRequest.php
│       └── UpdateClienteRequest.php
├── Services/
│   └── Arca/
│       ├── VerificadorCuit.php            # interfaz/contrato
│       └── VerificadorCuitStub.php        # implementación provisoria
└── Rules/
    └── CuitValido.php                     # regla de validación de formato/DV de CUIT

database/
├── migrations/
│   ├── xxxx_create_condiciones_iva_table.php
│   ├── xxxx_create_categorias_table.php
│   ├── xxxx_create_listas_precio_table.php
│   └── xxxx_create_clientes_table.php
└── seeders/
    ├── CondicionIvaSeeder.php             # catálogo fiscal precargado
    └── DatabaseSeeder.php                 # (actualizado para llamar al seeder)

resources/views/clientes/
├── index.blade.php     # listado: DataTable AJAX server-side + filtros + botón "Nuevo Cliente"
├── _modal_form.blade.php   # modal de alta/edición (form enviado por AJAX)
└── _row_actions.blade.php  # acciones por fila (editar/inactivar/eliminar)

resources/js/clientes.js  # lógica DataTable + submit AJAX del modal + toasts (compilado por Vite)

routes/web.php          # Route::resource('clientes', ...) parcial + rutas AJAX:
                        #   GET clientes/data (DataTables), POST verificar-cuit, PATCH {cliente}/estado

tests/
├── Feature/
│   ├── ClienteAbmTest.php                 # alta/edición/listado/baja lógica vía HTTP
│   └── ClienteFacturacionTest.php         # apto-para-facturar, condición IVA obligatoria
└── Unit/
    ├── CuitValidoTest.php                 # validación de formato/DV
    └── ClienteAptoFacturarTest.php        # lógica de negocio pura
```

**Structure Decision**: Se adopta la estructura monolítica estándar de Laravel (no las opciones
genéricas `src/` del template). Los modelos de soporte (`CondicionIva`, `Categoria`, `ListaPrecio`)
se crean en su forma mínima necesaria para las FK y selects del cliente; su gestión completa (ABM
propio) queda para features posteriores del módulo Base de Datos / Configuración.

## Complexity Tracking

> No aplica — el Constitution Check pasó sin violaciones. Sin desviaciones que justificar.
