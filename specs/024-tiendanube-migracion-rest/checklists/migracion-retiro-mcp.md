# Requirements Quality Checklist: Migración de configuración y retiro del MCP

**Purpose**: Validar que los requisitos que cubren la parte más riesgosa de esta spec —migrar
configuración de negocio de `tn_configuracion` a `tn_conexion_rest` y retirar la integración MCP sin
romper nada— están completos, claros y sin ambigüedad antes de tasks/implementación.
**Created**: 2026-07-31
**Feature**: [spec.md](../spec.md)

**Note**: Este checklist valida la CALIDAD de los requisitos escritos (spec.md + plan.md), no el
comportamiento del sistema una vez implementado.

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa si la migración de datos de `tn_configuracion` a
  `tn_conexion_rest` corre dos veces (reintento de un deploy fallido)? [Gap] — Resuelto: spec.md
  Assumptions especifica idempotencia (sobreescritura de la fila única, no inserción).
- [x] CHK002 - ¿Están enumerados explícitamente todos los campos de configuración de negocio que deben
  sobrevivir al retiro del MCP (depósito, categoría, cuenta de tesorería, lista de precios, vendedor,
  modo sólo lectura, creación automática, ventana de días, frecuencia)? [Completeness, Spec §Key Entities, Plan §Enfoque técnico 4] —
  Resuelto: data-model.md §1 los enumera con su tipo y origen.
- [x] CHK003 - ¿Especifica la spec qué ocurre con el historial de operaciones MCP (`tn_operaciones_log`)
  al retirar la integración — se conserva, se exporta, o se descarta? [Completeness, Spec §FR-018/FR-020] —
  Resuelto: data-model.md §3 especifica que se descarta (no es "dato de negocio" bajo FR-020).
- [x] CHK004 - ¿Está definido el criterio objetivo de "validado en producción" que habilita pasar a la
  Historia 3 (retiro del MCP), o queda a criterio subjetivo del operador? [Clarity, Spec §Assumptions] —
  Resuelto: spec.md Assumptions lo define explícitamente como confirmación manual del responsable técnico.

## Requirement Clarity

- [ ] CHK005 - ¿Es "mismo comportamiento observable" (SC-002) suficientemente específico como para saber
  si una diferencia de milisegundos en el momento de sincronización de stock constituye una regresión?
  [Clarity, Ambiguity, Spec §SC-002]
- [x] CHK006 - ¿Distingue la spec con claridad entre "datos de negocio" (que se conservan, FR-020) y
  "datos de conexión/credenciales MCP" (que se eliminan, FR-018), de forma que no haya duda sobre a qué
  categoría pertenece cada campo de `tn_configuracion`? [Clarity, Spec §FR-018, §FR-020] — Resuelto:
  data-model.md §1/§2 clasifica cada campo de `tn_configuracion` en "migra" vs. "se pierde con la tabla".
- [ ] CHK007 - ¿Especifica la spec si el retiro del MCP (Historia 3) es un paso manual disparado por una
  persona, o una condición automática que el sistema evalúa solo? [Clarity, Spec §Assumptions]

## Requirement Consistency

- [x] CHK008 - ¿Es consistente el criterio de exclusión de variantes con múltiples opciones (talle/color)
  entre esta spec (no se excluyen, Edge Cases) y el precedente de Mercado Libre (spec 023, sí se
  excluyen) — está la diferencia justificada explícitamente y no como una omisión? [Consistency, Spec §Edge Cases] —
  Resuelto: justificado en spec.md Edge Cases y research.md R7 (variante ya es la unidad de vinculación en TN).
- [ ] CHK009 - ¿Usan las tres historias de usuario (vinculación, migración funcional, retiro MCP) el mismo
  criterio de éxito/aceptación para "producción validada", o cada una define el suyo por separado?
  [Consistency, Spec §User Scenarios]

## Acceptance Criteria Quality

- [ ] CHK010 - ¿Es SC-002 ("mismo comportamiento observable... verificable contra la cuenta real")
  objetivamente verificable por alguien que no participó en la implementación, o requiere conocimiento
  tácito de qué comparar? [Measurability, Spec §SC-002]
- [ ] CHK011 - ¿Es SC-005 ("ningún flujo de negocio depende de admin-mcp.tiendanube.com... verificable por
  inspección de código") suficientemente específico sobre qué constituye una "dependencia" (import directo,
  referencia en config, mención en comentario)? [Measurability, Spec §SC-005]

## Edge Case Coverage

- [x] CHK012 - ¿Contempla la spec qué pasa si, durante la ventana entre migrar la configuración y retirar
  `tn_configuracion`, alguien edita un valor de configuración (ej. cambia el depósito) desde la pantalla
  vieja en vez de la nueva? [Gap, Edge Case] — Resuelto: spec.md Assumptions exige desplegar la migración de
  datos y el cambio de pantalla en el mismo cambio, eliminando la ventana.
- [x] CHK013 - ¿Está definido qué pasa si la migración de datos encuentra `tn_conexion_rest` sin fila
  todavía (nunca se corrió `actual()`) al momento de copiar los valores? [Gap, Edge Case] — Resuelto:
  data-model.md §1 especifica que la migración crea la fila si no existe.
- [x] CHK014 - ¿Contempla la spec un escenario de reversión si, ya con el MCP retirado (Historia 3), se
  detecta una regresión en producción — o asume implícitamente que no hace falta plan de vuelta atrás?
  [Gap, Spec §Assumptions] — Resuelto: spec.md Assumptions exige backup de base de datos antes de la
  migración destructiva, como vía de recuperación (sin reversión automática de un `DROP TABLE`).

## Dependencies & Assumptions

- [ ] CHK015 - ¿Está validada (o al menos declarada como pendiente de validar) la asunción de que el
  volumen de catálogo/pedidos de Tiendanube no requiere el modo `scan` que sí necesitó Mercado Libre, antes
  de comprometerse a un paginado simple? [Assumption, Spec §Assumptions]
- [ ] CHK016 - ¿Documenta la spec la dependencia entre la Historia 3 (retiro MCP) y un período de
  validación en producción de las Historias 1 y 2, con suficiente precisión como para que dos personas
  distintas coincidan en cuándo ese período terminó? [Dependency, Spec §Assumptions]

## Notes

- Este checklist se concentra deliberadamente en la parte nueva/riesgosa de la spec (migración de
  configuración + retiro del MCP) — no repite validaciones genéricas ya cubiertas por
  `checklists/requirements.md` (spec-kit, generado en `/speckit-specify`).
- Items marcados `[Gap]` señalan requisitos ausentes que corresponde resolver actualizando spec.md/plan.md
  antes de `/speckit-tasks`, no durante la implementación.
