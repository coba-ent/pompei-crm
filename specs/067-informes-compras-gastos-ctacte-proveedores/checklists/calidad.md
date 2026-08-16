# Checklist de calidad de requisitos: Módulo Informes — Tanda 1

**Purpose**: Validar la **calidad de los requisitos escritos** (completitud, claridad, consistencia,
medibilidad, cobertura) antes de implementar. No valida la implementación — eso lo hace
[quickstart.md](../quickstart.md).
**Created**: 2026-08-14
**Feature**: [spec.md](../spec.md)

**Foco pedido**: fidelidad estructural al relevamiento de Contagram · corrección de los cálculos de
dinero e impuestos · cumplimiento de las 5 reglas de diseño obligatorias de CLAUDE.md.
**Profundidad**: alta (es una feature de dinero — constitución IV).
**Audiencia**: quien implemente la spec y quien revise el PR.

---

## Fidelidad estructural al relevamiento (regla de oro del proyecto)

- [ ] CHK001 - ¿Está documentada, con su motivo, cada divergencia deliberada respecto de Contagram (no-landing de tarjetas, desglose impositivo en pantalla, doble hoja de Excel en los tres informes, tercera columna de percepciones)? [Completitud, Spec §Contexto y §Clarifications]
- [ ] CHK002 - ¿Cada pantalla especificada tiene su captura de referencia identificada en la spec, de modo que la fidelidad sea contrastable y no una interpretación? [Trazabilidad, Spec §Contexto]
- [ ] CHK003 - ¿Están enumeradas las columnas por defecto del Informe de Compras **en su orden exacto**, y no sólo como un conjunto? [Claridad, Spec §FR-013]
- [ ] CHK004 - ¿Están enumerados los 12 campos del panel de filtros de Compras con sus tipos de control y sus valores admitidos? [Completitud, Spec §FR-018, §FR-019]
- [ ] CHK005 - ¿Se especifica el comportamiento del widget "Desde - Hasta" (dos calendarios contiguos + accesos rápidos simultáneos + campos tipeables) con suficiente detalle como para contrastarlo contra la captura? [Claridad, Spec §FR-003]
- [ ] CHK006 - ¿Está definido explícitamente qué elementos de Contagram se dejan fuera a propósito y cuáles simplemente no se relevaron? [Cobertura, Spec §Out of Scope]
- [ ] CHK007 - ¿Se especifica que el informe de Cta Cte Proveedores es un **espejo estructural** del de clientes, incluyendo qué significa "espejo" en términos de columnas y tabs concretos? [Claridad, Spec §FR-028, §FR-029, §FR-034]
- [ ] CHK008 - ¿Está definido el rótulo exacto de las tres entradas nuevas del sidebar y el renombrado de la entrada existente? [Completitud, Spec §FR-001]
- [ ] CHK009 - ¿Se documenta qué pasa con las dependencias hacia módulos ya construidos (Compras, Gastos, Tesorería, Proveedores) para que ninguna pantalla se construya "simplificada" por una dependencia faltante? [Dependencias, Spec §Assumptions]

## Corrección de los cálculos de dinero

