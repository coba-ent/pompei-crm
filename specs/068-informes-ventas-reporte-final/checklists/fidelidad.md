# Checklist de calidad de requisitos: Fidelidad, dinero y réplicas — Informes Tanda 2

**Purpose**: validar que los requisitos escritos en `spec.md` son completos, claros, consistentes y
medibles antes de implementar. Es un test unitario **de la redacción**, no de la implementación.
**Created**: 2026-08-15
**Feature**: [spec.md](../spec.md)
**Depth**: gate de release (esta feature toca cálculo de dinero — constitución IV)
**Audiencia**: autor + revisor antes de `/speckit-implement`

## Fidelidad estructural al relevamiento (regla de oro de CLAUDE.md)

- [X] CHK001 - ¿Están enumeradas, en orden, las 12 columnas de la tabla de detalle de Ventas tal como aparecen en el relevamiento? [Completeness, Spec §FR-015]
- [X] CHK002 - ¿Las columnas del **export** de Ventas están especificadas por separado de las de pantalla, dado que sus rótulos difieren (Fecha/Emisión, Result./Resultado, Total Comprobante/Total Venta)? [Clarity, Spec §FR-021]
- [X] CHK003 - ¿Están definidos los 3 bloques de KPIs con sus rótulos exactos y la fórmula visible de cada uno? [Completeness, Spec §FR-010]
- [X] CHK004 - ¿Está especificado el nombre literal de los botones de exportación ("Exportar Resumen" / "Exportar a PDF") en vez de un genérico "exportar"? [Clarity, Spec §FR-020]
- [X] CHK005 - ¿Está documentada y motivada cada divergencia respecto de Contagram (sin landing de tarjetas, doble hoja de Excel, sin pestañas de pivots), en vez de aplicarse en silencio? [Consistency, Spec §Contexto]
- [X] CHK006 - ¿Está resuelto explícitamente el conflicto del relevamiento entre "22 campos" declarados y 19 enumerados, con una decisión y una brecha documentada, en lugar de inventar los 3 faltantes? [Conflict, Spec §Clarifications]
- [X] CHK007 - ¿Está especificada la jerarquía de niveles de **cada** vista del Reporte Final por separado, dado que sólo la vista caja tiene el nivel de Cuenta de Tesorería? [Completeness, Spec §FR-032, §FR-033]
- [X] CHK008 - ¿Está definido el contenido de los banners informativos de las dos vistas, incluida la diferencia sobre gastos pendientes? [Completeness, Spec §FR-031]
- [X] CHK009 - ¿Queda escrito qué elementos del relevamiento se dejan explícitamente fuera de alcance (Rankings, Arma tu Informe, /graphs, landing) para que no se lean como omisiones? [Coverage, Spec §FR-040, §FR-041]

## Corrección de los cálculos de dinero

- [X] CHK010 - ¿Está definida la unidad de fila del detalle (una por ítem) sin ambigüedad respecto de "una por comprobante"? [Ambiguity, Spec §Clarifications]
- [X] CHK011 - ¿Está especificado que "Total Comprobante" se repite por fila y por lo tanto **no** debe sumarse como KPI? [Gap, data-model.md §Invariantes]
- [X] CHK012 - ¿Está diferenciado con criterio verificable "Costo Total Actual" (costo vigente) de "CMV Total" (costo de compras), de modo que no puedan colapsarse en la misma cifra? [Clarity, Spec §FR-013, §FR-014]
- [X] CHK013 - ¿Es la definición del CMV suficientemente precisa como para implementarse sin decisiones adicionales (qué compras entran, si hay recorte temporal, qué pasa si no hay compras)? [Measurability, Spec §FR-014]
- [X] CHK014 - ¿Está definido el comportamiento de "Venta Promedio" cuando la cantidad de ventas es cero? [Edge Case, Spec §FR-012]
- [X] CHK015 - ¿Está especificado que "Cantidad Prod./Serv." es suma de cantidades y no conteo de líneas, con el motivo? [Clarity, Spec §FR-011]
- [X] CHK016 - ¿Está definido el signo con el que las Notas de Crédito y de Débito aportan al detalle y a los KPIs? [Completeness, Spec §Clarifications]
- [X] CHK017 - ¿Está requerido explícitamente que los KPIs se calculen sobre el conjunto filtrado completo y no sobre la página visible? [Completeness, Spec §FR-017]
- [X] CHK018 - ¿Está especificado el tratamiento del borrado lógico en **todos** los orígenes de datos (ventas, notas, cobros, pagos, gastos, otros ingresos)? [Coverage, Spec §FR-009]
- [X] CHK019 - ¿Está definido si "Precio Total Neto" incluye o no impuestos y descuentos, de forma que dos personas lo calculen igual? [Ambiguity, Spec §FR-016b]
- [X] CHK020 - ¿Está especificada la fecha de imputación de cada origen en la vista caja (fecha del cobro/pago) frente a la vista devengado (fecha del comprobante)? [Clarity, Spec §FR-037b]
- [X] CHK021 - ¿Existe una invariante escrita que relacione las dos vistas del Reporte Final y permita conciliarlas numéricamente? [Measurability, data-model.md §Invariantes]

## Aislamiento de las réplicas deliberadas (R1 / R2)

