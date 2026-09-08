# Specification Quality Checklist: Reconocedor de CUIT por ARCA en Proveedores

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-07
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

1. **Nombres de clases y servicios en los FR**: la redacción inicial de FR-001/FR-004 nombraba
   `ws_sr_padron_a13` y `ws_sr_constancia_inscripcion`. Se reescribieron en términos de negocio
   ("el padrón de ARCA", "aunque provenga de una fuente distinta a la de los datos de identidad"),
   dejando los nombres técnicos para el plan. La sección Contexto sí los menciona como referencia
   histórica de specs previas, lo cual es trazabilidad, no diseño.

2. **Contradicción potencial con el Principio III de la constitución**: ese principio prohíbe elegir
   el tipo de comprobante "a mano" salteando la derivación por condición de IVA. FR-015/FR-016
   permiten exactamente eso en Proveedor. Se resolvió explícitamente en Assumptions: el Principio III
   regula el comprobante que el negocio **emite** (una decisión fiscal propia), mientras que el de
   Proveedor es el que un tercero **nos emite** (un dato informado que el usuario transcribe). No hay
   contradicción, pero quedó documentada para que `/speckit-analyze` no la marque como inconsistencia
   silenciosa.

3. **"Factura E" sin cubrir**: el selector de Proveedor ofrece A/B/C/E pero la regla acordada sólo
   produce A/B/C. Se documentó como supuesto explícito (E se elige a mano, no se deduce de la
   condición de IVA) en lugar de dejar el hueco implícito.

### Iteración 2 — `/speckit-clarify` (2026-09-07)

4. **Conflicto entre US1-esc.3 y FR-009 al editar**: la spec decía que "Verificar" *refresca* los
   campos al editar, mientras FR-009 prometía no sobrescribir lo editado manualmente. Al editar, los
   campos precargados desde la base no fueron tocados en esa sesión, así que el padrón los pisaba —
   contradicción real, no de redacción. Se consultó al usuario y se resolvió por **paridad con
   Cliente** (el padrón sobrescribe). Se precisó FR-010, se reescribió US1-esc.3 y se agregó el edge
   case que deja el riesgo aceptado a la vista.

### Pendiente para `/speckit-plan`

- Evaluar la extracción de la consulta al padrón a un servicio compartido: hoy la lógica está
  duplicada en `ClienteController`, `DerivadorComprobante` (Mercado Libre) y `ResolutorCliente`
  (Tiendanube); Proveedor sería la cuarta copia. Es una decisión de diseño, correctamente ausente de
  la spec.
- FR-018 y SC-005 exigen no regresión en Cliente: el plan debe identificar qué cobertura existente
  la respalda.

### Actualización de documentación de dominio (Principio I)

Antes de `/speckit-tasks` corresponde corregir `docs/documentacion_principal_crm.md` §2.3, que hoy
afirma que Proveedores "reutiliza la misma validación de CUIT" que Cliente — afirmación que creó la
expectativa incumplida que originó este reporte. Debe distinguir el dígito verificador (sí
compartido) de la consulta al padrón (no implementada hasta esta spec) y documentar la regla A/C/B
propia de Proveedor.
