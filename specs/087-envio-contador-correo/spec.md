# Feature Specification: Enviar Información a tu Contador por Correo

**Feature Branch**: `087-envio-contador-correo`

**Created**: 2026-08-27

**Status**: Draft

**Input**: Modal "Enviar Información a tu Contador por Correo" del informe "Información para tu Contador", calcando el de Contagram real relevado con 4 capturas provistas por el usuario el 27/08/2026.

---

## Contexto y fuente de verdad

El modal **existe en Contagram real** y está relevado con **4 capturas** del 27/08/2026 sobre la cuenta
del cliente (Pompei Sanitarios), que muestran deliberadamente los cuatro estados del panel de adjuntos:
sin período elegido, con año solo, con año y mes, y con la casilla de PDFs tildada. Esas capturas son
la **fuente de verdad estructural** de esta spec (principio rector de `CLAUDE.md`).

Este modal cierra el módulo del informe del contador: la spec 077 construyó la pantalla y los XLSX, la
spec 086 genera el paquete IVA Digital, y ésta es la que efectivamente **le hace llegar todo al
contador**, que es el objetivo real del módulo.

---

## Estructura del modal (relevada de las capturas)

Título: **"Enviar Información a tu Contador por Correo"**. Dos columnas.

**Columna izquierda:**

| Elemento | Detalle observado |
|---|---|
| **Mail** | Con la leyenda auxiliar *"Separar con una coma (,) direcciones de mail adicionales"*. Precargado con el mail del contador (`cconicelli@dfconsultores.com.ar`). |
| **Asunto del Correo** | Precargado: *"Información de Pompei"* — el nombre del negocio. |
| **Contenido del Correo** | Textarea con scroll, precargado con una plantilla que **se rearma sola** según el período y los adjuntos elegidos. |
| **Enviar una copia a mi Mail** | Casilla, destildada por defecto. |
| **Adjuntar** | Botón amarillo con ícono de clip: adjunta archivos propios del usuario. |

**Columna derecha:**

| Elemento | Detalle observado |
|---|---|
| **Año** / **Mes** | Dos desplegables. Arrancan vacíos (placeholder "Año" / "Mes"). |
| **Facturas Electrónicas** | Casilla, **tildada por defecto**. |
| **Facturas Manuales** | Casilla destildada, con ícono de ayuda `?` al lado. |
| **PDF factura de ventas** | Casilla destildada. |
| **Archivos Adjuntos** | Panel que muestra en vivo los archivos que se van a enviar, con ícono por tipo. |

**Pie**: **Cancelar** (rojo) y **Enviar** (verde, con ícono de sobre).

---

## Comportamiento del panel de adjuntos (el hallazgo central del relevamiento)

Las 4 capturas fueron tomadas justamente para documentar esto, y es la regla más importante de la spec:

| Estado | Contenido del panel |
|---|---|
| Sin año ni mes | **Vacío.** |
| Año, sin mes | `IVA Ventas - 2026.xlsx` · `IVA Compras - 2026.xlsx` |
| Año **y** mes | `IVA Ventas Marzo - 2026.xlsx` · `IVA Compras Marzo - 2026.xlsx` · **`IVA Digital Marzo - 2026.zip`** |
| Año y mes + *PDF factura de ventas* | Lo anterior **más** `PDFs Facturas de Venta Marzo - 2026.zip` |

Dos consecuencias que no son obvias y que definen el diseño:

1. **El IVA Digital aparece sólo con mes elegido.** Coherente con la spec 086: el régimen RG 3685 es de
   presentación mensual y un archivo anual no tendría sentido para ARCA.
2. **El nombre del archivo cambia según haya mes o no** (`IVA Ventas - 2026.xlsx` vs. `IVA Ventas Marzo - 2026.xlsx`),
   y el cuerpo del correo cambia en paralelo: *"del mes de **de** 2026"* cuando no hay mes (así, con el
   hueco, en la captura real) vs. *"del mes de **Marzo** de 2026"*.

Sobre el punto 2: la versión sin mes de Contagram deja una frase gramaticalmente rota. Esta spec **la
corrige** (FR-014): con año solo, el texto dice "del año 2026". Es una divergencia deliberada y menor,
en un texto que le llega al contador con el nombre del negocio.

---

## Clarifications

### Session 2026-08-27

- Q: ¿Qué cuenta se usa para enviar? → A: **La misma configuración SMTP que ya usa la recuperación de
  contraseña** (spec 081), decidido por el usuario el 27/08/2026. No se agrega configuración de correo
  nueva.

