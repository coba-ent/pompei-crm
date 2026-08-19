# Checklist: Calidad de requisitos — Interacción y no-regresión del buscador

**Purpose**: Validar que los requisitos de la spec (no la implementación) sean completos, claros,
consistentes y verificables, con foco en los dos riesgos centrales de esta feature: (a) que el
comportamiento de foco/panel quede especificado sin ambigüedad, y (b) que la paridad con el buscador
actual esté definida de forma objetivamente comprobable.
**Created**: 2026-08-19
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa con el foco, el panel y el texto del buscador en cada uno de los momentos del ciclo (al tipear, al elegir, después de elegir)? [Completeness, Spec §FR-001..FR-004]
- [x] CHK002 - ¿Están definidos los cuatro estados posibles del panel (buscando, con resultados, sin coincidencias, error)? [Completeness, Spec §FR-009, FR-010, FR-011]
- [x] CHK003 - ¿Está especificado qué datos exactos lleva la línea que se agrega al detalle, y que deben ser los mismos que hoy? [Completeness, Spec §FR-006]
- [x] CHK004 - ¿Están documentadas las diferencias legítimas entre las 3 pantallas (precio vs. costo, IVA condicionado al tipo de comprobante, lista de precios)? [Completeness, Spec §FR-006, data-model.md]
- [x] CHK005 - ¿Está definido el comportamiento del teclado para cada tecla relevante (↑, ↓, Enter, Escape, Tab)? [Completeness, Spec §FR-007, §Edge Cases]

## Requirement Clarity

- [x] CHK006 - ¿Es inequívoco que el foco debe quedar en el buscador y NO en el panel, y que el panel no debe capturar la tabulación? [Clarity, Spec §FR-001, contracts §1]
- [x] CHK007 - ¿Está cuantificado el "no consultar por tecla" con un criterio concreto en lugar de un adjetivo vago? [Clarity, Spec §FR-002, research.md Decisión 4]
- [x] CHK008 - ¿Está explícito el orden de las acciones al elegir una opción (agregar línea, cerrar panel, vaciar texto, conservar foco), y no sólo el conjunto? [Clarity, Spec §FR-003, contracts §2]
- [x] CHK009 - ¿Queda claro que Escape conserva el texto tipeado mientras que elegir una opción lo borra? [Clarity, Spec §FR-003 vs. §FR-007]

## Requirement Consistency

- [x] CHK010 - ¿Es consistente el alcance entre la spec (3 buscadores de producto) y lo que el plan lista como archivos a tocar? [Consistency, Spec §FR-013, plan.md §Project Structure]
- [x] CHK011 - ¿La exclusión de "Crear Producto" del alcance es consistente con lo que la spec afirma que el buscador hace hoy, sin contradecirse entre secciones? [Consistency, Spec §Alcance vs. §Fuera de alcance]
- [x] CHK012 - ¿El requisito de comportamiento idéntico en las 3 pantallas (FR-014) es compatible con el requisito de que cada pantalla arme su línea distinta (FR-006), sin que uno anule al otro? [Consistency, Spec §FR-006 vs. §FR-014]

## Acceptance Criteria Quality / Measurability

- [x] CHK013 - ¿El criterio de paridad de búsqueda es objetivamente comprobable (conjunto y orden de resultados) en lugar de una apreciación subjetiva? [Measurability, Spec §SC-003]
- [x] CHK014 - ¿El criterio de paridad de la línea agregada enumera los campos concretos a comparar? [Measurability, Spec §SC-004, quickstart.md Escenario 2]
- [x] CHK015 - ¿El criterio de rendimiento está expresado como una comparación contra el estado actual, y no como un número inventado sin línea base? [Measurability, Spec §SC-005]
- [x] CHK016 - ¿El criterio de "no cambió nada más" (SC-006) tiene una forma concreta de verificarse, o es una afirmación no comprobable? [Measurability, Spec §SC-006, quickstart.md Escenario 5]

## Scenario Coverage

