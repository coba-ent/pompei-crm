# Research: Vendedores — activar/desactivar

## R1: Patrón de baja lógica a reutilizar

**Decisión**: replicar exactamente el patrón de `Deposito` (`app/Models/Deposito.php` +
`DepositoController`): columna `activo` boolean default `true`, `scopeActivos(Builder $query)`,
endpoint `PATCH .../{id}/estado` que hace toggle y devuelve el nuevo estado, sin permitir bloquear
el toggle por tener operaciones asociadas (a diferencia de `destroy`, que sí puede rechazarse).

**Rationale**: Depósito es el catálogo de Configuración & Ajustes estructuralmente más parecido a
Vendedor (catálogo chico, referenciado por FK en Venta/Compra, con necesidad real de "sacar de
circulación sin borrar" ya resuelta y en producción). Reutilizar el mismo nombre de columna y forma
del endpoint mantiene consistencia de dominio (Principio V) y evita reinventar una solución ya
validada.

**Alternativas consideradas**:
- Soft delete (`deleted_at`) como en documentos fiscales (Principio III): rechazado porque ese
  patrón es para *nunca* volver a mostrar el registro salvo auditoría; aquí se necesita reactivar
  y seguir mostrando el historial con normalidad, que es exactamente el caso de uso de `activo`,
  no de soft delete.
- Estado enum con más de dos valores (`activo`/`inactivo`/`suspendido`): rechazado, no hay pedido
  ni necesidad de un tercer estado; boolean es suficiente y más simple.

## R2: Ubicación del tab dentro de Configuración & Ajustes

**Decisión**: nuevo tab "Vendedores", **siempre visible** (no depende de ninguna Función Avanzada),
agregado a `resources/views/configuracion/index.blade.php` igual que el tab "Ventas" (que tampoco
depende de función avanzada — ver `clavesFuncionConTab` en `index.blade.php:88`, que sólo lista
`depositos`, `mercadolibre`, `tiendanube`, `facturacion_electronica`).

**Rationale**: Vendedor no tiene Función Avanzada asociada (no está en la lista de las 10 de
`docs/documentacion_principal_crm.md §5.1`); es un catálogo de uso permanente en Venta y
Presupuesto, análogo a Ventas/Depósitos.

**Alternativas consideradas**:
- Sub-sección dentro del tab "Ventas" existente (que ya tiene el campo "Vendedor por defecto"):
  rechazado — mezclaría configuración de defaults (fila única) con gestión de un catálogo de N
  filas (tabla), estructuralmente distinto; el usuario ya pidió explícitamente una pestaña nueva.

## R3: Exclusión de inactivos en selects de asignación

**Decisión**: agregar `Vendedor::scopeActivos()` y reemplazar `Vendedor::orderBy('nombre')->get()`
por `Vendedor::activos()->orderBy('nombre')->get()` en los 6 puntos de consumo identificados
(`VentaController` x3, `PresupuestoController` x3, `TiendanubeConfiguracionController`,
`MercadoLibreConfiguracionController`, `ConfiguracionController`), salvo donde el objetivo es
precargar/mostrar un vendedor *ya asignado* (ej. `Vendedor::find($configuracionVentas->vendedor_id)`
en la edición de una Venta existente, que debe seguir resolviendo aunque esté inactivo).

**Rationale**: FR-003/FR-004 exigen excluir de altas nuevas sin romper lo ya asignado; distinguir
"listar para elegir" (se filtra) de "resolver un id ya guardado" (no se filtra) es la única forma
de cumplir ambos sin código adicional de manejo de nulos.

**Alternativas consideradas**:
- Filtrar en el frontend (ocultar opciones inactivas con JS en el Select2 ya cargado): rechazado,
  requeriría mandar igual los inactivos al cliente y duplicar la regla de negocio en JS.

## R4: Vendedor por defecto que queda inactivo

**Decisión**: en `ConfiguracionController::index()`, calcular si `ConfiguracionVentas::first()->vendedor_id`
referencia un vendedor con `activo = false` y pasar un flag a la vista del tab Ventas para mostrar
un aviso (banner Bootstrap `alert-warning`), sin borrar ni tocar el valor guardado en
`configuracion_ventas.vendedor_id`. En "Crear Venta" (`VentaController::create()`), el precálculo
del vendedor por defecto (`Vendedor::find($configuracionVentas->vendedor_id)`) se condiciona a que
esté activo; si no, no se precarga (queda `null`).

**Rationale**: FR-010 pide avisar sin romper nada; no limpiar el `vendedor_id` guardado permite que
si el usuario reactiva el vendedor, la configuración vuelva a funcionar sola sin tener que
reconfigurar.

**Alternativas consideradas**: limpiar automáticamente `vendedor_id` a null al desactivar —
rechazado, perdería la intención original del usuario si reactiva el vendedor por error.

## R5: Tabla del tab Vendedores — DataTables server-side vs client-side

**Decisión**: igual que Depósitos (catálogo chico, decenas de filas): endpoint `data()` devuelve
el listado completo (`Vendedor::orderBy('nombre')->get(['id','nombre','activo'])`), DataTables lo
consume client-side (sin `serverSide: true`). Cumple igual la regla de CLAUDE.md #1 ("DataTables,
responsive, con datos cargados por AJAX") sin necesitar paginado server-side para un catálogo de
este tamaño — mismo criterio ya aplicado a Depósitos.

**Rationale**: consistencia con el catálogo hermano más cercano; server-side processing sería
sobre-ingeniería para un catálogo de decenas de filas.
