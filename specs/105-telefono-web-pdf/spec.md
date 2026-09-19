# Feature Specification: Teléfono y sitio web en el encabezado de los comprobantes impresos

**Feature Branch**: `105-telefono-web-pdf`

**Created**: 2026-09-18

**Status**: Draft

**Input**: Pedido del cliente: *"cuando imprimís una venta o presupuesto sumarle que salga número de teléfono dirección y página web"* (se refiere a los PDFs).

## Contexto y recorte del pedido

El pedido nombra tres datos, pero uno de los tres **ya se imprime**: la dirección del negocio sale
hoy en todos los comprobantes como *Domicilio Fiscal*. El pedido se recorta entonces a los dos datos
que efectivamente faltan —**teléfono** y **página web**— reutilizando el domicilio ya existente.

El encabezado del emisor es **uno solo, compartido** por todos los comprobantes imprimibles del CRM.
El cliente nombró Venta y Presupuesto porque son los que más imprime, pero la decisión tomada es que
los datos de contacto salgan en **los cinco** comprobantes que llevan ese encabezado (Venta,
Presupuesto, Nota de Crédito/Débito, Remito y Recibo): un cliente que recibe un Remito o un Recibo
necesita los mismos datos de contacto que uno que recibe una Factura, y mantenerlo unificado evita
que el negocio quede con dos encabezados distintos conviviendo.

## Clarifications

### Session 2026-09-18

Las dos ambigüedades reales del pedido se resolvieron **con el usuario antes de redactar la spec**,
así que entraron directamente al cuerpo del documento en lugar de quedar como `[NEEDS CLARIFICATION]`:

- Q: El pedido dice "dirección", pero el domicilio fiscal ya se imprime. ¿Es un domicilio comercial
  nuevo? → A: No. Es el domicilio fiscal existente; no se agrega campo de dirección. Sólo faltan
  teléfono y página web.
- Q: El pedido nombra Venta y Presupuesto, pero el encabezado del emisor es compartido por 5
  comprobantes. ¿Dónde salen los datos nuevos? → A: En los 5 (Venta, Presupuesto, NC/ND, Remito,
  Recibo), con un único encabezado compartido.

Barrido posterior de la taxonomía de ambigüedad: sin ambigüedades críticas adicionales. La feature no
tiene integraciones externas, concurrencia, supuestos de volumen ni requisitos de performance propios
más allá de que la generación de comprobantes no se degrade (SC-003). El formato libre del teléfono y
de la página web es una decisión tomada, no una ambigüedad pendiente (ver *Assumptions*).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - El cliente que recibe el comprobante puede contactar al negocio (Priority: P1)

Quien recibe un comprobante impreso o en PDF (una factura, un presupuesto) necesita poder comunicarse
con el negocio para consultar por el pedido, coordinar una entrega o hacer un reclamo. Hoy el
comprobante le da la razón social, el CUIT y el domicilio, pero **no un teléfono ni la página web**,
así que tiene que buscar esos datos por fuera del documento.

**Why this priority**: es el pedido del cliente y la razón de ser de la feature. Sin esto, el
comprobante no cumple su función comercial de canal de contacto.

**Independent Test**: se carga un teléfono y una página web en los datos de la empresa, se imprime un
presupuesto y se verifica que ambos datos aparecen en el encabezado, junto a los que ya salían.

**Acceptance Scenarios**:

1. **Given** la empresa tiene cargados teléfono y página web, **When** se genera el PDF de una Venta,
   **Then** el encabezado muestra el teléfono y la página web además de la razón social, el CUIT, el
   domicilio y la condición de IVA que ya mostraba.
2. **Given** la empresa tiene cargados teléfono y página web, **When** se genera el PDF de un
   Presupuesto, **Then** el encabezado muestra los mismos datos de contacto que en la Venta.
3. **Given** la empresa tiene cargados teléfono y página web, **When** se genera el PDF de una Nota de
   Crédito/Débito, de un Remito o de un Recibo, **Then** el encabezado también muestra esos datos.
