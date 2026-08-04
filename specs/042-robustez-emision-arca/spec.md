# Feature Specification: Robustez de datos fiscales en la emisión de CAE (ARCA)

**Feature Branch**: `042-robustez-emision-arca`

**Created**: 2026-08-04

**Status**: Draft

**Input**: User description: "Agregar el campo obligatorio CondicionIVAReceptorId a la solicitud de CAE (WSFEv1) que hace EmisorComprobante::emitir(), para evitar que ARCA rechace las emisiones a partir del 01/09/2026 (hoy es opcional, pero ARCA ya avisa en cada respuesta que a partir de esa fecha lo va a exigir — ver Events.Evt code 39 en la respuesta real registrada en arca_logs_auditoria del incidente del 04/08/2026). El valor sale de la condición frente al IVA del cliente de la Venta (Responsable Inscripto, Consumidor Final, Monotributista, Exento, etc. — mapeo a los códigos que exige ARCA). Además, aprovechar la spec para hacer una revisión general del servicio EmisorComprobante buscando otros posibles motivos de rechazo o error de cálculo (alícuotas de IVA, importes, etc.) similares al que causó el incidente del 04/08/2026 (código ARCA 10051 'Los importes informados en AlicIVA no se corresponden con los porcentajes'), y corregirlos o documentarlos como validaciones preventivas antes de enviar la solicitud a ARCA. No tocar el flujo de envío manual 'Enviar a ARCA' (spec 040) ni el resto de la lógica de negocio de Ventas — el foco es la corrección/robustez de los datos que se mandan a WSFEv1 dentro de EmisorComprobante."

## Clarifications

### Session 2026-08-04

- Q: ¿Qué tolerancia de redondeo se admite entre la suma de los bloques `AlicIva` y los totales del comprobante antes de rechazar por inconsistencia (FR-003/FR-004)? → A: Tolerancia de $0.01 por comprobante — acepta diferencias de redondeo de centavos por acumulación de decimales al prorratear IVA entre alícuotas, rechaza cualquier diferencia mayor (muy por debajo de los ~$1500 de diferencia real del incidente del 04/08/2026).

## Contexto (por qué existe esta spec)

El incidente del 04/08/2026 (ver `specs/040-envio-manual-arca/spec.md`) dejó registrado en
`arca_logs_auditoria` (id=1) el rechazo real de ARCA:

- **Código 10051**: "Los importes informados en AlicIVA no se corresponden con los porcentajes." La
  solicitud mandó `AlicIva.Id=5` (21%) con `BaseImp=149985.26` e `Importe=33154.64` — un cociente de
  ~22,1%, no 21%. Causa raíz identificada en `app/Services/Arca/MapeadorComprobante.php`: el mapeador
  arma un **único** bloque `AlicIva` con `Id` fijo por defecto en `5` (21%) y el IVA/neto totales de la
  Venta completa, sin verificar que ese total realmente corresponda a esa alícuota — y sin soportar
  Ventas cuyos ítems (`VentaItem.iva_pct`) tengan alícuotas **distintas entre sí** (ARCA exige un bloque
  `AlicIva` por cada alícuota realmente presente en el comprobante, cada uno con su propio
  `BaseImp`/`Importe` consistentes con su porcentaje).
- **Aviso (Events.Evt code 39, no es error)**: a partir del **01/09/2026** ARCA va a **rechazar**
  cualquier solicitud de CAE que no incluya `CondicionIVAReceptorId` — hoy es opcional. El sistema ya
  tiene el dato necesario: `Cliente->condicionIva->codigo_afip` (tabla `condiciones_iva`, seed
  `CondicionIvaSeeder`) usa exactamente los códigos oficiales de ARCA para "Condición IVA Receptor"
  (1=Responsable Inscripto, 4=Exento, 5=Consumidor Final, 6=Monotributista, 7=No Categorizado — RG
  5616), sólo falta que `EmisorComprobante`/`MapeadorComprobante` lo incluyan en la solicitud.

