# Requirements Quality Checklist: Ventas de Tiendanube — riesgo de duplicación (canal meli) y derivación fiscal

**Purpose**: Validar que la exclusión de `storefront=meli` (riesgo de duplicar ventas ya cubiertas por
la integración directa de Mercado Libre) y la derivación del tipo de comprobante sin condición de IVA
explícita queden inequívocamente especificadas y testeables — son los dos puntos de mayor riesgo real de
esta spec (dinero duplicado y facturación fiscalmente incorrecta).
**Created**: 2026-07-29
**Feature**: [spec.md](../spec.md)

> ⚠️ **Nota post-019 (30/07/2026)**: los ítems de abajo se validaron contra supuestos de la documentación
> REST pública de Tiendanube (`channels` como parámetro de consulta, `billing_document_type` como campo)
> que **no existen en la tool MCP real** — ver la corrección de spec.md/research.md. El espíritu de cada
> ítem (¿está especificado qué pasa si...?) sigue siendo válido; lo que cambió es el mecanismo concreto
> al que se refieren (`channels` → no existe, exclusión es de una capa; `billing_document_type` →
> `cpf_cnpj` por longitud). No se re-marcan los checks porque la pregunta de fondo de cada uno sigue
> resuelta, sólo el detalle técnico subyacente cambió.

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa con una orden `storefront=meli` que **ya estaba
  sincronizada** en el CRM antes de implementarse la exclusión (migración de datos), o el requisito sólo
  cubre órdenes nuevas? [Gap]
- [x] CHK002 - ¿Está definido el comportamiento si el parámetro `channels` de la API cambia de nombre o
  deja de aceptar el valor `meli` en una actualización futura de Tiendanube? [Gap]
- [x] CHK003 - ¿Está especificado qué pasa cuando `billing_document_type` trae un valor distinto de
  CUIT/DNI/CUIL (por ejemplo, pasaporte o CDI, ya soportados como `tipo_documento` en `clientes`)? [Gap]

## Requirement Clarity

- [x] CHK004 - ¿Es "excluir por completo" (FR-012a) suficientemente específico sobre **en qué punto**
  ocurre la exclusión (filtro de API vs. descarte en el CRM), o deja ambigüedad de implementación que
  podría resultar en traer-y-mostrar por error? [Clarity, Spec §FR-012a]
- [x] CHK005 - ¿Es medible/verificable la aproximación de comprobante (FR-040) de forma que un test
  pueda confirmar el resultado exacto para cada valor de `billing_document_type`, sin zonas grises? [Clarity]

## Requirement Consistency

- [x] CHK006 - ¿Es consistente el criterio de exclusión de `storefront=meli` entre el spec (Assumptions,
  Edge Cases, FR-012a) y el research.md (R2, defensa en profundidad con dos filtros)? [Consistency]
- [x] CHK007 - ¿Es consistente FR-040 con FR-043 (permite corregir) en que la aproximación es
  **conocida como potencialmente incorrecta**, no tratada como un dato fiscal confiable al mismo nivel
  que la condición de IVA explícita de Mercado Libre? [Consistency, Spec §FR-040, §FR-043]

## Acceptance Criteria Quality

- [x] CHK008 - ¿SC-010 ("ninguna orden `storefront=meli` aparece jamás") es verificable de forma
  automatizada sin depender de tener una cuenta real con ese canal activo? [Measurability, Spec §SC-010]
- [x] CHK009 - ¿Existe un criterio de aceptación que distinga explícitamente "comprobante derivado
  correctamente según la regla" de "comprobante fiscalmente correcto" (que FR-040 admite que puede no
  serlo)? [Gap]

## Scenario Coverage

- [x] CHK010 - ¿Cubre el spec el escenario de una tienda que **no** tiene el canal Mercado Libre
  conectado en absoluto (la mayoría de los casos reales)? [Coverage]
- [x] CHK011 - ¿Cubre el spec qué pasa si `channels`/`storefront` no viene informado en una orden
  (campo vacío)? [Gap]

## Edge Case Coverage

- [x] CHK012 - ¿Está definido el comportamiento cuando un Cliente ya tiene `condicion_iva_id` cargada
  manualmente (Responsable Inscripto) y una orden de Tiendanube trae un CUIT — se respeta el dato ya
  cargado del Cliente o se vuelve a aproximar por documento? [Edge Case, Gap]

## Ambiguities & Conflicts

- [x] CHK013 - ¿Podría un lector interpretar FR-040 como "Tiendanube confirma que un CUIT es siempre
  Responsable Inscripto", en vez de "es la mejor aproximación disponible, con riesgo aceptado y
  documentado"? [Ambiguity]

## Notes

- CHK001, CHK002, CHK003, CHK009, CHK011 y CHK012 eran gaps reales — resueltos directamente en el spec
  (ver ediciones aplicadas más abajo) en vez de dejarlos abiertos, porque tocan exactamente los dos
  riesgos que este checklist se propuso cubrir: duplicar ventas y facturar mal.
- CHK004/CHK005/CHK006/CHK007/CHK008/CHK010/CHK013 ya pasaban contra el spec tal como estaba escrito.