- Q: ¿Qué significa exactamente "Facturas Manuales"? → A: **Incluir en el libro IVA Ventas los
  comprobantes sin CAE**, es decir los que no fueron aprobados por ARCA. Confirmado por dos vías: la
  spec 077 ya construyó ese mismo par de casillas en la pantalla del informe ("Aprobadas por ARCA" /
  "Manuales") con esa semántica exacta, y el usuario confirmó que tildarla **no agrega ningún archivo
  nuevo** al panel de adjuntos. Es decir: **no es un adjunto, es un filtro de contenido** de los XLSX
  de IVA Ventas. Las dos casillas particionan el universo de ventas, igual que en la 077.

- Q: ¿Y "Facturas Electrónicas"? → A: la contraparte: las ventas **con** CAE aprobado. Viene tildada por
  defecto porque es el caso normal.

- Q: ¿Se pueden destildar las dos a la vez? → A: **No.** Con ambas destildadas el libro IVA Ventas
  quedaría vacío y el envío no tendría sentido. Al menos una debe quedar tildada (FR-020).

- Q: ¿El envío es síncrono? → A: **No: se encola.** El proyecto tiene `QUEUE_CONNECTION=sync`, con lo
  cual hoy se ejecutaría en la misma request; un mail con varios adjuntos (los XLSX más dos ZIP, uno de
  ellos con los PDF de todas las facturas del mes) puede tardar lo suficiente como para agotar el
  tiempo de la request y dejar al usuario sin saber si el correo salió. Ver FR-021 y la sección de
  riesgos.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enviarle al contador la información del mes (Priority: P1)

Al cierre de cada mes, el responsable administrativo abre el modal desde el informe, elige mes y año,
verifica que en el panel aparezcan los archivos esperados, y presiona Enviar. El contador recibe un
correo con los XLSX de IVA Ventas y Compras y el ZIP del IVA Digital, con un asunto y un cuerpo que ya
vienen escritos.

**Why this priority**: es el flujo completo del cierre mensual y la razón de existir del módulo. Sin
esto, el usuario tiene que descargar cada archivo, abrir su correo, redactar el mensaje y adjuntarlos a
mano todos los meses.

**Independent Test**: elegir un mes y un año, enviar, y verificar que sale un correo al destinatario
configurado con exactamente los tres adjuntos, con los nombres correctos, asunto correcto y cuerpo que
nombra el mes y lista los archivos.

**Acceptance Scenarios**:

1. **Given** el modal abierto sin período elegido, **When** el usuario lo mira, **Then** el panel de
   Archivos Adjuntos está vacío y el botón Enviar no permite enviar.
2. **Given** año y mes elegidos, **When** el panel se actualiza, **Then** muestra los tres archivos
   (IVA Ventas, IVA Compras, IVA Digital) con el mes y el año en el nombre.
3. **Given** ese estado, **When** el usuario presiona Enviar, **Then** el correo sale al destinatario
   con esos tres adjuntos y el usuario ve una notificación de éxito **sin que la página se recargue**.
4. **Given** un envío que falla (SMTP caído, destinatario inválido), **When** ocurre el error,
   **Then** el usuario ve una notificación de error clara y el modal **no** se cierra, conservando lo
   que había cargado.

---

### User Story 2 - Enviar la información de un año completo (Priority: P2)

El contador pide el consolidado anual. El usuario elige sólo el año, y el panel ofrece los dos XLSX
anuales, sin el IVA Digital.

**Why this priority**: es un pedido real pero menos frecuente que el cierre mensual. Comparte casi toda
la mecánica con la historia 1.

**Acceptance Scenarios**:

1. **Given** un año elegido sin mes, **When** el panel se actualiza, **Then** muestra `IVA Ventas - {Año}.xlsx`
   e `IVA Compras - {Año}.xlsx`, y **no** el IVA Digital.
2. **Given** ese estado, **When** el usuario mira el cuerpo del correo, **Then** el texto se refiere al
   año, no a un mes, y lista sólo los dos archivos que se van a enviar.

---

### User Story 3 - Ajustar qué se manda (Priority: P2)

El usuario tilda "PDF factura de ventas" para que el contador reciba también los comprobantes en PDF, o
destilda "Facturas Electrónicas" para mandar sólo las manuales, o adjunta un archivo propio con el
botón Adjuntar.

**Why this priority**: son variantes del envío, no un flujo nuevo. Aportan valor pero el módulo sirve
sin ellas.

**Acceptance Scenarios**:

1. **Given** año y mes elegidos, **When** el usuario tilda "PDF factura de ventas", **Then** el panel
   suma `PDFs Facturas de Venta {Mes} - {Año}.zip` y el cuerpo del correo lo menciona.
2. **Given** el usuario destilda "Facturas Electrónicas" y tilda "Facturas Manuales", **When** se
   genera el libro IVA Ventas, **Then** contiene únicamente los comprobantes sin CAE, y **no** aparece
   ningún archivo nuevo en el panel.
