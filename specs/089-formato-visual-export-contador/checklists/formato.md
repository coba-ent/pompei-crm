# Formato y No-Regresión Checklist: Excel del Libro IVA

**Purpose**: Validar que los requisitos de formato son verificables contra el fixture real y que la
spec protege explícitamente el contenido ya verificado peso por peso contra Contagram (specs 077/088) —
el riesgo central de una feature de "sólo estética".
**Created**: 2026-08-28
**Feature**: [spec.md](../spec.md), [plan.md](../plan.md)

## Completitud del relevamiento de formato

- [x] CHK001 - ¿Están especificados los estilos de cada bloque del archivo (encabezado del negocio, títulos de columna, cuerpo, totales), y no sólo "que se vea mejor"? [Completeness, Spec §FR-001..FR-012]
- [x] CHK002 - ¿Está definido el formato de cada *tipo* de columna (fecha, importe, texto), en vez de un formato único para toda la tabla? [Completeness, Plan §Referencia de formato]
- [x] CHK003 - ¿Está especificado qué pasa con los totales que hoy se emiten arriba de la tabla, en vez de dejar ambiguo si conviven con los nuevos del pie? [Clarity, Spec §FR-015]
- [x] CHK004 - ¿Están cubiertos los elementos de hoja que no son celdas (grilla visible, orientación)? [Coverage, Spec §FR-016, Plan §Referencia de formato]

## Trazabilidad al fixture (nada inventado)

- [x] CHK005 - ¿Cada valor de formato del plan (color, tipografía, cuerpos, formatos numéricos) es verificable contra el fixture guardado, en vez de una elección estética propia? [Traceability, Plan §Referencia de formato]
- [x] CHK006 - ¿Está identificado qué partes del fixture NO se replican y por qué (13 columnas, carácter roto en "Razón")? [Clarity, Spec §Clarifications]
- [x] CHK007 - ¿Se declara explícitamente que sólo existe fixture de Ventas, y que el formato de Compras se aplica por analogía? [Assumption, Spec §Assumptions]

## Protección del contenido ya verificado

- [x] CHK008 - ¿Prohíbe la spec explícitamente cambiar columnas, orden o cálculo, en vez de asumir que "no se van a tocar"? [Completeness, Spec §FR-010, SC-004]
- [x] CHK009 - ¿Exige la spec/plan un test de no-regresión de contenido (mismos valores, mismas columnas, mismo orden) y no sólo tests del formato nuevo? [Coverage, Plan §Estrategia de test 1]
- [x] CHK010 - ¿Identifica el plan el mecanismo concreto por el cual esta feature podría romper números (correr filas para meter el encabezado), en vez de afirmar genéricamente que es seguro? [Clarity, Plan §Riesgos fila 1]

## Verificabilidad de los criterios

- [x] CHK011 - ¿Son los criterios de éxito verificables por observación del archivo generado, sin conocer la implementación ("se puede sumar en Excel", "se ordena cronológicamente")? [Measurability, Spec §SC-001, SC-002]
- [x] CHK012 - ¿Exige la estrategia de test leer el archivo `.xlsx` generado, en vez de verificar el array de PHP previo a escribirlo? [Measurability, Plan §Estrategia de test nota final]
- [x] CHK013 - ¿Está cubierto que el formato llega tanto a la descarga desde pantalla como al adjunto del correo? [Coverage, Spec §SC-005]

## Consistencia con convenciones ya establecidas

- [x] CHK014 - ¿Se resolvió explícitamente la tensión entre emitir fechas como valor de fecha (fixture) y la convención opuesta ya vigente en `MovimientosExport`? [Conflict, Spec §Clarifications, Plan §Decisión 1]
- [x] CHK015 - ¿Reusa el color/tipografía una convención ya presente en el proyecto en vez de introducir una paleta nueva? [Consistency, Plan §Referencia de formato nota final]

## Edge cases

- [x] CHK016 - ¿Están definidos los casos de período vacío, empresa sin datos cargados, y período sin notas de crédito? [Coverage, Edge Case, Spec §Edge Cases, §User Story 3]
- [x] CHK017 - ¿Está cubierto el defecto de codificación actual (acentos rotos) como requisito y no sólo como observación? [Completeness, Spec §FR-014]

## Notes

Todos los ítems pasan sobre spec.md/plan.md tal como quedaron tras `clarify`. El checklist se concentra
deliberadamente en dos ejes: (a) que el formato sea **trazable al fixture** y no una decisión estética
inventada, y (b) que el contenido ya verificado peso por peso quede **explícitamente protegido** — que
es donde una feature "sólo de estética" puede hacer el daño real.
