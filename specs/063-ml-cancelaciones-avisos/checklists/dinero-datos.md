# Checklist de requisitos: corrección de datos y dinero

**Purpose**: Validar que los requisitos que tocan plata, stock y estados estén escritos de forma
completa, medible y sin contradicciones — antes de implementar
**Created**: 2026-08-12
**Feature**: [spec.md](../spec.md)

> Esto no prueba que el sistema funcione: prueba que los **requisitos estén bien escritos**. Un
> ítem sin marcar es un hueco en la spec, no un bug.

## Ninguna venta se revierte sin confirmación

- [x] CHK001 - ¿Está explícito que la detección NO modifica importes, cobros, tesorería ni stock? [Completeness, Spec §FR-003]
- [x] CHK002 - ¿Está definido quién confirma la reversión y que queda registrado? [Clarity, Spec §FR-011]
- [x] CHK003 - ¿Se especifica que una venta marcada NO queda bloqueada para operar? [Completeness, Spec §FR-008a]
- [x] CHK004 - ¿Está definido el camino alternativo a la nota de crédito (descartar el aviso)? [Coverage, Spec §FR-010]
- [x] CHK005 - ¿Se especifica qué pasa si dos personas resuelven el mismo aviso a la vez? [Edge Case, Spec §Edge Cases]

## Reversión completa y atómica

- [x] CHK006 - ¿Se especifica que la feature NO construye un circuito de reversión propio? [Completeness, Spec §FR-009a]
- [x] CHK007 - ¿Se define que el aviso se cierra solo al resolverse la venta por cualquier vía? [Completeness, Spec §FR-010a]
- [x] CHK008 - ¿Se especifica a qué depósito vuelve el stock? [Clarity, Clarifications]
- [x] CHK009 - ¿Se contempla que la venta se resuelva por fuera del aviso? [Coverage, Spec §Edge Cases]
- [x] CHK010 - ¿Se contempla que el stock destino esté en negativo al reponer? [Edge Case, Spec §Edge Cases]
- [x] CHK011 - ¿Se contempla que el producto haya sido dado de baja? [Edge Case, Spec §Edge Cases]
- [x] CHK012 - ¿Se aclara qué pasa con el cobro de una venta revertida (saldo a favor)? [Clarity, Spec §Edge Cases, §Assumptions]
- [x] CHK013 - ¿Se deja escrito cuál es la vía recomendada sin restringir la otra? [Clarity, Spec §FR-009a]
- [x] CHK014 - ¿Se aclara qué NO hay que volver a probar por estar ya cubierto? [Clarity, Quickstart §Escenario 2]

## Los tres motivos no se confunden

- [x] CHK015 - ¿Están nombrados y diferenciados los tres motivos? [Completeness, Spec §FR-004]
- [x] CHK016 - ¿Se especifica que un reembolso parcial o una mediación no revierten la venta solos? [Consistency, Spec §SC-006]
- [x] CHK017 - ¿Se define la transición cuando una mediación se resuelve como cancelación? [Coverage, Spec §US3]
- [x] CHK018 - ¿Se define el cierre automático cuando la orden vuelve a estar vigente? [Coverage, Spec §FR-006]
- [x] CHK019 - ¿Se especifica de dónde sale el estado de mediación, dado que no está en el estado de la orden? [Completeness, Spec §FR-004]
- [x] CHK020 - ¿Se define qué mostrar cuando el importe reembolsado no viene informado? [Coverage, Spec §FR-004a]

## El corte de reintentos no oculta un desfase real

- [x] CHK021 - ¿Está cuantificado el umbral de corte? [Clarity, Spec §FR-015]
- [x] CHK022 - ¿Se exige que una publicación bloqueada siga siendo visible? [Completeness, Spec §FR-014]
- [x] CHK023 - ¿Existe forma de reactivar manualmente una publicación bloqueada? [Coverage, Spec §FR-017]
- [x] CHK024 - ¿Se exige mostrar la diferencia entre el stock del CRM y el publicado? [Completeness, Spec §FR-018]
- [x] CHK025 - ¿Se distingue error transitorio de permanente con criterio explícito? [Clarity, Spec §FR-015]
- [x] CHK026 - ¿Se define qué pasa si el stock del producto cambia mientras la publicación está bloqueada? [Edge Case, Spec §Edge Cases]
- [ ] CHK027 - ¿Se especifica un límite de tiempo o revisión para un bloqueo que nadie atiende? [Gap]