- [ ] CHK010 - ¿Está escrita la ecuación de KPIs de Compras como fórmula explícita y no como enumeración de indicadores? [Medibilidad, Spec §FR-010]
- [ ] CHK011 - ¿Se define qué cuenta exactamente "Cantidad Prod./Serv." de forma que no admita dos lecturas (suma de cantidades vs. número de líneas)? [Ambigüedad resuelta, Spec §FR-011]
- [ ] CHK012 - ¿Está especificado el comportamiento de "Compra Promedio" cuando el divisor es cero? [Caso borde, Data-model §2]
- [ ] CHK013 - ¿Se advierte explícitamente que "Total Comprobante" se repite por fila y NO debe sumarse por fila para los KPIs? [Consistencia, Data-model §2 invariante]
- [ ] CHK014 - ¿Se define el signo con el que participan Notas de Crédito y Débito en cada KPI y en cada columna? [Claridad, Spec §FR-010, §FR-016]
- [ ] CHK015 - ¿Está escrito como requisito que NC/ND usan la misma fórmula que una compra normal, sin ramas por tipo de comprobante? [Completitud, Spec §FR-016]
- [ ] CHK016 - ¿Se especifica el tratamiento de ítems con cantidad negativa (bonificación del proveedor) en los KPIs y en el conteo de unidades? [Caso borde, Spec §Edge Cases]
- [ ] CHK017 - ¿Está definida la semántica de "Costo Actual" (costo vigente, no histórico) **y** el requisito de explicarla al usuario en pantalla? [Ambigüedad resuelta, Spec §FR-012, Data-model §5]
- [ ] CHK018 - ¿Se especifica que los documentos con soft delete no aparecen ni suman? [Completitud, Spec §FR-021]
- [ ] CHK019 - ¿Está definida la invariante "suma de subtotales de Categoría = Gasto Total" como requisito verificable? [Medibilidad, Spec §FR-026]
- [ ] CHK020 - ¿Se especifica que los subtotales de Gastos se calculan sobre el conjunto filtrado completo y no sobre la página visible? [Claridad, Spec §FR-023, Plan §Riesgos]
- [ ] CHK021 - ¿Está definida la invariante de consistencia entre el tab Saldos y el tab Movimientos de proveedores? [Medibilidad, Spec §FR-036]
- [ ] CHK022 - ¿Se especifica el tratamiento de saldos negativos y de la tolerancia de cero, sin dejarlo librado a "lo que ya hace el servicio"? [Claridad, Spec §FR-031]

## Corrección impositiva (constitución III)

- [ ] CHK023 - ¿Está definida la derivación de cada columna impositiva a partir de campos existentes, sin dejar ninguna como "se calcula de algún lado"? [Completitud, Data-model §2]
- [ ] CHK024 - ¿Está escrita como requisito la invariante de que netos + IVAs + percepciones + impuestos internos reconstruyen el Total Compra? [Medibilidad, Data-model §2]
- [ ] CHK025 - ¿Están enumeradas las cinco alícuotas de IVA como columnas separadas y definido qué pasa con una alícuota no contemplada? [Cobertura, Spec §FR-014, §FR-015]
- [ ] CHK026 - ¿Está definido el criterio de clasificación de percepciones y el destino de las no clasificables, de forma que ningún importe pueda perderse? [Ambigüedad resuelta, Spec §FR-015b, Data-model §4]
- [ ] CHK027 - ¿Está definida la diferencia entre Neto Gravado, Neto No Gravado y Neto Exento en términos de un campo concreto, y no por descripción narrativa? [Claridad, Data-model §2]
- [ ] CHK028 - ¿Se documenta que el desglose impositivo se deriva sin migraciones, y qué deuda de modelo queda anotada si esa derivación resultara insuficiente? [Assumptions, Data-model §7]
- [ ] CHK029 - ¿Se especifica que los informes no emiten ningún comprobante ni alteran nada fiscal (sólo lectura), para acotar el alcance del principio III? [Claridad, Plan §Constitution Check]

## Reglas de diseño obligatorias de CLAUDE.md

- [ ] CHK030 - ¿Está expresado como requisito que toda tabla de detalle se pagina desde el servidor, incluida la del informe jerárquico de Gastos? [Completitud, Spec §FR-006, §FR-023]
- [ ] CHK031 - ¿Está expresado como requisito que ninguna interacción del módulo recarga la página, enumerando las interacciones alcanzadas? [Cobertura, Spec §FR-008, §SC-008]
- [ ] CHK032 - ¿Se especifica que toda notificación va por las alertas toast del template? [Completitud, Spec §FR-009]
- [ ] CHK033 - ¿Se especifica que el PDF se abre en el modal compartido y bajo qué única condición se admite un fallback? [Claridad, Spec §FR-042]
- [ ] CHK034 - ¿Están identificados **todos** los selects de datos dinámicos de los tres informes que deben usar buscador, y no sólo algunos? [Cobertura, Spec §FR-007]
- [ ] CHK035 - ¿Se define dónde y por cuánto tiempo persiste la preferencia de columnas del usuario? [Claridad, Spec §FR-017]
- [ ] CHK036 - ¿Está definido el requisito de una URL real por informe, sin fragmentos, coherente con la regla de navegación del proyecto? [Consistencia, Spec §FR-001]

