# Checklist: Calidad de Requisitos — Selector de Depósito en Ventas y Compras

**Purpose**: Validar la calidad, claridad y completitud de spec.md/plan.md antes de tasks/implement
**Created**: 2026-08-06
**Feature**: [spec.md](../spec.md)
**Focus**: calidad de requisitos general, cobertura de stock/depósitos, consistencia con `configuracion_ventas` existente
**Depth**: Standard
**Audience**: Autor + revisión previa a `/speckit-tasks`

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa si el usuario elige un Depósito que se inactiva entre que abre el formulario y hace submit? [Gap]
- [x] CHK002 - ¿Está definido el comportamiento cuando no existe ningún depósito activo en el sistema al abrir "Nueva Venta"/"Nueva Compra"? [Gap]
- [x] CHK003 - ¿Están documentados los cambios de validación en los Form Requests (`StoreVentaRequest`/`UpdateVentaRequest`/`StoreCompraRequest`/`UpdateCompraRequest`) para el nuevo campo obligatorio? [Completeness, Plan §Project Structure]
- [x] CHK004 - ¿Está especificado si `deposito_id` en `ventas`/`compras` es nullable o not-null a nivel de columna, y por qué? [Completeness, Data-model]

## Requirement Clarity

- [x] CHK005 - ¿Está claramente definida la diferencia entre "Depósito por defecto del CRM" (`Deposito::porDefecto()`, fallback global) y "Depósito por defecto de Ventas/Compras" (configurable en `configuracion_ventas`)? [Clarity, Spec §Assumptions]
- [x] CHK006 - ¿Es objetivamente verificable el criterio "el default configurado ya no está activo" del escenario 4 de US2? [Measurability, Spec §User Story 2]

## Requirement Consistency

- [x] CHK007 - ¿Es consistente FR-009 (Depósito de Compra vive en `configuracion_ventas.deposito_compra_id`) con el resto del spec, que en ningún otro punto menciona una tabla `configuracion_compras` nueva? [Consistency, Spec §FR-009]
- [x] CHK008 - ¿Es consistente el nombre de columna `deposito_compra_id` con el patrón ya usado por el proyecto para pares Venta/Compra en la misma tabla (`categoria_id`/`categoria_compra_id`, `tipo_comprobante`/`tipo_comprobante_compra`)? [Consistency, Data-model]

## Scenario Coverage — Stock/Depósitos

- [x] CHK009 - ¿Están cubiertos los tres momentos del ciclo de vida de stock (alta, edición con cambio de depósito, baja) para Venta y para Compra por separado? [Coverage, Spec §User Story 1]
- [x] CHK010 - ¿Está definido el comportamiento cuando se edita una Venta/Compra sin cambiar el Depósito (sólo cantidades)? [Gap] — *Resuelto: FR-005 sólo describe el caso "cambia el Depósito"; el caso "no cambia" queda implícito por el patrón ya existente de `reaplicarPorEdicion`, sin requerir un requisito nuevo.*
- [x] CHK011 - ¿Está especificado qué pasa con el `deposito_id` de una Nota de Crédito/Débito de Compra respecto del `deposito_id` de la Compra "raíz" — son independientes o deben coincidir? [Clarity, Spec §Edge Cases]
- [x] CHK012 - ¿Está cubierto el caso de un ítem de Venta/Compra cuyo producto no controla stock, respecto de si igual requiere seleccionar Depósito a nivel de la operación completa? [Coverage, Spec §FR-001]

## Non-Functional Requirements

- [x] CHK013 - ¿Está especificada la regla de diseño (Select2, catálogo de activos) como requisito verificable y no sólo como nota de implementación? [Clarity, Spec §FR-012]
- [x] CHK014 - ¿Hay requisitos de auditoría/trazabilidad (quién eligió qué depósito) más allá de la persistencia del `deposito_id`? [Gap] — *Fuera de alcance explícito: el spec no lo pide y no hay patrón equivalente para otros campos de Venta/Compra (ej. `categoria_id`); no se agrega.*

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentada la dependencia de este feature respecto de `Deposito::porDefecto()` y de que su definición (menor `id` entre activos) no cambia como parte de este trabajo? [Dependency, Spec §Assumptions]
- [x] CHK016 - ¿Está validada la asunción de que Mercado Libre/Tiendanube no requieren ningún cambio porque resuelven su depósito por una rama de código distinta (`origen` de la Venta)? [Assumption, Research §R1]
- [x] CHK017 - ¿Está documentada la relación de este feature con specs previas (005 Depósitos, 030 Stock de Compras/Ventas, 043/044 Configuración de Ventas/Compras) para que un lector sin contexto entienda qué se reutiliza y qué es nuevo? [Traceability, Plan §Summary]

## Ambiguities & Conflicts

- [x] CHK018 - ¿Queda explícito que este feature es una divergencia deliberada respecto de Contagram real (sin capturas que confirmen un campo Depósito en estas pantallas), y no una fidelidad estructural verificada? [Ambiguity, Spec §Assumptions]
- [x] CHK019 - ¿Hay algún requisito que asuma implícitamente granularidad por ítem (contradiciendo la decisión confirmada de granularidad por operación completa)? [Conflict] — *Revisado: FR-001/FR-002 son explícitos en "no por ítem"; sin conflictos detectados.*

## User Story 3 (N° de comprobante de Compra editable) — ampliación 2026-08-06

- [x] CHK020 - ¿Está definido qué pasa si el usuario deja el campo con el valor sugerido sin tocarlo (no sólo el caso de borrarlo y escribir otro)? [Coverage, Spec §User Story 3 AC2]
- [x] CHK021 - ¿Es consistente FR-019 (persistir "exactamente el valor del campo") con FR-017 (precarga con el correlativo) sin ambigüedad sobre cuál gana si el usuario no interactúa con el campo? [Consistency, Spec §FR-017/FR-019] — *Resuelto: el valor sugerido YA está en el campo desde la carga; "no tocarlo" y "guardarlo tal cual" son la misma acción a nivel de request, sin caso especial en el backend.*
- [x] CHK022 - ¿Está explícitamente delimitada la relación entre el nuevo campo editable y los campos preexistentes `punto_venta_proveedor`/`numero_comprobante_proveedor`/`cae_proveedor`, para que no se lean como duplicados a resolver? [Ambiguity, Spec §Edge Cases, §Assumptions]
- [x] CHK023 - ¿Está definido si se agrega validación de unicidad del N° de comprobante entre Compras? [Gap] — *Resuelto explícitamente como fuera de alcance en Edge Cases: se acepta como límite conocido, no se valida.*
- [x] CHK024 - ¿Es medible/verificable "el sistema le sugiere el que sería el correlativo"? [Measurability, Spec §FR-017] — *Sí: referencia directa a `Compra::siguienteNroComprobante()`, método ya existente y determinístico.*

## Notes

- Ítems CHK001, CHK002, CHK004, CHK006, CHK011, CHK012 señalaban gaps reales — resueltos aplicando fixes directos al spec (ver más abajo) antes de continuar a `/speckit-tasks`, según la regla del proyecto de aplicar los fixes de analyze/checklist sin pedir confirmación intermedia.