4. **Given** la empresa no tiene cargado ninguno de los dos datos, **When** se genera cualquiera de
   esos PDFs, **Then** el comprobante se genera igual y el encabezado se ve como antes, sin renglones
   vacíos ni etiquetas sueltas.

---

### User Story 2 - El negocio carga y corrige sus datos de contacto (Priority: P2)

Quien administra el CRM necesita poder cargar el teléfono y la página web del negocio, y corregirlos
cuando cambien, desde la misma pantalla donde ya administra el resto de los datos de la empresa.

**Why this priority**: es la condición para que la Historia 1 sirva de algo —sin un lugar donde
cargarlos, los datos nunca llegan al comprobante— pero por sí sola no entrega valor al cliente final.

**Independent Test**: se abre Configuración & Ajustes → Empresa, se editan teléfono y página web, se
guarda, y se verifica que quedan mostrados en la pantalla al volver a entrar.

**Acceptance Scenarios**:

1. **Given** el usuario está en Configuración & Ajustes → Empresa, **When** abre la edición de los
   datos de la empresa, **Then** ve un campo para el teléfono y otro para la página web junto a los
   campos que ya existían.
2. **Given** el usuario completó teléfono y página web, **When** guarda, **Then** se confirma el
   guardado sin que la pantalla se recargue y los valores quedan visibles en la ficha de la empresa.
3. **Given** el usuario deja vacíos esos campos o los borra, **When** guarda, **Then** el guardado se
   acepta sin errores, porque son datos opcionales.
4. **Given** el usuario ya tenía cargados otros datos de la empresa (razón social, CUIT, logo),
   **When** guarda agregando sólo el teléfono, **Then** el resto de los datos se conserva intacto.

---

### Edge Cases

- **Sólo uno de los dos datos cargado**: si hay teléfono pero no página web (o viceversa), se imprime
  únicamente el que está cargado. No aparece una etiqueta sin valor al lado.
- **Empresa sin datos cargados**: si el negocio todavía no cargó ningún dato de empresa, el
  comprobante se sigue generando sin encabezado de emisor, como ya ocurre hoy. La feature no
  introduce un nuevo motivo para que un PDF falle.
- **Valores largos**: un teléfono con prefijo internacional y aclaraciones, o una URL larga, no deben
  romper la maquetación del encabezado ni desplazar el logo o los datos fiscales.
- **Formato libre del teléfono**: el negocio puede querer escribir más de un número, o agregar una
  aclaración (`11 5555-5555 / WhatsApp 11 4444-4444`). El dato se imprime tal como fue cargado.
- **Página web escrita de distintas formas**: el usuario puede cargarla con o sin `www`, con o sin
  prefijo de protocolo. Se imprime tal como fue cargada, sin reescribirla.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE permitir registrar un **teléfono** y una **página web** como parte de
  los datos del negocio emisor.
- **FR-002**: Ambos datos DEBEN ser **opcionales**: el negocio puede guardar sus datos de empresa sin
  completarlos, igual que ocurre hoy con el resto de los campos de esa pantalla.
- **FR-003**: El encabezado del emisor de los comprobantes imprimibles DEBE mostrar el teléfono y la
  página web cuando estén cargados, además de los datos que ya muestra hoy (razón social, CUIT,
  domicilio, condición de IVA y logo).
- **FR-004**: Ese comportamiento DEBE aplicar a los **cinco** comprobantes que usan el encabezado del
  emisor: Venta, Presupuesto, Nota de Crédito/Débito, Remito y Recibo. Los cinco DEBEN mostrar el
  mismo encabezado; no se admite que un comprobante muestre datos de contacto y otro no.
- **FR-005**: Un dato no cargado NO DEBE imprimirse: ni el valor vacío, ni su etiqueta, ni un renglón
  en blanco en su lugar.