## Cobertura de escenarios y casos borde

- [ ] CHK037 - ¿Están definidos los requisitos de estado vacío para los tres informes (KPIs en cero + mensaje explícito, no error)? [Cobertura, Spec §Edge Cases]
- [ ] CHK038 - ¿Está definido el comportamiento ante datos incompletos (compra sin ítems, gasto sin subcategoría, compra sin categoría, producto eliminado)? [Caso borde, Spec §Edge Cases]
- [ ] CHK039 - ¿Están definidos los requisitos de permisos, incluyendo el acceso directo por URL sin permiso? [Cobertura, Spec §FR-002]
- [ ] CHK040 - ¿Está definido el comportamiento ante un rango de fechas inválido (desde > hasta)? [Excepción, Contracts §5]
- [ ] CHK041 - ¿Está definido el rango con el que abre cada informe la primera vez? [Completitud, Spec §FR-004b]
- [ ] CHK042 - ¿Se especifica el comportamiento del deep-link desde el menú de fila de Compras, incluido el tab que debe quedar abierto? [Claridad, Spec §FR-038]
- [ ] CHK043 - ¿Están definidos los requisitos de exportación cuando el período es grande (límites, degradación esperada del PDF)? [Caso borde, Research §R5]
- [ ] CHK044 - ¿Se define qué pasa si "Otras Percepciones" resulta ser el caso mayoritario en datos reales (camino de salida documentado)? [Assumptions, Spec §Assumptions]

## Requisitos no funcionales

- [ ] CHK045 - ¿Están cuantificados los objetivos de rendimiento con un volumen de datos concreto y un umbral de tiempo? [Medibilidad, Spec §SC-006]
- [ ] CHK046 - ¿Está documentada la brecha de rendimiento heredada del tab Saldos, con la decisión explícita de no resolverla en esta tanda? [Assumptions, Research §R7]
- [ ] CHK047 - ¿Están definidos los requisitos de coincidencia al centavo entre pantalla, exportación y pantallas de origen? [Medibilidad, Spec §SC-004, §SC-005, §FR-043]
- [ ] CHK048 - ¿Los criterios de éxito están escritos sin nombrar tecnologías concretas? [Consistencia, Spec §Success Criteria]

## Dependencias, supuestos y conflictos

- [ ] CHK049 - ¿Está declarado como restricción dura que el servicio de Cuenta Corriente compartido no se modifica, con el motivo? [Dependencia, Research §R7, Plan §Riesgos]
- [ ] CHK050 - ¿Está documentado el supuesto de "sin migraciones" junto con la evidencia que lo respalda campo por campo? [Assumption, Data-model §1, Research §R6]
- [ ] CHK051 - ¿Se identifica el conflicto potencial entre la regla obligatoria de tablas server-side y la estructura jerárquica del Informe de Gastos, con su resolución explícita? [Conflicto resuelto, Spec §FR-023, Research §R3]
- [ ] CHK052 - ¿Está anotada la deuda técnica que la tanda hereda y la que crea, separadas entre sí? [Assumptions, Data-model §7]
- [ ] CHK053 - ¿Está documentado qué queda para la tanda 2 y la tanda 3, y por qué motivo concreto cada cosa? [Alcance, Spec §Out of Scope]
- [ ] CHK054 - ¿Se especifica la obligación de actualizar la documentación de dominio antes de cerrar la tanda, incluyendo el cierre de las brechas §4.3 y §6.4 que hoy declaran pendiente la Cta Cte de proveedores? [Trazabilidad, Plan §Constitution Check, principio I]

---

## Notas

- Los ítems marcados sin resolver requieren actualizar la **spec** (o el plan / data-model), no el
  código.
- CHK013, CHK021, CHK024 y CHK026 corresponden a invariantes de dinero: si alguno queda sin definir
  con precisión, la implementación **no debe empezar** (constitución IV).
- CHK054 es bloqueante para `/speckit-tasks` según el principio I de la constitución.
