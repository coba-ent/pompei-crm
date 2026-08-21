# Checklist de requisitos: riesgo de dinero

**Verificado**: 21/08/2026, al terminar la implementación (T039). Los 38 ítems se contrastaron
contra la spec y contra el código final. CHK021 (deuda del filtro de estado del Informe de Ventas,
que calculaba `total − cobrado` sin NC) se resolvió dentro de esta misma spec, no quedó pendiente.
CHK005 se resolvió como pedía la nota: `tests/Feature/Creditos/TesoreriaIntactaTest.php` mide los
totales antes y después y falla el build ante cualquier diferencia.

**Purpose**: Validar que los requisitos sobre saldos, Tesorería y cuenta corriente estén completos,
sin ambigüedad y sin contradicciones, **antes** de escribir una línea de código. No valida la
implementación: valida cómo están escritos los requisitos.

**Created**: 2026-08-21
**Feature**: [spec.md](../spec.md)
**Depth**: Formal (gate de release) — justificado por el principio IV de la constitución: hay dinero
de por medio y las cajas del negocio ya cuadran.

## No-impacto en Tesorería

- [x] CHK001 - ¿Está definido de forma inequívoca qué significa "no tocar Tesorería", enumerando los totales concretos que deben quedar idénticos? [Clarity, Spec §FR-018, §SC-003]
- [x] CHK002 - ¿Los requisitos especifican que la aplicación de crédito NO debe pasar por el circuito que genera movimientos de tesorería, y no sólo que "no debe afectarlos"? [Completeness, Plan §Riesgo principal]
- [x] CHK003 - ¿Está especificado que el medio "Saldo a favor" no debe existir como cuenta de tesorería en ninguna pantalla ni sumar a ningún bloque de saldos? [Coverage, Spec §FR-019]
- [x] CHK004 - ¿El requisito de invariancia cubre también la **anulación** de una aplicación, o sólo su creación? [Gap, Spec §FR-011]
- [x] CHK005 - ¿Se especifica qué debe pasar si un futuro cambio hiciera que aplicar crédito generara un movimiento de tesorería (¿falla el test, se bloquea el deploy)? [Gap, Measurability]

## Cuenta corriente y doble conteo

- [x] CHK006 - ¿Está explícito que el saldo de cuenta corriente del cliente debe ser idéntico antes y después de aplicar crédito? [Completeness, Spec §FR-003a, §SC-001a]
- [x] CHK007 - ¿Los requisitos definen el efecto de la aplicación sobre el comprobante de **origen**, y no sólo sobre el destino? [Completeness, Spec §FR-003a]
- [x] CHK008 - ¿Está documentado con números el escenario de doble conteo que se busca evitar, de modo que un tercero pueda reconocerlo si reaparece? [Traceability, Research §Decisión 3]
- [x] CHK009 - ¿Se especifica que el crédito de un cliente no puede aplicarse a comprobantes de otro cliente, y lo mismo para proveedores? [Coverage, Spec §Edge Cases]
- [x] CHK010 - ¿Está definido que los créditos de clientes y de proveedores no se compensan entre sí? [Consistency, Spec §Assumptions]

## Definición del crédito disponible

- [x] CHK011 - ¿La definición de "crédito disponible" es calculable sin ambigüedad a partir de datos existentes, sin depender de interpretación? [Measurability, Spec §FR-001]
- [x] CHK012 - ¿Está especificado explícitamente que una NC sobre un comprobante impago NO genera crédito? [Clarity, Spec §FR-001, Research §Decisión 2]
- [x] CHK013 - ¿Se distingue el crédito originado en Notas de Crédito del saldo a favor originado en otras causas (cobro de más, saldo inicial)? [Clarity, Spec §Assumptions]
- [x] CHK014 - ¿Está definido el orden de consumo cuando hay varios comprobantes con crédito? [Completeness, Spec §FR-008]
- [x] CHK015 - ¿Se especifica qué pasa cuando el crédito debe cubrirse con más de un comprobante de origen a la vez? [Coverage, Contract §2]
- [x] CHK016 - ¿Está definido si el crédito caduca por el paso del tiempo? [Assumption, Spec §Assumptions]

## Consistencia de la fórmula de saldo

