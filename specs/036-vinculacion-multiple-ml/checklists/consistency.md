# Requirements Consistency Checklist: Vinculación múltiple Producto ↔ Publicaciones (ML y Tiendanube)

**Purpose**: Validar que los requisitos de la spec (cambio de cardinalidad 1:1 → 1:N en dos integraciones)
son completos, no ambiguos y consistentes entre sí antes de implementar — foco en integridad de datos y
paridad entre Mercado Libre y Tiendanube, por ser el área de mayor riesgo de negocio (sobreventa).
**Created**: 2026-08-03
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa cuando una publicación/variante activa resuelve a un SKU que
  corresponde a un Producto ya eliminado (soft-delete o no) del CRM? [Gap] — Resuelto: nuevo Edge Case
  en spec.md, se trata igual que "SKU sin Producto correspondiente" (las queries ya excluyen soft-deleted).
- [x] CHK002 - ¿Están definidos los requisitos para el caso en que la sincronización de stock/precio se
  ejecuta con CERO vínculos para un producto (todos desvinculados)? [Coverage, Spec §Edge Cases] —
  Resuelto: nuevo Edge Case en spec.md, el `foreach` no itera nada, no es un error.
- [x] CHK003 - ¿Se especifica qué pasa si, al momento de sincronizar, uno de los múltiples vínculos de
  un producto quedó con la publicación/variante ya cerrada/eliminada del lado de Mercado Libre o
  Tiendanube (no del CRM)? [Gap] — Resuelto: nuevo Edge Case en spec.md, se trata como fallo individual
  (mismo criterio FR-007/FR-008/FR-017/FR-018), sin desvinculación automática (fuera de alcance).

## Requirement Clarity

- [x] CHK004 - ¿Está definido de forma explícita e inequívoca qué significa "de forma independiente"
  para el envío a múltiples publicaciones (FR-007/FR-008/FR-017/FR-018) — es decir, que el fallo de una
  llamada HTTP no debe abortar ni revertir las demás? [Clarity, Spec §FR-007] — Resuelto: FR-007/008/017/018
  reescritos en spec.md explicitando "llamadas HTTP separadas, sin aborto ni reversión entre ellas".
- [x] CHK005 - ¿Es medible el criterio "sin pedir confirmación manual caso por caso" (FR-003/FR-013) de
  forma que un test pueda verificarlo objetivamente (ej. contar vínculos creados vs. publicaciones
  candidatas)? [Measurability, Spec §FR-003] — Resuelto: nuevo SC-006 en spec.md con criterio objetivo
  (N publicaciones → N vínculos, sin interacción durante la corrida).

## Requirement Consistency

- [x] CHK006 - ¿Los requisitos funcionales de Mercado Libre (FR-001 a FR-008) y los de Tiendanube
  (FR-011 a FR-018) mantienen exactamente la misma estructura y nivel de exigencia entre sí, sin que
  ninguna integración quede con un requisito más laxo o más estricto que la otra sin justificación?
  [Consistency, Spec §Requirements] — Verificado: FR-001↔011, 002↔012, ..., 008↔018 son estructuralmente
  paralelos punto a punto, misma exigencia en ambas integraciones.
- [x] CHK007 - ¿Es consistente el uso del término "vínculo" a lo largo de la spec para referirse tanto a
  la relación con una publicación de ML como con una variante de Tiendanube, sin introducir sinónimos
  que generen ambigüedad en `tasks.md`/`data-model.md`? [Consistency, Terminology] — Verificado: "vínculo"
  se usa consistentemente en spec.md/data-model.md/tasks.md para ambas integraciones.

## Acceptance Criteria Quality

- [x] CHK008 - ¿SC-001 y SC-002 (100% de publicaciones/variantes vinculadas) son verificables sin
  depender de que el catálogo real de Mercado Libre/Tiendanube esté en un estado específico al momento
  de correr la verificación (es decir, son reproducibles en un entorno de test controlado)? [Measurability, Spec §SC-001] —
  Verificado: los Independent Test de User Story 1 ya especifican un catálogo de prueba controlado
  (2 publicaciones/variantes con mismo SKU), no dependen del catálogo real.
- [x] CHK009 - ¿SC-005 ("ninguna publicación/variante queda vinculada a más de un Producto") tiene un
  requisito funcional correspondiente que lo garantice a nivel de datos, no sólo de comportamiento de
  código? [Traceability, Spec §SC-005 ↔ FR-002/FR-012] — Resuelto: SC-005 en spec.md ahora referencia
  explícitamente FR-002/FR-012 y la restricción única en base de datos (research.md R1).

## Scenario Coverage

- [x] CHK010 - ¿Están cubiertos los requisitos para el escenario de concurrencia: dos ejecuciones
  simultáneas de la Vinculación Automática (ej. un cron y un click manual) intentando vincular el mismo
  SKU a la vez? [Coverage, Gap] — Resuelto: nuevo Edge Case en spec.md, la restricción única existente
  en base de datos (`ml_item_id`/`variant_id`) ya resuelve el conflicto sin lock adicional.
- [x] CHK011 - ¿Se especifica el comportamiento esperado cuando un Producto tiene vínculos simultáneos
  en Mercado Libre Y Tiendanube y sólo UNA de las dos integraciones falla al sincronizar? [Coverage,
  Spec §Edge Cases] — Ya cubierto por el Edge Case existente de fallo individual (independiente por
  integración, cada una con su propio sincronizador) + Acceptance Scenario 4 de User Story 2.

## Non-Functional Requirements

- [x] CHK012 - ¿Se documenta algún límite razonable a la cantidad de publicaciones/variantes que pueden
  vincularse a un mismo Producto, o se asume explícitamente que no hace falta uno para el volumen
  actual? [Assumption, Gap] — Resuelto: nueva Assumption en spec.md, sin límite, volumen actual no lo
  justifica.

## Dependencies & Assumptions

- [x] CHK013 - ¿La asunción de que "los sincronizadores de stock/precio ya iteran por vínculo individual
  y no requieren cambios" está validada contra AMBAS integraciones? [Assumption, research.md §R4] —
  Resuelto: se abrió y confirmó directamente `app/Services/Tiendanube/SincronizadorStock.php`, mismo
  patrón exacto que ML (`->get()` + `foreach` + envío individual). research.md R4 actualizado.
- [x] CHK014 - ¿Están documentadas las specs previas (012/013/016/017/018/021/023/024) de las que este
  feature depende, de forma que quien lo implemente sepa qué comportamiento NO debe romper? [Traceability, Spec §Assumptions] —
  Resuelto: nueva Assumption en spec.md listando 012/013/016/017/018/021/023/024/035 y qué no debe romperse.

## Ambiguities & Conflicts

- [x] CHK015 - ¿Existe alguna contradicción entre la Asunción de `spec.md` ("esta spec no unifica ni
  acopla la sincronización de ML con la de Tiendanube") y el Edge Case sobre productos vinculados
  simultáneamente a ambas integraciones? [Conflict Check, Spec §Assumptions vs §Edge Cases] — Resuelto:
  no hay contradicción, aclarado explícitamente en el Edge Case correspondiente de spec.md (independencia
  de ejecución ≠ exclusión mutua de vínculos).

## Notes

- Check items off as completed: `[x]`
- CHK013 quedó marcado como pendiente de verificación real contra el código de Tiendanube antes de
  `/speckit-analyze` — research.md lo declara como "esperable" sin haber abierto el archivo del
  sincronizador de stock de Tiendanube.
