# Phase 0 Research: Fidelidad estructural de la tabla NC/ND

## R1 — Dónde vive hoy la tabla NC/ND y sus datos

**Decisión**: Editar `resources/views/ventas/detalle.blade.php` (bloque "Notas de Crédito y Débito",
`<thead>` en la línea ~201) y `resources/views/compras/detalle.blade.php` (bloque equivalente, línea
~205). Los datos llegan ya cargados por `VentaController::show()` / `CompraController::show()` vía
`$venta->notasCreditoDebito` / `$compra->notasCreditoDebito` (eager loaded), sin AJAX/DataTable
separado — es una tabla Blade simple embebida en el detalle, no una `DataTables::eloquent()`.

**Rationale**: No hay endpoint AJAX dedicado a listar NC/ND — se renderiza server-side junto con el
resto del detalle. La regla de UX obligatoria de DataTables+AJAX (CLAUDE.md #1) aplica a *listados*
principales; esta es una sub-tabla de detalle ya existente con este mismo patrón Blade, no se
introduce un patrón nuevo.

**Alternativas consideradas**: Convertir la tabla a DataTables AJAX — descartado por alcance (fuera
de lo pedido, la propia Contagram real la muestra como tabla simple sin paginación server-side) y
por no ser parte de la brecha reportada por el usuario.

## R2 — Controller y Requests de NC/ND (compartidos Venta/Compra)

**Decisión**: Existe un único `NotaCreditoDebitoController` (no separado por Venta/Compra) con
métodos gemelos (`store`/`storeCompra`, `update`/`updateCompra`, etc.), y Requests compartidos
`StoreNotaCreditoDebitoRequest` / `UpdateNotaCreditoDebitoRequest` (no hay una request por módulo).
El campo nuevo `nota_interna` se agrega una sola vez en cada Request compartido, no duplicado.

**Rationale**: Seguir el patrón ya existente (evita bifurcar código donde el proyecto ya decidió
compartirlo).

## R3 — Relaciones ya disponibles para las columnas nuevas

**Decisión**: Reutilizar sin modificar:
- `NotaCreditoDebito::comprobanteFiscal()` (MorphOne a `ComprobanteFiscal`) → fuente de Estado,
  Comprobante (tipo) y N° Comprobante de la propia nota.
- `NotaCreditoDebito::notaAjustada()` (BelongsTo a sí misma, spec 057 FR-013) → cuando existe, es la
  fuente de "Documento que Ajusta" (se usa su propio `tipo_comprobante`/`nro_comprobante` o su
  `comprobanteFiscal`, ver R4).
- `Venta::comprobanteFiscal()` / `Compra::comprobanteFiscal()` (MorphOne) → fuente de "Documento que
  Ajusta" cuando la nota NO tiene `nota_ajustada_id` (caso default: ajusta al comprobante original).

**Rationale**: Ya existen y están probadas (specs 007/057); no se requiere nueva lógica de dominio,
sólo exponerlas en la vista.

## R4 — Qué mostrar exactamente en "Documento que Ajusta"

**Decisión**: Prioridad de resolución por nota:
1. Si `nota_ajustada_id` está seteado → mostrar tipo+número de la nota ajustada: si esa nota tiene
   `comprobanteFiscal` aprobado, usar su número real; si no, usar su `tipo_comprobante`/
   `nro_comprobante` propios (los que la propia nota registra al crearse) o "-" si ninguno existe.
2. Si no hay `nota_ajustada_id` → mostrar el comprobante fiscal de la Venta/Compra original
   (`$venta->comprobanteFiscal` / `$compra->comprobanteFiscal`) si existe y está aprobado; si no
   existe, mostrar "-".

**Rationale**: Coincide con el hallazgo documentado en el informe NC/ND §10 ("Documento que Ajusta"
puede quedar vacío si no hay factura ARCA emitida, y el sistema lo permite igual) y con el
encadenamiento de spec 057 FR-013.

**Alternativas consideradas**: Mostrar siempre el número de la Venta/Compra en el CRM (no el
comprobante fiscal) — descartado porque Contagram real muestra el N° de comprobante fiscal (factura),
no el ID interno, en esa columna (ver captura de referencia).

## R5/R6 — RETIRADOS en `/speckit-analyze`

Las decisiones originales de R5 ("Estado a mostrar en la columna Estado") y R6 ("menú de acciones
separado del Estado") se retiraron: al cruzar la captura de referencia con
`docs/documentacion_principal_crm.md` (línea ~493) se confirmó que en Contagram real el ícono de la
columna Estado **es** el propio disparador del menú de fila (Editar/Eliminar/Ver Detalle) — el CRM ya
replica ese comportamiento. No había brecha real que corregir en Estado; el trabajo se concentra en
N° Comprobante (R1-R4) y Nota Interna (R7-R8).

## R7 — Migración para `nota_interna`

**Decisión**: Nueva migración `add_nota_interna_to_notas_credito_debito_table` — columna
`nota_interna` tipo `text`, nullable, sin default. Se agrega a `$fillable` en
`app/Models/NotaCreditoDebito.php`.

**Rationale**: Sigue el mismo tipo/nulabilidad que `nota_interna` en `ventas`/`compras` (texto libre
opcional, ya usado en el proyecto — ver `documentacion_principal_crm.md`).

## R8 — Formularios de alta/edición (modal NC/ND)

**Decisión**: Agregar el campo Nota Interna (`<textarea>` o `<input>` simple) a
`resources/views/ventas/_modal_ncnd.blade.php` y `resources/views/compras/_modal_ncnd.blade.php`
(un solo modal reutilizado por ambos flujos, a confirmar contra el código real al implementar), y el
campo `nota_interna` a las reglas de `StoreNotaCreditoDebitoRequest`/`UpdateNotaCreditoDebitoRequest`
(`nullable|string`).

**Rationale**: Consistente con cómo ya se cargan `descripcion`/`mes_imputacion` en el mismo modal.

## Resumen de NEEDS CLARIFICATION resueltos

Ninguno quedaba pendiente de Technical Context — el stack, testing y estructura ya están fijados por
la constitución y el código existente del proyecto.