- [x] CHK017 - ¿Están enumerados **todos** los lugares que replican la fórmula de saldo y que deben actualizarse juntos? [Completeness, Data-model §Propagación]
- [x] CHK018 - ¿El requisito exige tratar esa actualización como un cambio atómico, en vez de permitir que un lugar quede con la fórmula vieja? [Clarity, Plan §Riesgo principal]
- [x] CHK019 - ¿Se documenta el precedente de divergencia entre el cálculo mostrado y el usado para filtrar, como antecedente del riesgo? [Traceability, Plan §Riesgo principal]
- [x] CHK020 - ¿Está especificado que los saldos siguen siendo derivados y nunca almacenados? [Consistency, Data-model §Entidades existentes]
- [x] CHK021 - ¿Se define el comportamiento esperado de los informes de Ventas y Compras, cuyo filtro de estado hoy no contempla siquiera las NC? [Gap, Data-model §Propagación]

## Topes, límites y validación

- [x] CHK022 - ¿Está definido con precisión el tope del importe aplicable (mínimo entre crédito disponible y saldo pendiente)? [Clarity, Spec §FR-007]
- [x] CHK023 - ¿Se especifican los mensajes de error para cada motivo de rechazo, de modo que sean distinguibles entre sí? [Completeness, Contract §2]
- [x] CHK024 - ¿Está definido el comportamiento cuando el comprobante destino ya está saldado? [Edge Case, Spec §Edge Cases]
- [x] CHK025 - ¿Se especifica la prohibición de aplicar el crédito de un comprobante sobre sí mismo? [Coverage, Spec §FR-009a]
- [x] CHK026 - ¿Están definidos los requisitos de concurrencia con un criterio verificable (el disponible nunca negativo)? [Measurability, Spec §FR-013]

## Trazabilidad y auditoría

- [x] CHK027 - ¿Los requisitos garantizan que, dado un crédito aplicado, se puede reconstruir de qué NC salió y a qué comprobante fue? [Completeness, Spec §FR-009, §SC-004]
- [x] CHK028 - ¿Está especificado que las aplicaciones no se borran físicamente, por trazabilidad contable? [Consistency, Constitución III, Data-model]
- [x] CHK029 - ¿Se define qué pasa al intentar anular una Nota de Crédito con crédito ya aplicado? [Coverage, Spec §FR-012]
- [x] CHK030 - ¿Está definido que el crédito aplicado no debe sumarse a "Cobrado" como si fuera dinero recibido? [Clarity, Contract §4]
- [x] CHK031 - ¿Se registra quién aplicó el crédito, para auditoría? [Completeness, Data-model]

## Compatibilidad con lo existente

- [x] CHK032 - ¿Está especificado que el circuito de cobranzas con dinero no cambia en absoluto? [Consistency, Spec §FR-021]
- [x] CHK033 - ¿Se define que la pantalla debe verse igual que hoy cuando el cliente no tiene crédito? [Clarity, Spec §FR-006, Quickstart §Escenario 3]
- [x] CHK034 - ¿Está explícito que se mantiene la posibilidad de emitir NC mayores al comprobante? [Consistency, Spec §FR-020]
- [x] CHK035 - ¿Se especifica que no se migran ni reconstruyen datos históricos, y qué pasa con los créditos ya perdidos? [Assumption, Spec §Assumptions]

## Dependencias y supuestos operativos

- [x] CHK036 - ¿Está documentado el supuesto de que la cobranza original no debe borrarse, y la necesidad de instruir al local? [Assumption, Spec §Assumptions]
- [x] CHK037 - ¿Se define el requisito de rendimiento del saldo en el selector con un límite concreto (no calcular sobre el catálogo entero)? [Measurability, Plan §Performance Goals]
- [x] CHK038 - ¿Está documentada la divergencia deliberada respecto de Contagram, con su justificación y quién la aprobó? [Traceability, Spec §Contexto, Plan §Constitution Check]

## Notas

- Ítems marcados como incompletos requieren corregir la **spec**, no el código.
- CHK021 apunta a una deuda preexistente detectada el 20/08/2026: el filtro de estado de cobro del
  Informe de Ventas usa `total − cobrado` sin NC. Esta feature agrega dos términos más a la fórmula,
  así que conviene decidir explícitamente si ese informe entra en el alcance o queda documentado como
  pendiente.
- CHK005 propone convertir la invariante de Tesorería en un test que falle el build, no en una
  verificación manual: es la única forma de que la garantía sobreviva a cambios futuros.
