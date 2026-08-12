# Implementation Plan: Módulo de Remitos (Ventas y Compras)

**Branch**: `064-modulo-remitos` | **Date**: 2026-08-12 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/064-modulo-remitos/spec.md`

## Summary

El CRM crea remitos vacíos que nadie puede ver, imprimir, editar ni eliminar. Esta spec construye el
módulo completo —ítems, transportista, documento imprimible, edición y baja— para Ventas y Compras,
calcando la estructura relevada en `docs/Contagram-Informe-Remitos.md` y sus 12 capturas.

El criterio que ordena el diseño: **el remito no mueve nada**. No toca stock, ni tesorería, ni cuenta
corriente, ni la operación de origen, ni ARCA. Es un documento logístico. Eso simplifica el problema:
no hay observers de recálculo, no hay transacciones sobre saldos, no hay CAE. Es un CRUD con
documento imprimible — la complejidad está en la fidelidad estructural, no en la lógica.

**Hallazgo previo al plan**: `remitos.venta_id` es **NOT NULL** en la base, pero `CompraController::remitoStore()`
crea el remito seteando sólo `compra_id`. Es decir que **hoy crear un remito desde una Compra está
roto** — exactamente el mismo bug que ya se documentó para `notas_credito_debito.venta_id`
(`docs/importacion_casos_a_revisar.md` §0). Nunca se detectó porque el remito de Compras jamás se
probó. Se corrige acá.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, `barryvdh/laravel-dompdf` (ya en uso para PDF de Ventas/NC-ND)

**Storage**: MySQL — 1 tabla nueva (`transportistas`), 1 tabla nueva (`remito_items`), columnas
nuevas en `remitos`, y corrección de nulabilidad de `remitos.venta_id`

**Testing**: PHPUnit (Feature tests)

**Target Platform**: aplicación web (Blade + Bootstrap 5 NexaDash + Vite)

**Project Type**: web app single-tenant

**Performance Goals**: no crítico. Volumen bajo (3 remitos en producción hoy); un remito tiene pocas
líneas y su documento se genera bajo demanda.

**Constraints**: no alterar stock, tesorería, cuenta corriente ni la operación de origen (FR-010);
no emitir ante ARCA (FR-011); fidelidad estructural al informe con capturas (SC-007)

**Scale/Scope**: 2 orígenes (Venta y Compra), 5 pantallas nuevas (alta y edición × 2 orígenes +
documento imprimible), 1 entidad nueva reutilizable

## Constitution Check

| Principio | Estado | Cómo se cumple |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ pendiente | `docs/modelo_datos.md` §`remitos` dice hoy *"Estructura interna no relevada en detalle... falta un relevamiento específico antes de implementar contenido más allá del encabezado"*. Ese relevamiento **ya existe** (`docs/Contagram-Informe-Remitos.md`). Hay que actualizar `modelo_datos.md` (tablas y campos nuevos) y `documentacion_principal_crm.md` (§3.6, §5 brechas, §11 reglas) **antes de `/speckit-tasks`**. Incluido como tareas de la Phase 1. |
| **II. Desarrollo spec-driven** | ✅ | Esta spec precede al código, y se apoya en un relevamiento con capturas reales. |
| **III. Corrección fiscal innegociable** | ✅ | El remito **no es fiscal**: sin CAE, sin ARCA, sin precios ni IVA. No toca comprobantes. Por eso tampoco le aplica la exigencia de soft delete (decisión registrada en Clarifications). |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ | El remito no mueve dinero ni stock — pero **precisamente eso** es lo que hay que blindar con tests: el caso negativo (que crear/editar/eliminar no altere stock, tesorería ni la operación) es obligatorio. |
| **V. Convenciones Laravel + dominio en español** | ✅ | `transportistas`, `remito_items`, `remitos` en español, snake_case, relación polimórfica/nullable estándar, FormRequests. |

**Gate**: PASA con una condición — el Principio I exige actualizar los docs de dominio antes de
`tasks`. Queda como tarea explícita, no como buena intención.

## Project Structure

### Documentation (this feature)

```text
specs/064-modulo-remitos/
├── plan.md              # Este archivo
├── spec.md
├── data-model.md        # Tablas y campos nuevos
├── research.md          # Qué se verificó en código y producción
├── quickstart.md        # Cómo validar que funciona
└── checklists/
    └── requirements.md
