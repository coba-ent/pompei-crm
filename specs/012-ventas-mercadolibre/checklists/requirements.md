# Specification Quality Checklist: Ventas de Mercado Libre

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-07-27
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

## Validación realizada

**Iteración 1 (2026-07-27)** — se detectaron y corrigieron los siguientes puntos antes de dar el
checklist por aprobado:

1. **Filtración de detalle técnico**: la redacción inicial nombraba endpoints y recursos concretos de
   la API de Mercado Libre. Se reemplazaron por descripciones funcionales ("los datos fiscales que
   expone Mercado Libre en la orden") para que la spec siga siendo legible por un stakeholder no
   técnico y no se ate a una versión de la API.
2. **Criterios de éxito no medibles**: se reformularon los criterios que decían "funciona
   correctamente" a métricas verificables (coincidencia exacta de importes, porcentajes, tiempos).
3. **Ambigüedad en el emparejamiento del comprador**: la versión inicial no decía qué hacer si dos
   Clientes comparten el mismo "Apodo ML". Se resolvió explícitamente en FR-038 (tratarlo como
   ambiguo y requerir intervención, nunca elegir arbitrariamente), coherente con el criterio de
   "nunca crear datos incompletos" que el usuario eligió para el mapeo.
4. **Supuesto de IVA no explicitado**: se agregó a Assumptions el supuesto de que los precios de
   Mercado Libre son finales con IVA incluido, por ser una decisión que afecta el cálculo de todas
   las líneas y que conviene que el usuario valide.

## Notas y riesgos registrados

- **Riesgo de sobreventa**: la spec deja explícitamente abierto el flujo de stock hacia Mercado Libre
  (spec 013). Está documentado en la sección de Alcance, en FR-060 y en Dependencies. No es una
  omisión, es una separación de alcance deliberada acordada con el usuario.
- **Órdenes de prueba tratadas como reales**: riesgo aceptado explícitamente por el usuario y
  documentado en Assumptions. Con la creación automática activa se generarán Ventas reales durante
  las pruebas.
- **Relación uno a uno publicación↔producto**: limitación aceptada por el usuario. Si el negocio
  publicara el mismo artículo dos veces, requeriría migración del modelo.
- **Supuesto de IVA incluido**: conviene confirmarlo con el usuario antes de `/speckit-plan`, ya que
  determina cómo se desagregan todas las líneas de la Venta.

## Estado

✅ **Aprobado** — la spec está lista para `/speckit-clarify` (opcional) o `/speckit-plan`.
