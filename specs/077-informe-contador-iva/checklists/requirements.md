# Specification Quality Checklist: Información para tu Contador (Libro IVA Ventas / Compras)

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-24
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

### Iteración 1 — hallazgos corregidos

1. **Fuga de implementación en los FR**: la redacción inicial de varios FR nombraba tablas y columnas
   concretas (`comprobantes_fiscales.estado`, `compras.mes_imputacion_iva`, `cuentas_tesoreria`). Se
   reescribieron en lenguaje de negocio ("validación fiscal firme", "mes de imputación de IVA Compras",
   "cuenta de tesorería usada en los cobros"), y el detalle técnico se movió a Assumptions y a
   Dependencias, que es donde corresponde antes del plan.
2. **Success criteria con métricas técnicas**: se reemplazaron los umbrales de tiempo de respuesta por
   resultados observables por el usuario (SC-005 "sin degradación perceptible" con el volumen real del
   negocio, en lugar de un límite en milisegundos).
3. **Ambigüedad en la partición ARCA/manuales**: la captura no aclara dónde caen los comprobantes
   **rechazados**. Se resolvió explícitamente en FR-016 y FR-017 (caen en "manuales") y se justificó en
   Assumptions: es la única lectura que hace que las dos casillas cubran el universo sin solapamiento,
   lo que convierte a SC-004 en verificable.

### Decisiones de alcance confirmadas con el usuario antes de especificar

- **"Exportar IVA Digital"**: fuera de alcance, documentado como brecha (requiere el diseño de registro
  de ARCA y merece spec propia).
- **"Enviar Info. a mi Contador"**: fuera de alcance, documentado como brecha (envío hacia afuera, se
  evalúa junto con el módulo de Notificaciones pendiente).

### Iteración 2 — hallazgos de `/speckit-clarify` (2026-08-24)

Las cinco ambigüedades se resolvieron **contra las capturas**, sin consultar al usuario. Dos de ellas
eran defectos reales de la spec:

4. **Las casillas ARCA/Manuales no van en IVA Compras** (corrección estructural, la más importante). La
   spec inicial las daba para las dos pestañas, siguiendo el texto del relevamiento ("replica exactamente
   la misma lógica"). La captura `05_iva_compras_agosto2026_datos_reales.jpg` muestra la tabla pegada a
   la barra de totales, **sin casillas**. Ante conflicto entre el texto del informe y la captura, manda
   la captura (regla de oro de `CLAUDE.md`). Se agregó FR-014a y se acotó US3, FR-013 a FR-019 y SC-004.
   El dominio lo confirma: el CAE lo obtiene el emisor, y en Compras el emisor es el proveedor.
5. **La ecuación de totales de Contagram no cierra**. Verificando la aritmética de las capturas:
   - IVA Compras: `21.580.897,56 + 4.531.988,49 + 3.217.112,83 = 29.329.998,88` ✅ exacto.
   - IVA Ventas: `2.669.509,27 + 560.596,95 + 0 + 0 = 3.230.106,22`, pero la pantalla muestra
     `3.230.106,21` ❌ — **1 centavo de deriva**.

   Es decir, Contagram calcula el Total Facturado por separado y no como suma de sus componentes. Se
   decidió **divergir deliberadamente** (FR-011): definirlo como la suma exacta de los cuatro. Mismo
   criterio con el que la spec 067 corrigió el signo de las NC en vez de replicar el bug.

### Riesgo estructural anotado

- **Imp. Municipales** es la única columna de la pantalla relevada sin respaldo en el modelo de datos
  actual. Se emite en cero para no divergir estructuralmente de Contagram (principio rector de
  `CLAUDE.md`), y queda anotada como brecha en la documentación de dominio. Resuelto en clarify: la
  columna —y también Imp. Internos— **no participa** de la ecuación de totales (FR-011a), así que emitir
  cero no rompe ningún total.

### Estado

Todos los ítems siguen pasando después de clarify (16/16). La spec está lista para `/speckit-plan`.
