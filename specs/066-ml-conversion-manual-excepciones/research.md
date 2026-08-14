# Phase 0 — Research

Todo lo de acá se verificó leyendo el código vigente, no de memoria.

## R1. ¿Dónde se decide hoy que una orden "se puede convertir"?

**Hallazgo**: en un único lugar, `EvaluadorConvertibilidad::evaluar()`. Lo usan `SincronizadorOrdenes` en
cada corrida y `ConversorOrdenAVenta` en cada intento de conversión — el propio docblock del servicio dice
que existe justamente "para no duplicar la lógica de qué hace a una orden Lista para convertir".

**Decisión**: agregar ahí los casos faltantes.

**Rationale**: `ConversorOrdenAVenta::convertirTodasLasListas()` filtra por `estado_conversion = Lista`, y la
creación automática del cron también. Un caso que el evaluador saque de `Lista` queda excluido de los dos
caminos sin tocar ninguno.

**Alternativas descartadas**: agregar un filtro de exclusión en el cron y otro en el lote. Se rechaza porque
duplica la regla en dos lugares y garantiza que en algún momento diverjan.

## R2. ¿Por qué una orden en mediación se convierte sola hoy?

**Hallazgo**: la mediación **no** está en el estado de la orden. `EstadoOrden::desdeCrudo()` mapea sólo
`status` (`paid`, `cancelled`, `partially_refunded`…), y su propio comentario lo advierte: *"La mediación NO
se deriva de este estado: vive en `payments[].status`"*.

El único que lo mira es `DetectorCancelaciones`, y ese servicio corre **después** de la conversión: arranca
buscando la Venta y devuelve `null` si no existe. Una orden pagada con un pago `in_mediation` que todavía no
tiene Venta llega al evaluador como perfectamente normal y sale como `Lista`.

**Decisión**: persistir la señal en `ml_ordenes.en_mediacion` durante la sincronización y hacer que el
evaluador la use.

**Rationale**: el evaluador recibe un modelo, no el payload crudo. Pasarle el payload obligaría a cambiar su
firma y a que el conversor —que trabaja sobre la orden ya guardada— consiga el JSON otra vez. Persistir el
booleano lo resuelve una vez, en el lugar donde el dato ya está disponible.

**Alternativas descartadas**:
- *Leer el payload guardado (`ml_ordenes.payload`) desde el evaluador.* El JSON está, pero obliga a
  deserializar y navegar la estructura del proveedor en cada evaluación, y ata el evaluador al formato crudo
  de Mercado Libre — justo lo que `TraductorOrdenes` existe para evitar.
- *Consultar la API en el momento de evaluar.* Agrega latencia y un punto de falla externo a una operación
  que hoy es local.

## R3. ¿Qué pasa exactamente con las canceladas?

**Hallazgo**: hay **dos** bloqueos superpuestos.

1. `EvaluadorConvertibilidad::evaluar()` las manda a `EstadoConversion::Cancelada` antes que nada, así que
   nunca quedan `Lista`.
2. `ConversorOrdenAVenta::convertirBajoCandado()` tiene además un rechazo explícito: *"La orden está
   cancelada en Mercado Libre y no puede convertirse"*.

Y una tercera barrera indirecta: la guarda siguiente exige `estado_orden === Pagada`, que una cancelada no
cumple.

**Decisión**: las tres guardas se condicionan a que la conversión **no** sea forzada.

**Rationale**: sin desactivar las tres, forzar no funciona; con desactivar sólo una o dos, el error sería
confuso ("no está pagada" cuando el problema real es otro).

**Riesgo identificado**: es fácil desactivar de más y dejar pasar una orden **pendiente de pago**. La guarda
de "pagada" cubre dos casos distintos —pendiente de pago y cancelada— y sólo el segundo se habilita.
Necesita test propio.

## R4. ¿Cómo se registra hoy quién hizo qué en la integración?

**Hallazgo**: `MercadoLibreOperacionLog::registrar()`, con campos `operacion`, `metodo`, `endpoint`,
`sentido`, `resultado`, `usuario_id`. Lo usan tanto el sincronizador (incluso para registrar bloqueos) como
el conversor.

**Decisión**: la conversión forzada se registra ahí, y en la orden quedan sólo los tres campos que el sistema
necesita para decidir (`forzada_motivo`, `forzada_por_id`, `forzada_en`).

**Rationale**: la bitácora ya es el lugar donde se responde "qué pasó con la integración". Una tabla nueva
partiría esa respuesta en dos lugares. Pero el log no sirve para FR-018 —hay que comparar el motivo forzado
contra el motivo detectado después, en cada sincronización— y consultar la bitácora para eso sería frágil.

## R5. ¿Cómo interactúa esto con los avisos de la spec 063?

**Hallazgo**: `DetectorCancelaciones` marca la orden como `RequiereAtencion` cuando una Venta ya creada pasa
a cancelada, reembolso parcial o mediación. Si se fuerza la conversión de una orden cancelada, la
sincronización siguiente la marcaría de inmediato **por el mismo motivo** que la persona acaba de asumir.

**Decisión**: el detector no genera el aviso si el motivo detectado coincide con `forzada_motivo`. Si aparece
un motivo **distinto**, avisa con normalidad.

**Rationale**: un aviso que informa lo que el usuario acaba de decidir es ruido, y el ruido entrena a ignorar
los avisos que sí importan — incluidos los que sí son novedad.

**Alternativas descartadas**:
- *Descartar el aviso automáticamente al forzar.* El circuito de descarte registra "quién y cuándo" y
  dejaría un descarte fantasma que nadie hizo.
- *No avisar nunca sobre una Venta forzada.* Perdería el caso legítimo: se forzó una cancelada y después
  entra en mediación, que es información nueva y relevante.

## R6. ¿La confirmación puede vivir sólo en la UI?

**Hallazgo**: la conversión se guarda por `POST ingresos/mercadolibre/{orden}/convertir`, validado por
`ConvertirOrdenRequest`. La ruta es alcanzable directamente.

**Decisión**: `forzar_conversion` es un campo validado del FormRequest, y el controlador rechaza con 409 la
conversión de una orden excepcional que llegue sin él.

**Rationale**: FR-010 exige que la confirmación no se pueda saltear. Un modal es una comodidad de la
interfaz, no un control de acceso. Y el principio III de la constitución aplica de lleno: del otro lado de
esta guarda se emite un comprobante fiscal.

## R7. Estado del evaluador respecto de la alerta de fraude

**Hallazgo**: `tiene_alerta_fraude` ya deja la orden en `RequiereAtencion` con motivo `AlertaFraude`, así que
**ya está excluida** del automático y del lote. Lo único que falta es poder forzarla.

**Decisión**: no se toca el evaluador para este caso; sólo entra en la lista de motivos que el camino forzado
puede saltear.

**Rationale**: es la mitad del trabajo ya hecha. Reescribirla sería riesgo sin beneficio.
