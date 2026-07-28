# Checklist de calidad de requisitos: integridad de importes, concurrencia, stock y cobranza

**Propósito**: validar la **calidad de la redacción de los requisitos** (completitud, claridad,
consistencia, medibilidad y cobertura) antes de implementar. No verifica comportamiento del sistema:
verifica que la spec esté bien escrita.

**Creado**: 2026-07-27 · **Feature**: [spec.md](../spec.md) · **Profundidad**: rigurosa (compuerta de
release — toca dinero, stock e impacto contable, principio IV de la constitución)

**Focos**: cálculo de importes y desagregación de IVA · idempotencia y concurrencia de la conversión ·
integridad de stock y cobranza · consistencia con el módulo Ventas existente

---

## Cálculo de importes y desagregación de IVA

- [x] CHK001 - ¿Está explícito que los importes de Mercado Libre son precios finales con IVA incluido, y no netos? [Claridad, Spec §FR-030a] → cubierto: FR-030a
- [x] CHK002 - ¿Está definida la fuente del porcentaje de IVA a usar para desagregar cada línea? [Completitud, Spec §FR-030a] → cubierto: FR-030a
- [x] CHK003 - ¿Se especifica qué ocurre cuando el producto vinculado tiene IVA "Exento" o "No Gravado", donde el coeficiente de desagregación es 1? [Caso borde, Vacío] → resuelto: FR-030b
- [x] CHK004 - ¿Está definido cómo se absorbe la diferencia por redondeo entre la suma de las líneas y el monto de la orden? [Completitud, Spec §FR-030a] → cubierto: FR-030a + data-model §8
- [x] CHK005 - ¿La regla de coincidencia exacta de totales es objetivamente medible (unidad monetaria y tolerancia)? [Medibilidad, Spec §FR-030, §SC-003] → cubierto: SC-003 + quickstart §3
- [x] CHK006 - ¿Se especifica el comportamiento cuando el monto de la orden no coincide con la suma de sus líneas por descuentos o promociones de Mercado Libre? [Cobertura, Spec §Edge Cases] → cubierto: FR-030 + Edge Cases
- [x] CHK007 - ¿Está definido qué hacer si la orden llega en una moneda distinta de la esperada? [Vacío, Caso borde] → resuelto: FR-030d
- [x] CHK008 - ¿Se especifica si el descuento general y los conceptos extra (percepciones, impuestos internos, intereses) aplican o no a las Ventas originadas en Mercado Libre? [Vacío, Consistencia con Spec §3.2 del dominio] → resuelto: FR-030c

## Idempotencia y concurrencia de la conversión

- [x] CHK009 - ¿Está definida la clave de identidad que garantiza que una orden no se duplique? [Completitud, Spec §FR-032] → cubierto: FR-032 + data-model §2 (unique ml_order_id)
- [x] CHK010 - ¿Se distingue explícitamente entre la verificación previa y el bloqueo exclusivo, aclarando por qué la primera no alcanza? [Claridad, Spec §FR-032a] → cubierto: FR-032a
- [x] CHK011 - ¿Está especificado el alcance de cada bloqueo (global de sincronización vs. por orden individual)? [Claridad, Spec §FR-014, §FR-032a] → cubierto: research §R6
- [x] CHK012 - ¿Se define el comportamiento esperado del proceso que pierde la carrera (mensaje y código de resultado)? [Completitud, Spec §FR-032a] → cubierto: contracts §1 (409)
- [x] CHK013 - ¿El criterio de éxito de concurrencia especifica el número de intentos simultáneos y los tres artefactos que no deben duplicarse? [Medibilidad, Spec §SC-004a] → cubierto: SC-004a
- [x] CHK014 - ¿Está definido qué ocurre si el bloqueo expira antes de que termine la conversión (operación más lenta que el tiempo de retención del candado)? [Caso borde, Vacío] → resuelto: FR-032b
- [x] CHK015 - ¿Se especifica el comportamiento cuando dos sincronizaciones programadas se solapan por ejecución lenta? [Cobertura, Spec §FR-014] → cubierto: FR-014
- [x] CHK016 - ¿Están definidos los requisitos de idempotencia de la sincronización de forma independiente de los de la conversión? [Consistencia, Spec §FR-013, §FR-032] → cubierto: FR-013 vs FR-032

## Integridad de stock

- [x] CHK017 - ¿Está explícitamente reconocido en la spec que el comportamiento de stock que FR-046 toma como referencia ("igual que cualquier otra Venta") no existe hoy? [Conflicto, Spec §FR-046 vs. research §R1] → resuelto: research §R1 + plan Constitution Check
- [x] CHK018 - ¿Está definido el criterio para elegir el depósito cuando la configuración de Mercado Libre no tiene uno seteado? [Completitud, Spec §FR-047] → cubierto: FR-047
- [x] CHK019 - ¿Se especifica qué tipos de ítem NO mueven stock (Servicios, ítems libres sin producto)? [Cobertura, data-model §7] → resuelto: FR-046a
- [x] CHK020 - ¿Está definido el comportamiento de stock al **editar** una Venta ya creada (reintegro y reaplicación)? [Vacío, Spec §FR-034] → resuelto: FR-046b
- [x] CHK021 - ¿Está definido el comportamiento de stock al **eliminar** una Venta originada en Mercado Libre? [Vacío, Cobertura] → resuelto: FR-046b
- [x] CHK022 - ¿Se especifica el resultado cuando el stock del depósito es insuficiente, dado que la venta ya ocurrió y no puede rechazarse? [Caso borde, Spec §Edge Cases] → resuelto: FR-046d
- [x] CHK023 - ¿Está definido explícitamente que la atomicidad abarca Venta, cobranza y movimiento de stock como una sola unidad? [Claridad, Spec §FR-048] → cubierto: FR-048
- [x] CHK024 - ¿Se especifica qué pasa con el stock cuando una orden ya convertida se cancela después en Mercado Libre? [Cobertura, Spec §FR-058] → resuelto: FR-046e
- [x] CHK025 - ¿Están documentados los requisitos de trazabilidad del movimiento de stock hacia su Venta de origen? [Completitud, Vacío] → resuelto: FR-046c

