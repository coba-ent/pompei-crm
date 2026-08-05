# Research: Condición de IVA en el autocompletado del Padrón de ARCA

## R1 — Por qué `ws_sr_padron_a13` nunca trae condición de IVA

**Decision**: Confirmado contra el WSDL real de producción (`https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?wsdl`):
el elemento `persona` del schema **no tiene ningún campo** de impuestos/régimen/monotributo — sólo
identidad (`nombre`, `apellido`, `razonSocial`, `idActividadPrincipal`, `descripcionActividadPrincipal`,
`estadoClave`, `tipoPersona`, etc.) y `domicilio[]`. Se probó en vivo con dos CUITs reales muy distintos
(Mauricio Macri, persona física, y YPF S.A., persona jurídica grande con actividad económica intensa) y
ninguno devolvió dato de impuestos — no es que a esas personas les falte el dato, es que el servicio no lo
expone estructuralmente.

**Rationale**: research.md de la spec 037 asumía (sin verificar contra el WSDL real) que A13 traía
`datosRegimenGeneral`/`datosMonotributo` bajo `personaReturn`. Era un supuesto incorrecto, replicado tanto
en `ResultadoConsultaPadron::extraerCondicionIva()` como en los tests unitarios/feature de esa spec — por
eso el bug pasó inadvertido hasta que se probó contra ARCA real (ver `app/Services/Arca/ResultadoConsultaPadron.php`,
corregido el 05/08/2026 en el mismo hallazgo que reveló este problema).

**Alternatives considered**: Ninguna — es un hecho verificado contra el servicio real, no una decisión de
diseño.

## R2 — Servicio y WSDL a sumar para condición de IVA

**Decision**: `ws_sr_constancia_inscripcion` ("Consulta de constancia de inscripción" en el Administrador de
Relaciones de Clave Fiscal de ARCA — nombre de servicio confirmado en producción: el intento de adherirlo
al certificado devolvió `La autorizacion: (...)-ws://ws_sr_constancia_inscripcion-... ya existe`, es decir
**ya está habilitado sobre el certificado actual**, no requiere gestión adicional en ARCA). Su WSDL real es
`https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5?wsdl` (producción) /
`https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5?wsdl` (homologación) — el predecesor
histórico "A5" de la familia de padrón, que ARCA mantiene activo específicamente para esta consulta.

**Rationale**: Se verificó el schema completo del WSDL (`personaServiceA5`) y sí incluye
`datosGenerales`, `datosRegimenGeneral` (con `impuesto[]`, `actividad[]`, `categoriaAutonomo`) y
`datosMonotributo` (con `categoriaMonotributo`, `actividadMonotributista`) bajo `personaReturn`. Se probó en
vivo contra producción con el CUIT de Mauricio Macri y devolvió exactamente esos campos con datos reales
(impuesto "IVA" activo, "GANANCIAS PERSONAS FISICAS", "APORTES SEG.SOCIAL AUTONOMOS").

**Alternatives considered**: `ws_sr_padron_a5` (mismo WSDL técnico `personaServiceA5`, pero identificado
como servicio de padrón "puro" en vez de "constancia de inscripción") — se descarta la distinción como
irrelevante: en el Administrador de Relaciones de Clave Fiscal el ítem visible y ya adherido es
"Consulta de constancia de inscripción", que es el nombre de servicio (`ws_sr_constancia_inscripcion`) que
efectivamente autentica el ticket de acceso contra este WSDL — se usa ese nombre de servicio en
`ClienteWsaa::obtenerTicketAcceso()`.

## R3 — Estructura real de la respuesta de `ws_sr_constancia_inscripcion` (`getPersona`)

**Decision**: A diferencia de A13 (que anida todo bajo `personaReturn.persona`), esta respuesta trae:

```
personaReturn
├── metadata (fechaHora, servidor)
├── datosGenerales (nombre, apellido / razonSocial, tipoPersona, estadoClave, esSucesion,
│                    domicilioFiscal { direccion, descripcionProvincia, codPostal, tipoDomicilio })
├── datosRegimenGeneral (opcional, ausente si no aplica)
│   ├── actividad[] (idActividad, descripcionActividad, periodo, orden)
│   ├── categoriaAutonomo (opcional)
│   └── impuesto[] (idImpuesto, descripcionImpuesto, estadoImpuesto, periodo, motivo)
└── datosMonotributo (opcional, ausente si no aplica — mutuamente excluyente en la práctica con datosRegimenGeneral.impuesto conteniendo "IVA")
```