```

### Source Code

```text
app/
├── Models/
│   ├── Remito.php                  # + items(), transportista(), totalBultos()
│   ├── RemitoItem.php              # NUEVO
│   └── Transportista.php           # NUEVO
├── Http/
│   ├── Controllers/
│   │   ├── RemitoController.php    # NUEVO — create/store/edit/update/destroy/pdf, Ventas y Compras
│   │   └── TransportistaController.php  # NUEVO — sólo store (alta al vuelo) + opciones para el buscador
│   └── Requests/
│       ├── StoreRemitoRequest.php  # NUEVO
│       └── UpdateRemitoRequest.php # NUEVO
database/migrations/
├── ..._create_transportistas_table.php
├── ..._create_remito_items_table.php
├── ..._add_campos_a_remitos.php            # transportista_id, domicilio_entrega, nota, monto_asegurado, tipo
└── ..._hacer_venta_id_nullable_en_remitos.php   # corrige el bug que rompe remitos de Compra

resources/views/remitos/
├── form.blade.php       # página completa, compartida alta/edición, Ventas y Compras
└── pdf.blade.php        # documento imprimible
resources/views/ventas/detalle.blade.php     # + sección Remitos; corregir botón mal cerrado
resources/views/compras/detalle.blade.php    # + sección Remitos
resources/views/ventas/_row_actions.blade.php # corregir link con #remitos
resources/js/remitos.js  # NUEVO — líneas dinámicas, Total Bultos, Select2 transportista, modal alta