- **FR-006**: La ausencia de estos datos NO DEBE impedir ni demorar la generación de ningún
  comprobante.
- **FR-007**: El teléfono y la página web DEBEN imprimirse **tal como fueron cargados**, sin
  reformatearlos ni normalizarlos.
- **FR-008**: Los usuarios DEBEN poder cargar y modificar estos datos desde la misma pantalla de
  Configuración & Ajustes → Empresa donde ya administran el resto de los datos del negocio, y DEBEN
  poder verlos ahí sin entrar a editar.
- **FR-009**: Agregar estos datos NO DEBE alterar ningún dato fiscal del comprobante: ni los importes,
  ni el tipo de comprobante, ni el CAE, ni el código QR, ni la numeración. Son datos de presentación.
- **FR-010**: Los negocios que ya tienen datos de empresa cargados DEBEN conservarlos intactos; los
  dos campos nuevos simplemente aparecen vacíos hasta que alguien los complete.
- **FR-011**: Los datos de contacto DEBEN imprimirse **después** de los datos fiscales (razón social,
  CUIT, domicilio, condición de IVA), respetando el orden de lectura habitual de un comprobante:
  identidad e información fiscal primero, canales de contacto después.
- **FR-012**: El encabezado DEBE seguir siendo legible con valores largos (un teléfono con varios
  números y aclaraciones, o una dirección web extensa): el texto acomoda en varias líneas sin
  desplazar el logo ni superponerse con los datos fiscales.

### Key Entities

- **Datos de la empresa**: la ficha única del negocio emisor, que hoy reúne razón social, CUIT,
  domicilio fiscal, condición de IVA, ingresos brutos, logo y mail del contador. Se le suman dos
  atributos opcionales de contacto: **teléfono** y **página web**.

> **Nota de terminología**: este documento usa los nombres de negocio "teléfono" y "página web",
> que son los que ve el usuario en pantalla. Los nombres técnicos de esos campos (`telefono` y
> `sitio_web`) se fijan en [data-model.md](./data-model.md); la diferencia en el segundo es
> deliberada y no una inconsistencia.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Con los datos de contacto cargados, **los 5** comprobantes imprimibles muestran el
  teléfono y la página web en su encabezado.
- **SC-002**: El negocio puede cargar ambos datos y verlos reflejados en un comprobante impreso sin
  ayuda técnica, entrando a una sola pantalla.
- **SC-003**: El 100% de los comprobantes que hoy se generan correctamente se siguen generando
  correctamente, con los datos de contacto cargados o sin ellos.
- **SC-004**: Ningún importe, numeración, CAE ni código QR de un comprobante cambia como consecuencia
  de esta feature.
- **SC-005**: Un negocio que tenía datos de empresa cargados antes del cambio no pierde ninguno de
  ellos.

## Assumptions

- La "dirección" del pedido del cliente es el **domicilio fiscal ya existente**, que ya se imprime en
  el encabezado. No se agrega un domicilio comercial separado. Si más adelante el negocio necesita
  mostrar una dirección de local distinta de la fiscal, eso es una feature aparte.
- Se decidió que los datos salgan en los cinco comprobantes con encabezado de emisor, y no sólo en
  Venta y Presupuesto como decía el pedido literal.
- El teléfono y la página web se guardan como texto libre. No se valida el formato del teléfono
  —el negocio puede necesitar cargar más de un número o una aclaración— ni se exige que la página web
  sea una URL con protocolo.
- Estos datos son públicos por naturaleza (se imprimen en un comprobante que se entrega al cliente),
  así que no requieren tratamiento especial de privacidad ni permisos distintos de los que ya rigen
  la pantalla de Empresa.
- La feature no toca el circuito de emisión fiscal (ARCA/CAE): el encabezado del emisor es metadata
  de presentación y no participa de la generación del comprobante fiscal.
- Se reutiliza el mecanismo de guardado que la pantalla de Empresa ya tiene; no se cambia la forma en
  que esa pantalla guarda ni notifica.