- [x] CHK017 - ¿Hay escenarios que cubran la carga de varios productos consecutivos, que es el caso de uso que motiva la feature? [Coverage, Spec §US1]
- [x] CHK018 - ¿Hay escenarios que cubran las 3 pantallas y no sólo Venta? [Coverage, Spec §US1 escenario 5, §US2 escenarios 4-5]
- [x] CHK019 - ¿Hay escenarios de flujo alternativo por teclado, además del flujo con mouse? [Coverage, Spec §US3]
- [x] CHK020 - ¿Hay escenarios de excepción (error de consulta) y de recuperación (reintento sin perder lo cargado)? [Coverage, Spec §FR-011, §Edge Cases]

## Edge Case Coverage

- [x] CHK021 - ¿Está definido qué pasa cuando llegan respuestas de búsqueda fuera de orden por tipeo rápido? [Edge Case, Spec §FR-012]
- [x] CHK022 - ¿Está definido qué pasa al presionar Enter sin ninguna opción resaltada, y esa decisión está justificada? [Edge Case, Spec §Edge Cases, research.md Decisión 5]
- [x] CHK023 - ¿Está definido el comportamiento al salir del campo con Tab o al hacer clic fuera, sin que se agregue nada por accidente? [Edge Case, Spec §FR-008, §Edge Cases]
- [x] CHK024 - ¿Está cubierto el caso de elegir dos productos muy rápido sin que se pierda ninguna línea? [Edge Case, Spec §Edge Cases]
- [x] CHK025 - ¿Está cubierto el caso del formulario en modo edición (comprobante que ya tenía líneas)? [Edge Case, Spec §Edge Cases]

## Non-Functional Requirements

- [x] CHK026 - ¿Hay requisitos de accesibilidad explícitos, y expresados como "no degradar respecto de lo actual" en lugar de omitirse? [Coverage, Spec §FR-016, contracts §8]
- [x] CHK027 - ¿Hay un requisito de coherencia visual con el resto de los controles del formulario, para que el cambio de componente no rompa la estética? [Completeness, Spec §FR-015]
- [x] CHK028 - ¿Está considerado el riesgo de inyección al mostrar nombres de producto cargados por el usuario? [Coverage, contracts §Seguridad]

## Dependencies & Assumptions

- [x] CHK029 - ¿Está documentado y justificado el supuesto de que el servicio de catálogo no se modifica, que es lo que sostiene la paridad de búsqueda? [Assumption, Spec §Assumptions, research.md Decisión 4]
- [x] CHK030 - ¿Está documentada la decisión de construir un widget propio en lugar de otra librería, con las alternativas que se descartaron y por qué? [Assumption, research.md Decisión 1]
- [x] CHK031 - ¿Está registrada la excepción a la regla del proyecto sobre el componente estándar de selects, con su justificación y su límite de alcance? [Assumption, Spec §Assumptions, plan.md §Constitution Check]
- [x] CHK032 - ¿Está identificada la documentación del proyecto que queda desactualizada por este cambio y que debe corregirse en la misma entrega? [Dependency, Spec §Assumptions, plan.md §Constitution Check]

## Ambiguities & Conflicts

- [x] CHK033 - ¿Se resolvió la contradicción entre la etiqueta del campo ("Seleccionar o Crear Producto/Servicio") y lo que el campo realmente hace, aunque sea documentándola como brecha pendiente? [Conflict, Spec §Alcance, §Fuera de alcance]
- [x] CHK034 - ¿Está claro que el menú ▾ de la fila del detalle es una parte distinta de la pantalla que no forma parte de esta feature? [Ambiguity, Spec §FR-018]

## Notes

- Todos los items pasan tras la corrección de la spec. El hallazgo más importante del relevamiento
  quedó capturado en CHK011/CHK033: la primera redacción de la spec daba por existentes en el
  buscador de productos la opción "Crear Producto" y el lápiz de editar, que en realidad sólo existen
  para el selector de Cliente. De haberse implementado así, se habría construido funcionalidad nueva
  creyendo que era no-regresión, inflando el alcance sin que nadie lo hubiera pedido.
- CHK022 y CHK005 documentan una decisión deliberada (no auto-resaltar la primera opción) que se
  aparta de cómo se comporta el componente actual: es intencional por el riesgo de cargar una línea
  equivocada en un comprobante fiscal, y está justificada en `research.md` Decisión 5.