- [X] CHK022 - ¿Está declarado que R1 y R2 son réplicas **deliberadas** de defectos de origen, con la fuente citada, para que un revisor futuro no las corrija por error? [Traceability, Spec §Réplicas]
- [X] CHK023 - ¿Está delimitado con precisión el alcance de R1 (una celda, hoja legible, filas de NC) y afirmado que no se propaga a KPIs ni totales? [Clarity, Spec §FR-022]
- [X] CHK024 - ¿Está especificado que pantalla, PDF y hoja plana usan la fórmula correcta, de modo que la desviación no tenga más de una superficie? [Consistency, Spec §FR-016, §FR-022]
- [X] CHK025 - ¿Está especificado el comportamiento de signos **en pantalla** además del de export, dado que difieren? [Completeness, Spec §FR-035, §FR-036]
- [X] CHK026 - ¿Está desagregada la regla de signos de R2 hasta el nivel de subtotal de bloque y de línea individual, y no sólo al total? [Completeness, Spec §FR-036]
- [X] CHK027 - ¿Es R2 verificable con un criterio numérico que distinga las dos hojas sin ambigüedad? [Measurability, Spec §FR-036]
- [X] CHK028 - ¿Está resuelto qué quirks de origen **no** se replican (celdas Desde/Hasta vacías) y con qué criterio se distinguen de los que sí? [Ambiguity, Spec §Clarifications]
- [X] CHK029 - ¿Está previsto el conflicto entre estas réplicas y el principio III de la constitución, con la condición que lo resuelve? [Conflict, plan.md §Constitution Check]

## Reglas de diseño obligatorias de CLAUDE.md

- [X] CHK030 - ¿Está requerido que el detalle de Ventas sea una tabla paginada desde el servidor? [Completeness, Spec §FR-017]
- [X] CHK031 - ¿Está declarada y justificada la excepción del Reporte Final a la regla de tablas server-side, en vez de omitirla? [Conflict, plan.md §Complexity Tracking]
- [X] CHK032 - ¿Está requerido que ninguna operación recargue la página, incluidos el cambio de rango, la aplicación de filtros y el simulador? [Coverage, Spec §FR-004, §FR-034]
- [X] CHK033 - ¿Está requerido que los PDFs se visualicen en el modal compartido y no en una pestaña nueva? [Completeness, Spec §FR-005]
- [X] CHK034 - ¿Está requerido que los selects de catálogo del panel de filtros tengan buscador? [Completeness, Spec §FR-019]
- [X] CHK035 - ¿Está requerido que los avisos y errores se muestren con las notificaciones del template, sin alerts nativos? [Completeness, Spec §FR-008]
- [X] CHK036 - ¿Está especificado que cada informe tiene URL real propia, sin fragmentos `#`? [Completeness, Spec §FR-001]

## Cobertura de escenarios y bordes

- [X] CHK037 - ¿Están definidos los rótulos de fallback para registros sin categoría, sin subcategoría y sin cuenta de tesorería? [Edge Case, Spec §Edge Cases]
- [X] CHK038 - ¿Está definido el comportamiento con el período vacío en ambos informes, incluidos los archivos exportados? [Edge Case, Spec §Edge Cases, §SC-007]
- [X] CHK039 - ¿Está definido el escenario de todas las categorías destildadas en el simulador? [Edge Case, Spec §Edge Cases]
- [X] CHK040 - ¿Está especificado el efecto del simulador sobre los archivos exportados, en vez de quedar implícito? [Gap, Spec §FR-006]
- [X] CHK041 - ¿Está definido el tratamiento de ítems sin producto asociado (conceptos libres) en Costo Actual y CMV? [Edge Case, Spec §Edge Cases]
- [X] CHK042 - ¿Está definido qué comprende "mes actual" (mes calendario completo, con fechas futuras) para que el rango por defecto no sea interpretable de dos maneras? [Ambiguity, Spec §FR-003]
- [X] CHK043 - ¿Están especificadas las reglas de combinación de filtros (AND entre campos, OR dentro de un campo multi-valor)? [Clarity, Spec §FR-019]
- [X] CHK044 - ¿Está definido el criterio de los filtros derivados de agregados (Estado del Cobro, Facturado, Remitos) sin dejarlos a interpretación? [Ambiguity, research.md §R7]

## Requisitos no funcionales, dependencias y supuestos

- [X] CHK045 - ¿Está cuantificado el objetivo de rendimiento con un volumen y un tiempo concretos, en vez de "rápido"? [Measurability, Spec §SC-002]
- [X] CHK046 - ¿Está especificado el requisito de que el simulador recalcule sin ida y vuelta al servidor, de forma verificable? [Measurability, Spec §SC-005]
- [X] CHK047 - ¿Está declarado el requisito de control de acceso para vistas **y** endpoints de datos y export? [Coverage, Spec §FR-007]
- [X] CHK048 - ¿Están documentados los supuestos sobre reutilización de la Tanda 1 y el riesgo de modificar piezas compartidas? [Assumption, research.md §R10]
- [X] CHK049 - ¿Está declarado que la feature es de sólo lectura y no crea ni modifica datos? [Assumption, Spec §Assumptions]
- [X] CHK050 - ¿Está registrada la obligación de actualizar la documentación de dominio con la definición de CMV y la brecha de filtros antes de implementar? [Traceability, plan.md §Constitution Check]

## Notas

- Un ítem sin marcar significa que **la spec necesita una corrección**, no que falte código.
- CHK013, CHK023 y CHK026 son los de mayor riesgo: son los tres puntos donde una redacción vaga se
  traduce directamente en números distintos a los de Contagram.