`descripcionImpuesto` NO es un texto de "condición de IVA" (no viene "IVA RESPONSABLE INSCRIPTO" como
research.md de la spec 037 asumía) — viene el **nombre del impuesto** ("IVA", "GANANCIAS PERSONAS FISICAS",
"APORTES SEG.SOCIAL AUTONOMOS", etc.) junto con `estadoImpuesto` (`AC` = activo, u otro código de baja).

**Rationale**: Verificado con la respuesta real de ARCA producción (CUIT 20-13120469-9), no documentación de
terceros — ver ejemplo completo capturado en `quickstart.md`.

**Alternatives considered**: N/A — dato verificado, no diseño.

## R4 — Nueva regla de mapeo a `condiciones_iva` del CRM

**Decision**: Reemplaza la lógica de "buscar texto de condición en `impuesto[].descripcionImpuesto`" (que
nunca iba a matchear nada, ver R1/R3) por esta derivación:

1. Si `datosMonotributo` está presente → **Monotributista**.
2. Si no, y `datosRegimenGeneral.impuesto[]` contiene un ítem con `descripcionImpuesto === 'IVA'` y
   `estadoImpuesto === 'AC'` → **Responsable Inscripto**.
3. Si no, y existe un ítem de impuesto IVA con `estadoImpuesto` distinto de `AC` (dado de baja) → **Exento**
   sólo si además el padrón (`ws_sr_padron_a13`) confirma `estadoClave === 'ACTIVO'` en términos generales;
   en cualquier otro caso ambiguo → `null` ("no se pudo determinar", mismo fallback ya definido en spec 037
   R6).
4. Si no hay `datosRegimenGeneral` ni `datosMonotributo` en absoluto (contribuyente sin inscripciones
   activas relevadas) → `null`, tratado igual que "Consumidor Final" a los efectos de FR-007/FR-008 de la
   spec 037 (no fuerza Factura A).

**Rationale**: Es la única derivación consistente con los datos reales observados; evita inventar un mapeo
de texto que ARCA nunca devuelve. Mantiene el principio ya establecido (spec 037 R6) de no crear
condiciones de IVA nuevas y degradar a `null` ante ambigüedad.

**Alternatives considered**: Inferir la condición sólo por la ausencia/presencia de `datosMonotributo` sin
mirar `impuesto[]` — descartado, perdería la distinción entre Responsable Inscripto y Exento/dado de baja
de IVA, que sí es relevante para FR-007 (determinación de tipo de comprobante).

## R5 — Independencia de las dos consultas (A13 + constancia)

**Decision**: Se disparan ambas consultas SOAP de forma independiente en el mismo punto donde spec 037 ya
dispara la consulta a A13; el resultado de cada una se evalúa y aplica por separado — el éxito o fallo de
una no condiciona a la otra (FR-005 de esta spec).

**Rationale**: Ya confirmado en producción que ambos servicios pueden responder de forma independiente
(distintos WSDL, distintos posibles estados de disponibilidad); tratarlas como una sola operación atómica
degradaría innecesariamente el autocompletado de razón social/domicilio (que sí funciona hoy) si sólo la
consulta de constancia falla.

**Alternatives considered**: Consulta secuencial condicionada (sólo pedir constancia si A13 tuvo éxito) —
descartado; no hay relación de dependencia real entre ambos servicios (son dos padrones distintos de ARCA,
ambos autenticados con el mismo certificado pero sin relación funcional entre sí), y encadenarlas
artificialmente añadiría latencia sin beneficio.

## R6 — Timeout y patrón del wrapper

**Decision**: Mismo patrón que `ClientePadron` (spec 037 R2/R3): wrapper delgado `ClienteConstanciaInscripcion`
con `connection_timeout` corto (8s, igual que A13), `SOAP_1_1` (probado directo contra producción y
funcionó correctamente; no se probó explícitamente `SOAP_1_2` contra este WSDL puntual, pero dado que
comparte host e infraestructura con A13 — que sí confirmó requerir 1.1 — se usa 1.1 por defecto para evitar
repetir el mismo bug), mismo `stream_context` con `SECLEVEL=1` (mismos servidores de ARCA), excepciones
envueltas en `ArcaNoDisponibleException` ya existente.

**Rationale**: Consistencia total con el wrapper ya probado en producción; cero necesidad de inventar un
patrón nuevo.

**Alternatives considered**: Ninguna — mismo patrón exacto, sin variación.
