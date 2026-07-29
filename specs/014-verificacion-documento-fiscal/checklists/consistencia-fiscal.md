# Consistencia Fiscal Checklist: Verificación de documento fiscal (CUIT/CUIL)

**Purpose**: Validar que los requisitos sean completos, claros y consistentes entre sí — con foco en
la parte de mayor riesgo (reuso del mismo criterio de validación en los tres puntos, y su interacción
con la lógica de comprobante ya existente de spec 012), antes de pasar a `/speckit-tasks`.
**Created**: 2026-07-29
**Feature**: [spec.md](../spec.md)

**Depth**: Standard (la feature toca principio III de la constitución — corrección fiscal). **Audience**:
quien planifique/revise las tareas de implementación.

## Requirement Completeness

- [x] CHK001 - ¿Está definido qué pasa con el feedback del botón "Verificar" si el usuario cambia el
  tipo de documento (CUIT → DNI) después de haber verificado un número? [Gap] — resuelto: se agrega a
  Edge Cases (ver spec.md).
- [x] CHK002 - ¿Hay un escenario de aceptación explícito para el modal de **edición** de Cliente/
  Proveedor (no sólo alta), dado que FR-001 dice "alta y edición"? [Coverage, Spec §User Story 1] —
  resuelto: se agrega acceptance scenario 6.
- [x] CHK003 - ¿Está definido el comportamiento cuando `tipo_documento` es un valor que Mercado Libre
  puede informar pero que no es CUIT, CUIL ni DNI (ej. "CI", "Otro")? [Completeness, Spec §Edge Cases]
  — resuelto: se aclara que sólo CUIT/CUIL activan la validación, cualquier otro tipo queda sin tocar
  (ya cubierto implícitamente por el alcance de FR-005, se hace explícito).

## Requirement Consistency

- [x] CHK004 - FR-007 ("no persistir un documento inválido en el Cliente") ¿aplica sólo dentro de la
  rama de aproximación por documento (FR-005), o también cuando Mercado Libre SÍ informó condición de
  IVA (rama que hoy no valida el documento en absoluto)? Los Edge Cases dicen que esa rama "no toca el
  documento", lo que deja sin resolver si un documento inválido podría igual terminar persistido en el
  Cliente por esa vía. [Conflict, Spec §FR-007 vs §Edge Cases] — **resuelto**: se amplía FR-007 para
  que aplique sin importar qué rama derivó el comprobante (ver spec.md actualizado).
- [x] CHK005 - ¿Usan el mismo verbo/criterio FR-005 y FR-007 para describir "documento inválido"
  (mismo umbral, misma fuente), o hay margen para que se interpreten como dos chequeos distintos?
  [Consistency, Spec §FR-005, §FR-007] — resuelto: FR-008 ya fuerza "misma lógica/algoritmo" en los
  tres puntos; se referencia FR-008 explícitamente desde FR-007 tras el fix de CHK004.

## Requirement Clarity

- [ ] CHK006 - SC-001 dice que el usuario recibe feedback "sin tener que llegar a intentar guardar" —
  ¿está cuantificado el tiempo de respuesta esperado ("de inmediato"), o alcanza con la formulación
  actual (no bloqueante) para considerarlo verificable? [Ambiguity, Spec §SC-001]
- [x] CHK007 - ¿"Matemáticamente inválido/válido" está usado de forma consistente en toda la spec (spec
  vs. Edge Cases vs. Assumptions), o en algún punto se desliza hacia lenguaje de implementación
  ("algoritmo módulo 11", "dígito verificador") dentro de un requisito que debería ser agnóstico?
  [Clarity, Spec §FR-002, §FR-005] — revisado: el término técnico aparece sólo en Assumptions (donde
  es apropiado documentar el mecanismo ya existente), no dentro de los FR/SC en sí, que usan
  "matemáticamente válido/inválido" de forma consistente.

## Scenario Coverage

- [x] CHK008 - ¿Hay un escenario que cubra explícitamente que el resultado de FR-005/FR-006/FR-007 sea
  **idéntico** entre `doc_tipo = "CUIT"` y `doc_tipo = "CUIL"` (no sólo mencionado en la narrativa)?
  [Coverage, Spec §User Story 2] — ya cubierto por el acceptance scenario 4 de User Story 2.
- [x] CHK009 - ¿Está cubierto el caso "documento válido" (no sólo inválido) en la conversión automática
  de Mercado Libre, para confirmar que el comportamiento actual no se degrada? [Coverage, Regresión] —
  ya cubierto por el acceptance scenario 5 de User Story 2.

## Non-Functional / Impacto Fiscal

- [ ] CHK010 - ¿La spec deja explícito que esta feature NO introduce ninguna llamada de red nueva hacia
  un servicio externo (ARCA, padrón, o cualquier otro), dado que es el punto más sensible del alcance
  ya recortado? [Clarity, Spec §FR-009]
- [ ] CHK011 - ¿Están documentadas las implicancias de que la conversión automática de Mercado Libre siga
  el camino "silencioso" (sin marcar Requiere Atención) ante un documento inválido, incluyendo por qué
  se prefirió así frente a la alternativa de bloquear? [Traceability, Spec §Assumptions] — la decisión
  y su razón están en el historial de la conversación pero no quedaron como una nota explícita dentro
  de la spec; ver acción de seguimiento más abajo.

## Dependencies & Assumptions

- [x] CHK012 - ¿Está identificada la dependencia de que `App\Rules\CuitValido` no cambie de forma
  incompatible durante esta feature (ya que los tres puntos dependen de su comportamiento actual)?
  [Assumption, Spec §Assumptions] — cubierto por la primera assumption.
- [x] CHK013 - ¿Está identificado que el campo `cuit` en Cliente/Proveedor ya normaliza guiones antes de
  persistir (dependencia implícita del auto-formato con FR-003)? [Dependency] — documentado en
  research.md R3; se referencia desde Assumptions para que quede trazable también desde spec.md.

## Notes

- CHK004 fue el hallazgo más importante: una contradicción real entre FR-007 y la nota de Edge Cases
  sobre qué rama "toca" el documento. Se corrigió en `spec.md` (ver historial) ampliando FR-007 para
  que sea incondicional — el Cliente automático nunca debe quedar con un CUIT/CUIL inválido, sin
  importar qué rama de `DerivadorComprobante` se haya usado.
- CHK006, CHK010 y CHK011 quedan abiertos: no bloquean el paso a `/speckit-plan` (ya hecho) ni a
  `/speckit-tasks`, pero conviene resolverlos en la propia redacción de tasks.md (CHK010/CHK011 son
  aclaraciones de una sola línea; CHK006 es una decisión de producto menor sobre si vale la pena
  cuantificar "inmediato" con un número o dejarlo como está).