Esta spec corrige ambos puntos en el servicio de emisión (`app/Services/Arca/`), sin tocar el flujo de
envío manual "Enviar a ARCA" (spec 040) ni la lógica de negocio de Ventas/Compras en sí.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enviar comprobantes con distintas alícuotas de IVA sin que ARCA los rechace por error de cálculo (Priority: P1) 🎯 MVP

Como usuario que emite comprobantes fiscales, quiero que una Venta con ítems de distinta alícuota de
IVA (por ejemplo, algunos productos al 21% y otros al 10,5%) se envíe a ARCA correctamente desglosada
por alícuota, para que no se repita un rechazo como el del incidente del 04/08/2026 por un cálculo de
IVA que no correspondía al porcentaje declarado.

**Why this priority**: es la causa raíz concreta y ya confirmada del incidente real contra ARCA
producción — sin esto, cualquier Venta cuyo IVA total no coincida exactamente con el 21% (la única
alícuota que hoy se declara, fija, sin importar los ítems reales) sigue en riesgo de rechazo por el
mismo motivo.

**Independent Test**: crear una Venta con ítems de dos alícuotas distintas (ej. 21% y 10,5%), enviarla
a ARCA (homologación) y verificar que la solicitud desglosa un bloque `AlicIva` por cada alícuota
presente, cada uno con `BaseImp`/`Importe` consistentes con su porcentaje real — sin rechazo por código
10051.

**Acceptance Scenarios**:

1. **Given** una Venta cuyos ítems tienen todos la misma alícuota de IVA (ej. 21%), **When** se solicita
   el CAE, **Then** la solicitud declara un único bloque `AlicIva` cuyo `Id` corresponde a esa alícuota
   real (no un valor fijo asumido) y cuyo `Importe`/`BaseImp` son consistentes con el porcentaje de esa
   alícuota.
2. **Given** una Venta cuyos ítems tienen alícuotas de IVA distintas entre sí, **When** se solicita el
   CAE, **Then** la solicitud declara un bloque `AlicIva` por cada alícuota distinta presente, cada uno
   con su propio `BaseImp` (suma de los netos de los ítems de esa alícuota) e `Importe` (IVA
   correspondiente a esa alícuota), y la suma de todos los bloques coincide con `ImpNeto`/`ImpIVA`
   totales del comprobante.
3. **Given** una Venta con ítems cuya alícuota no tiene un código ARCA soportado, **When** se intenta
   emitir, **Then** el sistema rechaza el envío **antes** de contactar a ARCA con un mensaje claro
   (rechazo de precondición, mismo patrón que FR-012 de spec 040), en vez de mandar una solicitud que
   ARCA va a rechazar igual.
4. **Given** una Venta cuyo total de IVA calculado difiere en más de $0.01 (tolerancia de redondeo) de
   la suma de `Importe` de los bloques `AlicIva` armados, **When** se intenta emitir, **Then** el
   sistema rechaza el envío antes de contactar a ARCA en vez de mandar una solicitud inconsistente.
   Diferencias de hasta $0.01 (redondeo por acumulación de decimales al prorratear IVA entre
   alícuotas) se aceptan sin rechazo.

---

### User Story 2 - Informar la Condición frente al IVA del receptor en cada solicitud de CAE (Priority: P1) 🎯 MVP

Como responsable de la facturación electrónica del negocio, quiero que cada solicitud de CAE incluya la
Condición frente al IVA del cliente receptor, para que el sistema no empiece a fallar automáticamente a
partir del 01/09/2026 cuando ARCA vuelva obligatorio ese dato.

**Why this priority**: es un cambio normativo con fecha límite conocida (01/09/2026) — a diferencia de
la User Story 1 (ya causó un incidente hoy), ésta previene un corte total de la facturación electrónica
en esa fecha si no se corrige antes.

**Independent Test**: emitir un comprobante para un cliente con Condición de IVA cargada (ej.
"Consumidor Final") y verificar en `arca_logs_auditoria.payload_solicitud` que la solicitud incluye
`CondicionIVAReceptorId` con el código correcto (5 para Consumidor Final).

**Acceptance Scenarios**:

1. **Given** una Venta cuyo cliente tiene una Condición de IVA cargada, **When** se solicita el CAE,
   **Then** la solicitud a WSFEv1 incluye `CondicionIVAReceptorId` con el código ARCA correspondiente a
   esa condición (`Cliente->condicionIva->codigo_afip`).