## Idempotencia y consistencia del aviso

- [x] CHK028 - ¿Se exige que repetir la sincronización no duplique el aviso? [Consistency, Spec §FR-005]
- [x] CHK029 - ¿Se exige preservar la fecha de detección original? [Clarity, Spec §FR-005, §US3]
- [x] CHK030 - ¿Se excluyen las órdenes sin venta y las ventas ya eliminadas? [Coverage, Spec §FR-007]
- [x] CHK031 - ¿Se contempla la cancelación concurrente con una edición o cobro en curso? [Edge Case, Spec §Edge Cases]

## Integridad fiscal

- [x] CHK032 - ¿Se especifica qué pasa cuando la venta NO tiene comprobante fiscal emitido? [Coverage, Spec §Edge Cases]
- [x] CHK033 - ¿Se aclara que la autorización ante ARCA sigue el circuito existente? [Clarity, Spec §Assumptions]
- [x] CHK034 - ¿Se especifica que la feature no modifica el circuito fiscal ni el de NC? [Clarity, Spec §FR-012]
- [x] CHK035 - ¿Se contempla la venta con nota de crédito previa? [Edge Case, Spec §Edge Cases]

## Medición y alcance

- [x] CHK036 - ¿Los criterios de éxito son medibles sin conocer la implementación? [Measurability, Spec §SC-001..006]
- [x] CHK037 - ¿Está acotado explícitamente lo que queda fuera? [Clarity, Spec §Assumptions]
- [x] CHK038 - ¿Hay una línea de base contra la cual medir la mejora? [Measurability, Spec §SC-004]

---

## Resultado: 30/38 → **37/38** tras aplicar los fixes

La primera pasada encontró **8 huecos**. Siete se corrigieron en la spec:

| Ítem | Hueco detectado | Cómo se resolvió |
|---|---|---|
| **CHK013** | No estaba prohibido eliminar la factura. | Nuevo **FR-009a** + **SC-003a** |
| **CHK034** | No quedaba claro qué pasaba con el comprobante fiscal al revertir. | **FR-012** reescrito: la factura no se altera |
| **CHK019** | Que la mediación salga del estado del **pago** y no de la orden estaba sólo en research. | **FR-004** ampliado |
| **CHK005** | Resolución concurrente del mismo aviso. | Nuevo edge case |
| **CHK026** | Stock que cambia mientras la publicación está bloqueada. | Nuevo edge case |
| **CHK014** | "El saldo vuelve al valor anterior" sin criterio verificable. | **SC-003** reescrito |
| **CHK020** | Importe reembolsado no informado. | Nuevo **FR-004a** |

### Revisión posterior: dos recortes de alcance

El usuario corrigió el enfoque dos veces, siempre en la misma dirección — **no inventar lo que ya
existe**:

1. Primero: una factura nunca se elimina; revertir es emitir una nota de crédito.
2. Después: **tampoco hay que construir esa emisión**. Tanto la nota de crédito como la eliminación
   ya están hechas desde el principio. El aviso sólo detecta, informa y conduce a la Venta; la
   persona elige la vía.

Los ítems se reevaluaron contra ese alcance final. Varios que exigían definir la mecánica de
reversión dejaron de aplicar: esa mecánica no es parte de esta feature.

**Queda 1 sin resolver, de bajo impacto**:

- **CHK027** — no se define qué pasa con un bloqueo que nadie atiende durante mucho tiempo. Se deja
  abierto a propósito: cerrarlo bien depende del módulo de notificaciones, que está fuera de alcance.
  Mientras tanto el bloqueo es visible en pantalla, que era el objetivo.
