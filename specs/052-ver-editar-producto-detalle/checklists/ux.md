# Checklist: Calidad de requisitos UX/Frontend — Ver/Editar producto desde el detalle

**Purpose**: Validar que la especificación (spec.md, plan.md) define con suficiente precisión el
comportamiento de UI compartido entre Ventas, Presupuestos y Compras antes de pasar a tareas.
**Created**: 2026-08-06
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md)

## Consistencia entre las 3 pantallas

- [x] CHK001 - ¿La spec exige explícitamente que el desplegable, los modales y el comportamiento de refresco sean idénticos entre Ventas, Presupuestos y Compras? [Consistency, Spec §FR-010]
- [x] CHK002 - ¿Está definido un criterio verificable para comprobar esa consistencia (no sólo "debe ser igual")? [Measurability, Spec §SC-003]
- [ ] CHK003 - ¿La spec aclara qué pasa si en el futuro una de las 3 pantallas necesita una acción adicional en el desplegable (ej. sólo Compras) sin romper la consistencia exigida? [Gap]

## No duplicación de lógica (extracción a módulo compartido)

- [x] CHK004 - ¿El plan documenta explícitamente qué lógica de `productos.js` se comparte y cuál permanece específica de cada pantalla? [Clarity, Plan §Project Structure]
- [x] CHK005 - ¿Está especificado el mecanismo de comunicación entre el módulo compartido y cada pantalla consumidora (evento, callback, etc.) en vez de dejarlo implícito? [Completeness, Data-model §Evento de integración]
- [ ] CHK006 - ¿La spec/plan definen qué pasa si `producto-modales.js` no llega a cargarse en una de las 3 páginas (fallback, error silencioso, o requisito duro de carga)? [Gap, Exception Flow]

## No pérdida de datos del formulario (Venta/Presupuesto/Compra)

- [x] CHK007 - ¿Está especificado que cerrar "Ver" o cancelar "Editar" debe dejar el formulario padre intacto, con criterio de aceptación explícito? [Completeness, Spec §FR-007]
- [ ] CHK008 - ¿Se especifica el comportamiento si el usuario tiene cambios sin guardar en el formulario padre (ej. un ítem recién tipeado) y abre "Editar" de otra fila — se preserva ese estado en memoria o hay riesgo de que el re-render lo pise? [Gap, Edge Case]
- [ ] CHK009 - ¿Está definido qué ocurre si dos modales quedan abiertos simultáneamente (ej. modal de Cobranza + modal de Editar producto) en términos de foco/scroll, más allá del fix genérico ya documentado en el código existente? [Gap, Ambiguity]

## No romper el guardado existente de Productos

- [x] CHK010 - ¿El plan exige que el refactor de `productos.js` mantenga el comportamiento actual (recarga de DataTable, stats) sin regresión, como criterio explícito y no sólo implícito en "sin cambios"? [Clarity, Plan §Constitution Check]
- [ ] CHK011 - ¿Existe un criterio de aceptación (aunque sea manual) que cubra específicamente "editar un producto desde la vista de Productos sigue funcionando igual que antes" después del refactor? [Coverage, Gap]

## Refresco de fila y precio manual (FR-006)

- [x] CHK012 - ¿La regla de "no pisar un precio tipeado manualmente" está definida de forma objetiva y verificable (no ambigua)? [Measurability, Spec §FR-006]
- [ ] CHK013 - ¿Se especifica qué pasa con el campo de referencia (`_precioCatalogoOriginal`) si el usuario cambia la Lista de Precios general del formulario después de agregar la fila — sigue siendo válida la comparación? [Ambiguity, Data-model]

## Casos de error y bordes

- [x] CHK014 - ¿Está definido el comportamiento ante falla de carga del producto al abrir Ver/Editar desde el detalle (toast, no apertura de modal vacío)? [Completeness, Spec §FR-009]
- [x] CHK015 - ¿Está definido que filas sin `producto_id` no muestran el desplegable? [Completeness, Spec §FR-008]
- [ ] CHK016 - ¿Se especifica el comportamiento si el producto fue inactivado (no eliminado) mientras el formulario estaba abierto — se permite igual "Ver"/"Editar" o se bloquea? [Gap, Edge Case]

## Notas

- Ítems sin marcar (CHK003, CHK006, CHK008, CHK009, CHK011, CHK013, CHK016) son de bajo impacto o
  tienen un default razonable ya cubierto por el comportamiento existente del código (no bloquean
  `tasks`); se resuelven durante la implementación siguiendo el patrón ya usado en `productos.js`
  y `ventas.js`, y se documentan en `tasks.md` si requieren una tarea explícita.