3. **Given** el usuario intenta destildar ambas casillas, **When** lo hace, **Then** el sistema se lo
   impide y le explica por qué.
4. **Given** el usuario adjunta un archivo propio, **When** envía, **Then** ese archivo viaja junto a
   los generados.

---

### User Story 4 - Enviar a varios destinatarios y guardarse una copia (Priority: P3)

El usuario agrega un segundo mail separado por coma y tilda "Enviar una copia a mi Mail".

**Acceptance Scenarios**:

1. **Given** dos direcciones separadas por coma, **When** envía, **Then** ambas reciben el correo.
2. **Given** "Enviar una copia a mi Mail" tildada, **When** envía, **Then** el usuario que operó
   también recibe el correo.
3. **Given** una dirección mal escrita, **When** intenta enviar, **Then** el sistema señala cuál es
   inválida y no envía nada.

---

### Edge Cases

- **Período sin movimientos**: se envía igual, con los libros vacíos; el contador necesita saber que el
  período no tuvo actividad. No se bloquea el envío.
- **Sin mail de contador configurado**: el campo aparece vacío y el usuario puede escribirlo; el envío
  exige al menos un destinatario.
- **Adjuntos que superan el límite del servidor de correo**: ver FR-022 — se avisa antes de intentar.
- **El usuario cambia el período con el modal abierto**: el panel y el cuerpo del correo se rearman;
  si había editado el cuerpo a mano, ver FR-013.
- **Doble clic en Enviar**: no debe mandar el correo dos veces.
- **Mes sin PDFs de facturas** con la casilla tildada: el ZIP viaja vacío o no viaja, pero no rompe el
  envío.

## Requirements *(mandatory)*

### Estructura del modal

- **FR-001**: El sistema MUST ofrecer, desde el informe "Información para tu Contador", un modal
  titulado "Enviar Información a tu Contador por Correo" con la estructura de dos columnas relevada.
- **FR-002**: El campo de destinatario MUST admitir **varias direcciones separadas por coma** y MUST
  mostrar esa indicación junto al campo.
- **FR-003**: El destinatario MUST precargarse con el mail del contador configurado.
- **FR-004**: El asunto MUST precargarse como `Información de {nombre del negocio}`.
- **FR-005**: El sistema MUST ofrecer las casillas "Facturas Electrónicas" (tildada por defecto),
  "Facturas Manuales" (con ayuda contextual) y "PDF factura de ventas" (destildada).
- **FR-006**: El sistema MUST ofrecer "Enviar una copia a mi Mail" (destildada) y un botón para
  adjuntar archivos propios.
- **FR-007**: Los desplegables de Año y Mes MUST arrancar **vacíos**, coherente con la spec 077 (este
  informe no precarga período).

### Panel de adjuntos

- **FR-008**: El panel MUST mostrar en vivo los archivos que se enviarán, actualizándose ante cada
  cambio de período o de casilla, **sin recargar la página**.
- **FR-009**: Sin período elegido, el panel MUST estar vacío.
- **FR-010**: Con año y sin mes, MUST listar `IVA Ventas - {Año}.xlsx` e `IVA Compras - {Año}.xlsx`.
- **FR-011**: Con año y mes, MUST listar `IVA Ventas {Mes} - {Año}.xlsx`, `IVA Compras {Mes} - {Año}.xlsx`
  y `IVA Digital {Mes} - {Año}.zip`.
- **FR-012**: Con "PDF factura de ventas" tildada, MUST sumar `PDFs Facturas de Venta {Mes} - {Año}.zip`.
- **FR-012a**: El IVA Digital MUST ofrecerse **sólo** cuando hay mes elegido.
- **FR-012b**: El ZIP de PDF de facturas de venta MUST ofrecerse **sólo** cuando hay mes elegido,
  por el mismo motivo de volumen explicado en Assumptions.

### Cuerpo del correo

- **FR-013**: El cuerpo MUST precargarse con una plantilla que nombre al contador, indique el período y
  **liste los archivos que efectivamente se adjuntan**, rearmándose ante cada cambio. Si el usuario lo
  editó a mano, el sistema MUST NOT descartar su texto en silencio.
- **FR-014**: Con año y sin mes, el texto MUST referirse al **año** (corrige el hueco gramatical del
  original, ver arriba).
- **FR-015**: El usuario MUST poder editar libremente asunto y cuerpo antes de enviar.

### Envío

- **FR-016**: El sistema MUST enviar el correo con los adjuntos listados, usando la **configuración
  SMTP ya existente** del sistema; MUST NOT introducir configuración de correo nueva.
- **FR-017**: El sistema MUST validar que haya al menos un destinatario y que todas las direcciones
  sean válidas, señalando cuál falla **antes** de enviar.
