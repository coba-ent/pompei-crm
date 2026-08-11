# Research: Página completa de NC/ND

## 1. Página completa a reusar como referencia estructural

**Decisión**: modelar `resources/views/notas-credito-debito/form.blade.php` (nueva) sobre
`resources/views/compras/form.blade.php` (create/edit compartido de Compra) — mismo esqueleto
(header con datos heredados no editables, tabla de ítems, Nota Interna, Descuento General,
Percepciones/Impuestos Internos/Intereses colapsados, botones de acción). Un solo Blade sirve para
Ventas y Compras (recibe `$venta` o `$compra` ya resuelto por el controller, mismo patrón polimórfico
que ya usa el resto de NC/ND) y para Crear/Editar (recibe `$notaCreditoDebito` nullable).

**Rationale**: evita duplicar dos formularios casi idénticos (Ventas/Compras) y dos más (Crear/Editar)
— mismo criterio ya aplicado en el resto del módulo NC/ND (`NotaCreditoDebitoController` atiende
ambas entidades vía `venta_id`/`compra_id`).

**Alternativas consideradas**: 4 vistas separadas (venta-crear, venta-editar, compra-crear,
compra-editar) — rechazado, sobre-ingeniería para un formulario que ya es idéntico salvo el
tercero heredado (Cliente vs Proveedor).

## 2. Rutas nuevas

**Decisión**: agregar
`GET ventas/{venta}/notas/nueva` (`ventas.notas.create`),
`GET ventas/{venta}/notas/{notaCreditoDebito}/editar` (`ventas.notas.edit`),
`GET compras/{compra}/notas/nueva` (`compras.notas.create`),
`GET compras/{compra}/notas/{notaCreditoDebito}/editar` (`compras.notas.edit`).
Las rutas `PUT`/`DELETE`/`POST` ya existentes (spec 057) no cambian — sólo se agregan las `GET` que
faltaban porque hoy todo vivía en el modal sin una URL propia.

**Rationale**: mismo patrón `nueva`/`{id}/editar` que ya usan Venta y Compra (`ventas.create`,
`ventas.edit`, líneas 197/199 de `routes/web.php`).

## 3. Qué pasa con el modal de paso 1

**Decisión**: `_modal_ncnd.blade.php` (Ventas y Compras) pierde el bloque `#ncnd-paso-2` completo
(Fecha/Monto/Descripción/Tipo Comprobante/N° Comprobante — todo lo agregado en spec 057) y el botón
"Siguiente" deja de mostrar un 2do paso: en su lugar, arma la URL de la página completa
(`ventas.notas.create` o `ventas.notas.edit`, con los valores de paso 1 como query string —
`tipo`, `documento_ajusta`, `afecta_stock`, `deposito_id`, `mes_imputacion`) y navega ahí
(`window.location.href = ...`). El modal pasa a tener sólo Tipo/Documento que Ajusta/Stock/Mes +
Cancelar/Siguiente — igual que hoy pero sin los botones/lógica del paso 2.

**Rationale**: FR-002 exige que "Siguiente" navegue, no que abra un 2do paso — la forma más simple de
pasarle el contexto del paso 1 a la página completa sin duplicar un formulario oculto es query string
(la página completa los toma para precargar sus propios controles equivalentes, ver §5).

**Alternativa considerada**: guardar el paso 1 en sesión/localStorage — rechazado, más complejidad
para el mismo resultado; la query string ya es legible y permite acceder directo a la URL con
parámetros (FR-010).

## 4. Bloque de ítems: producto vs. descripción libre según Stock

**Decisión**: reusar el mismo componente de tabla de ítems que ya usa `compras/form.blade.php` /
`compras.js` (selector de producto, Cant./Precio/Desc./Subtotal/IVA por fila), condicionado a
`afecta_stock`. Cuando `afecta_stock = false`, la tabla se reduce a una fila fija (no agregable/
quitable) con un `<textarea>` "Descripción" en la celda que normalmente tiene el selector de
producto, resto de columnas igual. El backend ya soporta ambos casos desde spec 057
(`NotaCreditoDebitoItem.producto_id` nullable, `descuento_pct`/`iva_pct` en el ítem).

**Rationale**: reusa componentes ya construidos (render de filas de ítems en `compras.js`) en vez de
crear un componente de tabla nuevo desde cero; el backend ya no requiere cambios (spec 057 ya dejó
`producto_id` nullable y los campos de IVA por ítem).

## 5. Precarga desde query string / desde la nota existente

**Decisión**: en Crear, la página completa lee `tipo`/`documento_ajusta`/`afecta_stock`/
`deposito_id`/`mes_imputacion` de la query string (pasados por el modal, §3) para precargar sus
propios controles equivalentes (que también viven en la página, per FR-010 — accesible sin pasar por
el modal). En Editar, la página completa recibe `$notaCreditoDebito` ya cargado con sus `items` y
arma el JSON de precarga en el propio controller (mismo patrón que `VentaController@edit`/
`CompraController@edit` ya usan para precargar `form.blade.php`).

**Rationale**: consistente con cómo ya se resuelve la precarga de Editar Venta/Compra — el controller
arma los datos, el Blade los vuelca a un `window.NotaFormData = @json(...)` y el JS los usa para
poblar los campos, sin AJAX adicional.

## 6. Tipo/Stock deshabilitados en el modal de Editar

**Decisión**: en `_modal_ncnd.blade.php`, cuando se abre en modo edición (mismo JS que ya llama
`abrirEdicionNota(id)` de spec 057), además de deshabilitar `#ncnd-tipo` (ya lo hacía), deshabilitar
también los radios `#ncnd-afecta-si`/`#ncnd-afecta-no` (`prop('disabled', true)` en vez de sólo
setear el valor). Esto es un cambio de una línea en `ventas.js`/`compras.js` sobre la función ya
existente.

**Rationale**: FR-008 — hoy `abrirEdicionNota()` ya deshabilita Tipo (spec 057); sólo falta replicar
el mismo `.prop('disabled', true)` sobre el radio de Stock.

## 7. Qué se elimina de spec 057

**Decisión**: se elimina (no se reusa) el bloque `#ncnd-paso-2` de `_modal_ncnd.blade.php` (Ventas y
Compras) y toda la lógica JS asociada en `ventas.js`/`compras.js` (`irAPaso(2)`, el handler de
`#btn-ncnd-guardar` dentro del modal, el `#btn-ncnd-eliminar` dentro del modal, el modal
`#modal-eliminar-nota` se **mueve** a la página completa en vez de vivir en el modal). El backend
(`update`/`destroy`/`store`/`storeCompra`, FormRequests, StockService) no se toca.

**Rationale**: evita mantener dos caminos (modal viejo + página nueva) en paralelo, que generaría
código muerto y confusión sobre cuál es la fuente de verdad de la UI.