2. **Given** una Venta cuyo cliente **no** tiene Condición de IVA cargada, **When** se intenta emitir,
   **Then** el sistema rechaza el envío antes de contactar a ARCA (rechazo de precondición, mismo
   patrón que FR-012 de spec 040) indicando que falta cargar la Condición de IVA del cliente, en vez de
   mandar una solicitud incompleta que ARCA terminará rechazando de todos modos a partir del 01/09/2026.
3. **Given** un cliente sin CUIT/DNI identificado (Consumidor Final anónimo, `DocTipo=99`), **When** se
   solicita el CAE, **Then** el sistema igual informa `CondicionIVAReceptorId` (por defecto "Consumidor
   Final", código 5, si el cliente asociado a la Venta no distingue una condición explícita distinta).

---

### Edge Cases

- ¿Qué pasa con comprobantes que no llevan IVA discriminado (ej. total exento, `ImpIVA = 0`)? No se
  agrega ningún bloque `AlicIva` — comportamiento ya existente, sin cambios (`MapeadorComprobante`
  actual ya lo contempla con `if ((float) $datos['iva'] > 0)`).
- ¿Qué pasa si dos ítems distintos de la Venta caen en la misma alícuota de IVA? Se agrupan en un único
  bloque `AlicIva` para esa alícuota (ARCA no permite bloques duplicados por el mismo `Id`).
- ¿Qué pasa con Notas de Crédito/Débito (spec 034 US3), que también arman su propia solicitud vía el
  mismo `MapeadorComprobante`? Se benefician automáticamente de esta corrección sin cambios adicionales,
  ya que comparten el mismo mapeador — fuera de alcance modificar su lógica de negocio propia (FR de
  esta spec no tocan cuándo/cómo se dispara la emisión de NC/ND, sólo cómo se arma el detalle de IVA y
  el receptor dentro de la solicitud).
- ¿Qué pasa si la alícuota de un ítem es 0% (ítem gravado a tasa cero, distinto de exento)? Se incluye
  igual como bloque `AlicIva` propio si ARCA tiene un código para 0% gravado, consistente con el resto
  de alícuotas — no se asume que "0% de IVA" implique omitir el bloque (eso es sólo para ítems fuera del
  campo de IVA, `ImpOpEx`/exento, que ya está fuera de alcance de esta spec por no haber cambiado).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE declarar en la solicitud de CAE un bloque `AlicIva` por cada alícuota de
  IVA distinta efectivamente presente en los ítems del comprobante (Venta, o comprobante ajustado por
  NC/ND), en vez de asumir siempre una única alícuota fija.
- **FR-002**: Cada bloque `AlicIva` DEBE declarar un `BaseImp` (suma de los netos de los ítems de esa
  alícuota) y un `Importe` (IVA de esa alícuota) matemáticamente consistentes con el porcentaje real de
  esa alícuota, evitando el motivo de rechazo que causó el incidente del 04/08/2026 (código ARCA 10051).
- **FR-003**: La suma de los `Importe`/`BaseImp` de todos los bloques `AlicIva` DEBE coincidir con
  `ImpIVA`/`ImpNeto` totales declarados en la solicitud del comprobante.
- **FR-004**: Si alguna alícuota presente en los ítems del comprobante no tiene un código ARCA
  soportado, o si la diferencia entre la suma de los bloques `AlicIva` y los totales
  `ImpIVA`/`ImpNeto` del comprobante supera **$0.01** (tolerancia de redondeo por acumulación de
  decimales al prorratear IVA entre alícuotas), el sistema DEBE rechazar el envío **antes** de
  contactar a ARCA (rechazo de precondición), con un mensaje que indique el motivo concreto.
- **FR-005**: El sistema DEBE incluir `CondicionIVAReceptorId` en toda solicitud de CAE, usando el
  código ARCA de la Condición de IVA del cliente asociado al comprobante
  (`Cliente->condicionIva->codigo_afip`).
- **FR-006**: Si el cliente de la Venta no tiene una Condición de IVA cargada, el sistema DEBE rechazar
  el envío antes de contactar a ARCA (rechazo de precondición), indicando que falta ese dato del
  cliente — salvo el caso de Consumidor Final anónimo (FR-007).
- **FR-007**: Para un receptor sin CUIT/DNI identificado (Consumidor Final anónimo), el sistema DEBE
  informar `CondicionIVAReceptorId` con el código de "Consumidor Final" por defecto, sin requerir que el
  cliente tenga la Condición de IVA cargada explícitamente.
- **FR-008**: Esta spec NO DEBE modificar el flujo de envío manual "Enviar a ARCA" (spec 040) —
  precondiciones ya existentes (`Venta::puedeEnviarseAArca()`, certificado fiscal activo, Función
  Avanzada) siguen igual; las nuevas precondiciones de FR-004/FR-006 se suman como validaciones
  adicionales dentro de `EmisorComprobante::emitir()`, reportadas por el mismo mecanismo de rechazo de
  precondición ya definido en spec 040 (FR-007a: toast, no modal).
- **FR-009**: Esta spec NO DEBE modificar cuándo o por qué se dispara la emisión de comprobantes
  (Ventas, Compras, NC/ND) — sólo cómo se arma el detalle de IVA y el receptor dentro de la solicitud ya
  existente.

### Key Entities *(include if feature involves data)*

- **VentaItem** (existente, sin cambios de esquema): su campo `iva_pct` es la fuente real de la(s)
  alícuota(s) de IVA de un comprobante — hoy no se usa a nivel de ítem para armar la solicitud a ARCA,
  sólo el total agregado de la Venta.
- **CondicionIva** (existente, sin cambios de esquema): su campo `codigo_afip` ya usa los códigos
  oficiales de ARCA para "Condición IVA Receptor" — pasa a usarse también en la solicitud de CAE, no
  sólo en la relación `Cliente->condicionIva` existente.
- **ComprobanteFiscal**: sin cambios de esquema — la corrección es sólo en los datos que se envían antes
  de crear el registro, no en su estructura.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 rechazos de ARCA por código 10051 ("importes informados en AlicIVA no corresponden a
  los porcentajes") en `arca_logs_auditoria` para comprobantes emitidos después de este cambio.
- **SC-002**: 100% de las solicitudes de CAE posteriores a este cambio incluyen `CondicionIVAReceptorId`
  — verificable contra `arca_logs_auditoria.payload_solicitud`.
- **SC-003**: Una Venta con ítems de alícuotas mixtas se emite correctamente en un solo intento (sin
  necesitar reintento manual por error de cálculo de IVA).
- **SC-004**: Ningún envío llega a contactar a ARCA cuando faltan datos que van a causar un rechazo
  seguro (alícuota no soportada, inconsistencia de importes, o Condición de IVA faltante) — se informan
  como rechazo de precondición antes de la llamada real a WSFEv1.

## Assumptions

- Los códigos ARCA de alícuota de IVA a mapear son los estándar vigentes (3=0%, 4=10,5%, 5=21%, 6=27%,
  8=5%, 9=2,5%) — se documentan como tabla de mapeo dentro de `MapeadorComprobante`, análoga a la que ya
  existe para `CbteTipo`.
- El campo `iva_pct` de `VentaItem` ya refleja fielmente la alícuota real de cada ítem (esta spec no
  audita ni corrige cómo se calcula/carga ese campo al crear la Venta, sólo cómo se usa al armar la
  solicitud de CAE).
- `Cliente->condicionIva->codigo_afip` (tabla `condiciones_iva`, seed `CondicionIvaSeeder`) ya usa los
  códigos oficiales de "Condición IVA Receptor" de ARCA — confirmado por inspección directa del seeder
  (1, 4, 5, 6, 7 coinciden con RG 5616), no requiere una tabla de mapeo nueva.
- El mismo `MapeadorComprobante` se usa para Ventas, Compras y NC/ND (spec 034) — esta corrección aplica
  transversalmente sin necesitar lógica separada por tipo de comprobantable.
- No se requiere backfill ni corrección retroactiva de comprobantes ya emitidos (aprobados o
  rechazados) — esta spec sólo corrige las solicitudes futuras.