- **FR-018**: Con "Enviar una copia a mi Mail" tildada, el usuario que opera MUST recibir el correo.
- **FR-019**: El sistema MUST notificar éxito o error por toast, sin recargar la página, y MUST
  conservar el contenido del modal si el envío falla.
- **FR-020**: El sistema MUST impedir que las casillas "Facturas Electrónicas" y "Facturas Manuales"
  queden ambas destildadas, explicando el motivo.
- **FR-021**: El envío MUST procesarse **en segundo plano**, devolviendo el control al usuario sin
  esperar a que termine, y MUST informarle el resultado.
- **FR-022**: El sistema MUST advertir al usuario **antes de enviar** si el conjunto de adjuntos supera
  el tamaño admitido, en lugar de fallar durante el envío.
- **FR-023**: El sistema MUST impedir el envío duplicado por doble clic.
- **FR-024**: El sistema MUST registrar cada envío (destinatarios, período, archivos, resultado) para
  poder responder después si el contador dice que no le llegó.

### Contenido de los archivos

- **FR-025**: El libro IVA Ventas adjunto MUST respetar el filtro de las casillas Electrónicas/Manuales,
  reutilizando la clasificación ARCA/manual ya construida por la spec 077.
- **FR-026**: Los archivos adjuntos MUST generarse con la misma lógica que las descargas del informe
  (specs 077 y 086); MUST NOT existir una segunda derivación de los mismos números.

### Key Entities

- **Solicitud de envío**: destinatarios, copia al remitente, asunto, cuerpo, período (año y mes
  opcional), casillas de contenido, y archivos propios adjuntados.
- **Adjunto**: un archivo del correo, generado (XLSX de IVA Ventas, XLSX de IVA Compras, ZIP de IVA
  Digital, ZIP de PDFs) o subido por el usuario.
- **Registro de envío**: constancia de un envío realizado, con su resultado.

## Success Criteria *(mandatory)*

- **SC-001**: El responsable administrativo completa el envío mensual al contador en **menos de un
  minuto** y sin salir del CRM.
- **SC-002**: El contador recibe un correo cuyo asunto y cuerpo no necesitan edición manual en el caso
  normal.
- **SC-003**: Los archivos que llegan al contador son **idénticos** a los que el usuario descargaría
  desde la pantalla del informe para el mismo período y las mismas casillas.
- **SC-004**: El panel de adjuntos refleja siempre lo que efectivamente se va a enviar: no hay caso en
  que llegue un archivo que no estaba listado, ni falte uno que sí lo estaba.
- **SC-005**: Ningún envío fallido deja al usuario sin saber qué pasó.
- **SC-006**: Un envío con adjuntos grandes no deja la pantalla bloqueada ni pierde el correo.

## Assumptions

- El mail del contador y el nombre del negocio se toman de la configuración ya existente del sistema;
  esta spec no crea una pantalla de configuración nueva. Si el mail del contador todavía no tiene dónde
  guardarse, se agrega ese único dato a la configuración existente.
- El ícono de ayuda de "Facturas Manuales" explica que son los comprobantes sin CAE, en línea con la
  terminología ya usada por la spec 077.
- Los PDF de facturas de venta se generan con el mismo mecanismo que ya usa el CRM para mostrarlos en
  pantalla; esta spec no define un formato de PDF nuevo.
- El envío lo realiza un usuario autenticado con permiso sobre el módulo de Informes.
- El límite de tamaño de adjuntos se toma del servidor de correo configurado.
- **PDF en modo anual**: las capturas sólo muestran la casilla de PDF con un mes elegido, así que
  el relevamiento no cubre qué hace Contagram con año solo. Se asume el criterio conservador y
  coherente con el IVA Digital: los PDF se ofrecen **sólo en modo mensual** (FR-012b). Un ZIP con
  los PDF de un año entero sería inmanejable como adjunto de correo.

## Dependencies

- **Spec 077**: la pantalla desde la que se abre el modal, los XLSX de IVA Ventas/Compras y la
  clasificación ARCA/manual que alimenta las casillas.
- **Spec 086**: el paquete IVA Digital, que es uno de los adjuntos. **El modal puede construirse antes
  de que 086 esté implementada**, ofreciendo los demás adjuntos; pero el envío mensual completo (US1)
  no está terminado hasta que 086 exista.
- **Spec 081**: la configuración SMTP que se reutiliza.

## Out of Scope

- Configurar el servidor de correo: ya está resuelto (spec 081).
- El formato interno de los archivos adjuntos: specs 077 y 086.
- Envío automático programado al cierre de cada mes: el envío lo dispara el usuario.
- Recepción de respuestas del contador dentro del CRM.
