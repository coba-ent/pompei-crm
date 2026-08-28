# Research: Enviar Información a tu Contador por Correo (spec 087)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md)

---

## Decisión 1 — Reutilizar el mailer existente, sin configuración nueva

**Elegido**: usar la configuración de correo del sistema, la misma que envía la recuperación de
contraseña (spec 081).

**Por qué**: decisión explícita del usuario el 27/08/2026. Además evita el peor resultado posible en
este terreno: dos configuraciones de correo distintas, una de las cuales se rompe sin que nadie se
entere hasta que hace falta.

---

## Decisión 2 — Encolar el envío en vez de mandarlo dentro de la request

**Elegido**: el envío se procesa en segundo plano; la request responde apenas queda encolado.

**Por qué**: el correo del cierre mensual puede llevar dos XLSX, el ZIP del IVA Digital y un ZIP con
**los PDF de todas las facturas de venta del mes**. Generar todo eso y hablar con el servidor SMTP
dentro de una request web es una espera larga con final incierto: si la request corta por tiempo, el
usuario no sabe si el correo salió, y reintentar puede mandarlo dos veces.

**Detalle que hay que resolver al implementar**: el proyecto está hoy con la cola en modo `sync`, es
decir que "encolar" ejecuta en el acto y no cambia nada. Encolar de verdad requiere un worker corriendo
en el VPS. Esta decisión es, por lo tanto, **una dependencia operativa**, no sólo de código: si el
worker no está, el envío sigue siendo síncrono y FR-021 no se cumple aunque el código esté escrito.
Se trata explícitamente en el plan.

**Alternativa descartada**: dejarlo síncrono y subir el tiempo límite. Tapa el síntoma para los meses
chicos y falla justo en los meses grandes, que son los que más importan.

---

## Decisión 3 — El panel de adjuntos se arma en el cliente, los archivos se generan en el servidor

**Elegido**: al cambiar período o casillas, la interfaz **calcula y muestra la lista de archivos** que
correspondería enviar, sin generarlos. Los archivos se generan recién al enviar.

**Por qué**: las capturas muestran que el panel reacciona instantáneamente a cada cambio. Generar los
archivos en cada cambio para poder mostrarlos sería costosísimo (un ZIP de PDFs por cada clic) y no
aporta nada: lo que el usuario necesita ver es **qué** va a recibir el contador, no el contenido.

**Riesgo que introduce**: que la lista mostrada y lo efectivamente enviado se separen — exactamente lo
que SC-004 prohíbe. Se mitiga con una regla explícita: **la misma decisión de qué archivos
corresponden** vive en un solo lugar del servidor, y el panel la refleja; no hay una lista escrita a
mano en el cliente y otra en el servidor.

---

## Decisión 4 — "Facturas Manuales" es un filtro, no un adjunto

**Elegido**: las casillas Electrónicas/Manuales filtran el contenido del libro IVA Ventas; no agregan
ni quitan archivos del panel.

**Por qué**: confirmado por el usuario ("cuando lo chequeo, a eso no me incluye un archivo extra") y
coherente con la spec 077, que ya construyó ese mismo par de casillas sobre la tabla del informe con la
semántica "tiene CAE aprobado" / "no lo tiene". Se reutiliza esa clasificación tal cual, incluido su
gotcha documentado: la relación entre venta y comprobante fiscal es 1→N (una venta reintentada tiene un
rechazo y una aprobación), así que la clasificación se hace por existencia de una aprobación, no
tomando el primer comprobante.

**Consecuencia de diseño**: como las dos casillas particionan el universo, destildar ambas produce un
libro vacío. Se impide (FR-020) en lugar de permitir un envío inútil.

---

## Decisión 5 — Los archivos salen de las mismas fuentes que las descargas

**Elegido**: los adjuntos se generan con los mismos componentes que ya usan las descargas del informe
(spec 077 para los XLSX, spec 086 para el IVA Digital).

**Por qué**: SC-003 exige que lo que llega por correo sea idéntico a lo que se descarga. La única forma
de garantizarlo es que sea **el mismo código**, no dos caminos que casualmente coinciden. Una segunda
derivación de los mismos números es una divergencia futura garantizada, y en este caso la divergencia
la descubriría el contador liquidando impuestos.

---

## Decisión 6 — Nombres de archivo del correo ≠ nombres de la descarga

**Observación**: los adjuntos del correo se llaman `IVA Ventas Marzo - 2026.xlsx`, mientras que la
descarga directa del informe usa hoy `Libro IVA Ventas 03-2026.xlsx`, y el ZIP de la spec 086 se llama
`IVA Digital Ventas y Compras Agosto 2026.zip` pero en el panel del correo figura como
`IVA Digital Marzo - 2026.zip`.

**Elegido**: respetar los nombres de las capturas para los adjuntos del correo, y dejar los de la
descarga como están.

**Por qué**: son dos contextos distintos y las capturas son fuente de verdad para éste. El nombre que
ve el contador en su bandeja debe ser el relevado. No se unifican por prolijidad: unificarlos
cambiaría un nombre ya relevado, que es precisamente lo que el principio rector prohíbe.

**Consecuencia**: el componente que arma el correo decide el nombre del adjunto; el generador del
archivo no lo impone. Vale la pena que quede explícito porque es una fuente clásica de confusión al
implementar.

---

## Decisión 7 — Guardar constancia de cada envío

**Elegido**: registrar destinatarios, período, archivos y resultado de cada envío.

**Por qué**: el correo sale del sistema hacia afuera y su destinatario es alguien externo al negocio.
Cuando el contador dice "no me llegó" —que pasa— sin registro no hay forma de responder si se envió, a
quién y con qué. Es también lo que permite detectar que los envíos vienen fallando en silencio, en línea
con el módulo de notificaciones de fallas que el usuario ya pidió para las integraciones.