## Integridad de la cobranza

- [x] CHK026 - ¿Está definido el monto exacto de la cobranza automática (total de la Venta, no parcial)? [Claridad, Spec §FR-044] → cubierto: data-model §8
- [x] CHK027 - ¿Está especificada la fecha a usar en la cobranza generada? [Completitud, Spec §FR-044, §Assumptions] → cubierto: data-model §8 + Assumptions
- [x] CHK028 - ¿Se define el comportamiento cuando la cuenta de Tesorería de Mercado Pago no existe o está inactiva? [Vacío, Caso borde, Spec §FR-045] → resuelto: FR-045a
- [x] CHK029 - ¿Está definido si la cobranza automática debe generar movimiento de tesorería, o sólo el registro de cobro? [Claridad, Spec §FR-045] → cubierto: FR-045 + data-model §8
- [x] CHK030 - ¿Se especifica el tratamiento de la comisión de Mercado Libre de forma que quede claro que el monto cobrado es bruto y no neto? [Claridad, Spec §FR-049] → cubierto: FR-049 + FR-049a
- [x] CHK031 - ¿Está documentada la consecuencia de registrar el bruto sobre la conciliación real de la cuenta de Mercado Pago? [Vacío, Suposición] → resuelto: FR-049a

## Consistencia con el módulo Ventas existente

- [x] CHK032 - ¿Los requisitos garantizan que una Venta originada en Mercado Libre sea indistinguible de una manual en su ciclo de vida posterior? [Consistencia, Spec §FR-034] → cubierto: FR-034
- [x] CHK033 - ¿Está definido cómo se identifica el origen "Mercado Libre" sin romper la semántica existente de "Creada Desde" (Presupuesto / venta directa)? [Consistencia, Spec §FR-035] → cubierto: FR-035 + data-model §6
- [x] CHK034 - ¿Se especifica que la numeración de comprobante sigue la correlativa existente y no una serie separada? [Claridad, Spec §Assumptions] → cubierto: Assumptions
- [x] CHK035 - ¿Están definidos los campos de Venta que deliberadamente quedan vacíos (vendedor, lista de precios) y por qué? [Completitud, Spec §Assumptions] → cubierto: Assumptions
- [x] CHK036 - ¿Los requisitos de derivación del comprobante son consistentes con la regla del principio III de la constitución? [Consistencia, Spec §FR-039] → cubierto: FR-039 + FR-040a
- [x] CHK037 - ¿Está definido el comportamiento cuando el Cliente emparejado ya tiene una condición de IVA distinta de la que informa Mercado Libre? [Conflicto, Vacío, Spec §FR-041] → resuelto: FR-041a

## Cobertura de escenarios y suposiciones

- [x] CHK038 - ¿Están cubiertos los requisitos de recuperación ante fallo parcial de la conversión automática? [Cobertura, Flujo de excepción, Spec §FR-055] → cubierto: FR-055
- [x] CHK039 - ¿Está validada —o marcada como pendiente de validar— la suposición sobre el formato de la respuesta de Mercado Libre? [Suposición, research §R2] → cubierto: research §R2 + T024
- [x] CHK040 - ¿Está documentado el riesgo de que las órdenes de prueba generen Ventas y movimientos de stock reales con la creación automática activa? [Suposición, Spec §Assumptions] → cubierto: Assumptions
- [x] CHK041 - ¿Los requisitos de bloqueo por modo sólo lectura distinguen que la sincronización es una lectura y por lo tanto el kill-switch no la bloquearía por sí solo? [Ambigüedad, Spec §FR-017 vs. research §R10] → cubierto: research §R10
- [x] CHK042 - ¿Está definida la relación de dependencia con la spec 013 de forma que el riesgo de sobreventa quede explícito y acotado en el tiempo? [Dependencia, Spec §FR-060] → cubierto: FR-060 + Dependencies

---

## Resultado (27/07/2026)

**42/42 satisfechos.** De los 42 ítems, **14 detectaron huecos reales** que se cerraron agregando
requisitos a la spec; los otros 28 ya estaban cubiertos por la redacción original.

Requisitos agregados a raíz de este checklist: FR-030b (IVA exento), FR-030c (sin descuentos ni
conceptos extra), FR-030d (moneda distinta), FR-032b (unicidad como respaldo del candado), FR-041a (no
sobrescribir datos fiscales), FR-045a (cuenta Mercado Pago ausente), FR-046a-e (alcance, edición,
borrado, trazabilidad, stock insuficiente, cancelación) y FR-049a (conciliación bruta).

Agregados luego en `/speckit-analyze`: FR-040a (persistir "Consumidor Final" explícitamente, por el
principio III) y la aclaración de FR-046d sobre la función "Ventas sin stock" no construida.

## Notas

- Los ítems marcados `[Vacío]` señalan requisitos **ausentes** que conviene agregar antes de implementar.
- Los marcados `[Conflicto]` señalan contradicciones entre partes de la spec o con el estado real del código.
- Este checklist valida la **redacción de los requisitos**, no el comportamiento del sistema. La
  validación funcional está en [quickstart.md](../quickstart.md).
