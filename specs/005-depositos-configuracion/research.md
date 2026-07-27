# Research: Gestión de Depósitos

## 1. Patrón de guardado: por-fila inmediato vs. lote con diff

**Pregunta**: Contagram real muestra un único par Cancelar/Guardar al pie del modal, sugiriendo un
guardado en lote de todos los cambios hechos durante esa apertura del modal. ¿Replicamos ese
mecanismo de diff, o usamos guardado inmediato por fila?

**Decisión**: Guardado inmediato por fila/acción (alta, renombrado, toggle de activo, eliminación
son 4 llamadas AJAX independientes, cada una con su propio toast), igual que
`ListaPrecioController`/`TipoProductoController` ya construidos. "Guardar" y "Cancelar" del modal
sólo cierran la ventana — no hay estado pendiente que reconciliar porque nada quedó sin persistir.

**Rationale**: Es el patrón ya establecido y probado en este proyecto para catálogos chicos
idénticos en forma (nombre + activo, alta/renombrar/eliminar inline). Introducir un mecanismo de
"diff de lote" nuevo sólo para Depósitos sería una excepción sin justificación de negocio real — el
resultado visible para el usuario (la lista queda como la dejó) es el mismo.

**Alternativas consideradas**:
- *Guardado en lote real (comparar estado inicial vs. final del modal y mandar un solo PATCH)*:
  rechazada — más complejidad de estado en el frontend (hay que trackear qué cambió) para un
  catálogo que, en la práctica, se edita una fila a la vez.

## 2. Regla "no eliminar con operaciones asociadas" para Depósito

**Pregunta**: ¿qué cuenta como "el depósito tiene operaciones asociadas" para bloquear su
eliminación física?

**Decisión**: Un depósito NO se puede eliminar físicamente si existe al menos una fila en `stocks`
con `deposito_id` = ese depósito y `cantidad != 0`, **o** al menos una fila en `movimientos_stock`
con ese `deposito_id` (histórico, aunque el stock actual sea 0 por movimientos que se cancelaron
entre sí). Método `Deposito::tieneOperaciones(): bool`.

**Rationale**: Mismo criterio ya usado para Cliente (`tieneOperaciones()` sobre ventas futuras),
Proveedor (sobre `productos.proveedor_id`) y Producto (sobre `movimientos_stock`) — protege contra
perder trazabilidad de valorización de inventario histórica, no sólo el saldo actual.

## 3. Reuso de patrones existentes (sin research adicional)

- **Modal Bootstrap + AJAX + toasts**: mismo patrón de `ListaPrecioController`/
  `productos.js` (sección de Listas de Precio del modal de Producto).
- **`tieneOperaciones()` como guardia de eliminación**: mismo patrón ya usado en
  `Cliente`/`Proveedor`/`Producto`.
- **Filtro "Depósito" de Productos**: ya existente (`Deposito::activos()`), sin cambios — sigue
  poblándose con los mismos depósitos activos, ahora gestionados desde esta pantalla.