tests/Feature/
├── RemitoVentaTest.php
├── RemitoCompraTest.php
└── RemitoNoMueveNadaTest.php   # el test que más importa
```

## Enfoque técnico

### 1. Modelo de datos

Tres cambios, ninguno complejo (detalle en `data-model.md`):

- **`transportistas`**: `id`, `nombre`. Nada más — fidelidad al alta rápida de Contagram (captura 04).
- **`remito_items`**: la pieza que hoy falta. `remito_id`, `producto_id` (nullable, para ítems
  libres), `descripcion`, `codigo`, `observacion`, `cantidad`.
- **`remitos`**: se le suman `transportista_id`, `domicilio_entrega`, `nota`, `monto_asegurado`
  (nullable — null = interruptor apagado), `tipo` (letra, default `X`).

**Corrección de nulabilidad**: `venta_id` pasa a nullable, con la regla "exactamente uno de
`venta_id`/`compra_id`" que `modelo_datos.md` ya documenta pero la base no cumple. Sin esto, US4
(Compras) no puede funcionar.

**Total Bultos** se **deriva** (suma de cantidades), no se persiste: un total guardado puede quedar
desincronizado de sus líneas, y no hay razón de performance para denormalizarlo con este volumen.

### 2. Un solo controlador para dos orígenes

`RemitoController` atiende Ventas y Compras resolviendo el origen desde la ruta, en vez de duplicar la
lógica en `VentaController` y `CompraController` como está hoy. El formulario y el documento
imprimible son la misma vista, parametrizada por origen — lo único que cambia es de dónde salen los
datos de cabecera (cliente vs proveedor) y qué se precarga en el domicilio de entrega (domicilio del
cliente vs depósito que recibe, FR-005).

Rutas, calcando el patrón ya establecido por NC/ND (spec 059) para formularios de página completa:

```
GET    ventas/{venta}/remitos/nuevo          → create
POST   ventas/{venta}/remitos                 → store
GET    ventas/{venta}/remitos/{remito}/editar → edit
PUT    ventas/{venta}/remitos/{remito}        → update
DELETE ventas/{venta}/remitos/{remito}        → destroy
GET    remitos/{remito}/pdf                   → pdf
```

…y sus equivalentes bajo `compras/`. Los `remitoStore` actuales de `VentaController` y
`CompraController` se retiran.

### 3. Página completa, no modal

La regla de UI del proyecto pide modales para alta/edición, pero **la spec 059 ya estableció el
precedente contrario para formularios con tabla de ítems** (fue el mismo caso: NC/ND pasó de modal a
página completa por fidelidad estructural). La captura 02 muestra "Nuevo Remito Venta ID 5" como
página completa. Se sigue ese precedente.

Lo que **sí** va en modal, por AJAX: el alta al vuelo del transportista (captura 04, pide sólo
nombre) y la confirmación de eliminación.

### 4. Documento imprimible

Blade + DomPDF, mismo patrón que `ventas/pdf.blade.php`, servido con `Content-Disposition: inline` y
abierto en el modal PDF compartido (`window.AppPdf.abrir`), como exige la regla de diseño #4 del
proyecto.

Estructura exacta según la captura 10: encabezado REMITO con la letra en recuadro, Nro. Remito, Fecha
de Emisión, Transportista, bloque de datos del cliente (razón social, teléfono, persona de contacto,
condición de IVA, CUIT), Domicilio de Entrega, y tabla Código / Productos / Observaciones / Cantidad.
**Sin precios, sin IVA, sin totales de dinero, y sin el Monto Asegurado** (FR-007, FR-012).

### 5. Que no mueva nada

No hay observers ni servicios de recálculo involucrados: el remito escribe únicamente en `remitos` y
`remito_items`. La garantía se blinda con `RemitoNoMueveNadaTest`, que verifica —para crear, editar y
eliminar, en Ventas y en Compras— que no cambian el stock, los movimientos de tesorería, los cobros ni
los totales de la operación de origen.

Es el mismo criterio que se usó en la spec 063: cuando una feature se define por lo que **no** hace,
el test del caso negativo es el que más vale.

### 6. Borrado en cascada

Al eliminar la Venta/Compra, sus remitos se eliminan (FR-018). Como las Ventas usan **soft delete** y
los remitos no, la cascada de la base de datos no alcanza: se resuelve en el `deleting` del modelo
(mismo lugar donde `VentaObserver` ya revierte cobros y stock), borrando los remitos asociados.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Tocar `VentaObserver::deleting` para la cascada podría afectar el circuito de reversión de cobros/stock que ya existe | Se agrega el borrado de remitos **sin** modificar la lógica existente, y los tests de eliminación de Venta ya existentes deben seguir en verde |
| La corrección de nulabilidad de `venta_id` afecta una tabla con datos en producción | Son 2 filas (tras borrar el N° 3) y ambas tienen `venta_id`; hacer la columna nullable no las altera |
| Los 2 remitos históricos no tienen ítems ni transportista y podrían romper la sección o el PDF | FR-026 lo exige explícitamente: la vista y el documento deben tolerar remitos sin líneas ni transportista |
| Retirar `remitoStore` de Venta/CompraController rompe el botón actual si no se actualiza el front en el mismo cambio | Ambos se tocan en la misma fase; el botón pasa a navegar a la página nueva |
| Duplicar numeración ante emisiones simultáneas | `Remito::siguienteNumero()` ya deriva del máximo; se mantiene y se cubre con test |

## Fuera de alcance

- Remitos electrónicos oficiales ante ARCA (con CAE).
- Pantalla propia de listado de remitos, fuera del detalle de la operación.
- ABM de transportistas (decisión de alcance: sólo alta al vuelo).
- Control de "cantidad pendiente de remitir" entre remitos parciales — fidelidad al original.
- Remitos sobre Presupuestos o cualquier otro origen que no sea Venta/Compra.
