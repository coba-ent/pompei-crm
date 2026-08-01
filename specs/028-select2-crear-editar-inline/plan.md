# Implementation Plan: Crear/editar catálogo inline en selects de Presupuestos

**Branch**: `028-select2-crear-editar-inline` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/028-select2-crear-editar-inline/spec.md`

## Summary

Los selects Select2 de Cliente, Categoría de Venta y Vendedor en el formulario de Presupuestos (`resources/views/presupuestos/form.blade.php`) hoy resuelven el alta/edición del catálogo con links "Renombrar"/"Eliminar" fijos al lado del label, que sólo operan sobre el ítem ya seleccionado, y el select de Cliente no tiene ningún mecanismo inline. El comportamiento real de Contagram (capturas `docs/capturas/saldos/`) muestra el patrón correcto: dentro del propio dropdown Select2, una opción fija "Crear X" (ícono +) arriba del listado, y un ícono de lápiz por cada fila que abre la edición de ESE ítem sin seleccionarlo. La solución es puramente de frontend: usar las opciones nativas de Select2 (`templateResult` para renderizar el ícono de lápiz por resultado + una opción sintética "crear" inyectada primera en `data`/`processResults`) y delegar el submit de los modales ya existentes; para Cliente se agrega un modal de alta/edición rápida (sólo campo Nombre) que reutiliza los endpoints REST ya existentes (`clientes.store`/`clientes.update`, que sólo exigen `nombre`).

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12) + JavaScript (jQuery, sin build de TS)

**Primary Dependencies**: Select2 (`vendor/select2`, ya usado en `presupuestos.js`), Bootstrap 5 (modales), Toastr (NexaDash) para notificaciones — todas ya presentes en el proyecto, sin dependencias nuevas.

**Storage**: MySQL (sin cambios de esquema — reutiliza tablas `clientes`, `categorias`, `vendedores` ya existentes)

**Testing**: PHPUnit (Feature tests) para los endpoints reutilizados si hace falta cubrir un caso no cubierto hoy; sin nueva lógica de backend no hay tests de dominio nuevos que agregar (Constitución IV: CRUD simple no exige tests estrictos).

**Target Platform**: Web (navegador de escritorio, mismo alcance que el resto del CRM)

**Project Type**: Web application (Laravel monolito + Blade/Vite) — feature acotada a una vista existente

**Performance Goals**: N/A (interacción de UI, sin volumen relevante — catálogos de decenas/cientos de ítems ya paginados por Select2 `ajax`)

**Constraints**: Debe cumplir las especificaciones de diseño obligatorias del proyecto (modal Bootstrap + AJAX sin recarga, notificación Toastr, Select2 con `dropdownParent` correcto al estar dentro de la página, no dentro de otro modal en este caso).

**Scale/Scope**: 1 vista (`presupuestos/form.blade.php`), 1 archivo JS (`resources/js/presupuestos.js`), 2 partials de modal existentes a reubicar como disparador (`_modal_categoria`, `_modal_vendedor`) + 1 partial de modal nuevo (`_modal_cliente_rapido` o similar) + reutilización de rutas ya existentes.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/documentacion_principal_crm.md` §3.1 ya documenta Presupuestos; esta feature no introduce campos/entidades nuevas, así que no requiere edición de ese documento salvo una nota aclaratoria sobre el patrón de creación/edición inline (se agrega en Phase 1). PASA.
- **II. Desarrollo spec-driven**: se sigue el flujo completo specify→clarify→plan→checklist→tasks→analyze. PASA.
- **III. Corrección fiscal (ARCA)**: no aplica — no toca comprobantes, CAE ni condición de IVA. N/A.
- **IV. Testing donde hay dinero o impacto fiscal**: no hay cálculo de importes/IVA/stock/tesorería involucrado; es alta/edición de nombre de catálogo. No se exigen tests nuevos de dominio; se valida manualmente el flujo end-to-end (quickstart.md) y se linkea a los tests ya existentes de Cliente/Vendedor/Categoría si corresponde extenderlos. PASA (sin violación).
- **V. Convenciones Laravel + dominio en español**: se reutilizan nombres y rutas ya existentes en español (`clientes`, `vendedores`, `categorias-venta`). PASA.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/028-select2-crear-editar-inline/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
resources/views/presupuestos/
├── form.blade.php            # quita links Renombrar/Eliminar del label; agrega @include del modal rápido de Cliente
├── _modal_categoria.blade.php   # sin cambios de estructura (se dispara distinto)
├── _modal_vendedor.blade.php    # sin cambios de estructura (se dispara distinto)
└── _modal_cliente_rapido.blade.php  # NUEVO: modal de alta/edición rápida de Cliente (sólo Nombre)

resources/js/presupuestos.js
└── helper compartido para Select2 "catálogo editable" (opción "Crear X" fija + templateResult con ícono
    de lápiz por fila), aplicado a #f-cliente, #f-categoria, #f-vendedor

app/Http/Controllers/ClienteController.php   # sin cambios (se reutiliza store/update ya existentes)
app/Http/Controllers/VendedorController.php  # sin cambios
app/Http/Controllers/CategoriaController.php # sin cambios
routes/web.php                                # sin cambios (rutas ya existentes: clientes.store,
                                                # clientes.update, vendedores.store, vendedores.update,
                                                # categorias.venta.store, y falta confirmar update de categoría)
```

**Structure Decision**: Cambio acotado a la vista de Presupuestos y su JS asociado (Opción "Single project" — Laravel monolito ya existente, sin nueva app/servicio). No se toca Ventas/Otros Ingresos/Compras (fuera de alcance, FR-007). El backend se reutiliza tal cual: la única pieza nueva es el partial del modal rápido de Cliente en el frontend.

## Complexity Tracking

*(vacío — sin violaciones de la constitución que requieran justificar)*
