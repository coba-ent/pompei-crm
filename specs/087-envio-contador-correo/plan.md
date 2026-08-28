# Implementation Plan: Enviar Información a tu Contador por Correo (spec 087)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

---

## Resumen técnico

Se agrega un modal al informe del contador que arma y envía un correo con los archivos del período. El
grueso del trabajo no es el envío en sí —Laravel ya lo resuelve— sino **mantener una sola verdad sobre
qué archivos corresponden** a un período y unas casillas dadas, y que esa verdad sea la misma que ve el
usuario en el panel, la que se genera al enviar y la que se descarga desde la pantalla.

---

## Arquitectura

```
Modal (Blade + JS)
   │  cambio de período / casillas  → AJAX
   ▼
InformeContadorController::adjuntosPrevistos()   ← devuelve la lista, NO los archivos
   │
   ▼
PaqueteContador  ◄─────────────────────────────── única fuente de "qué corresponde enviar"
   │   .listar(periodo, opciones): array de nombres
   │   .generar(periodo, opciones): array de archivos
   │
   ├── LibroIvaExport            (spec 077, sin cambios)
   ├── IvaDigitalPaquete         (spec 086, sin cambios)
   └── PdfsFacturasVentaPaquete  (nuevo)

Modal → Enviar (AJAX) → EnvioContadorController::enviar()
   │
   ▼
EnviarInformacionContador (job encolado)
   ├── PaqueteContador::generar()
   ├── CorreoContador (Mailable)
   └── EnvioContador (registro de constancia)
```

**La pieza clave es `PaqueteContador`**, y su razón de existir es SC-004: el panel muestra
`listar()` y el envío usa `generar()`. Ambas responden a la misma decisión, escrita una sola vez. Si
en cambio el JS armara la lista por su cuenta —que es lo natural y lo más rápido de escribir— la lista
y el envío se separarían en la primera modificación, y el usuario recibiría un correo distinto del que
se le anunció.

---

## Componentes

### 1. `PaqueteContador` (nuevo — `app/Services/Informes/Contador/`)

- `listar(Periodo $p, OpcionesEnvio $o): array` — nombres de archivo previstos, sin generar nada.
- `generar(Periodo $p, OpcionesEnvio $o): array` — los archivos, ya nombrados igual que en `listar()`.

Contiene las reglas de FR-009 a FR-012a: panel vacío sin período, XLSX anuales con año solo, IVA
Digital sólo con mes, PDFs sólo si la casilla está tildada. Los nombres son los de las capturas
(research §6).

### 2. `OpcionesEnvio` / `Periodo` (nuevos, objetos de valor)

`Periodo` distingue explícitamente **año solo** de **año y mes**, porque esa distinción decide tres
cosas distintas (qué archivos, cómo se llaman, qué dice el cuerpo del correo) y pasarla como un mes
nulo suelto la desparramaría en condicionales por todo el código.

`OpcionesEnvio` valida la regla de FR-020 (no ambas casillas destildadas) en el constructor: así es
imposible construir un envío inválido, con o sin validación de formulario.

### 3. `PdfsFacturasVentaPaquete` (nuevo)

ZIP con los PDF de las facturas de venta del mes, reutilizando el generador de PDF existente.

### 4. `CuerpoCorreoContador` (nuevo)

Arma asunto y cuerpo a partir del período, el destinatario y la lista de `listar()`. Incluye FR-014
(texto anual bien redactado). Aislado porque es puro texto y conviene testearlo sin correo de por medio.

### 5. `EnviarInformacionContador` (job encolado)

Genera, envía y registra. Encolado por research §2.

### 6. `CorreoContador` (Mailable) y `EnvioContador` (registro)

### 7. Endpoints y UI

- `POST informes/contador/adjuntos-previstos` — alimenta el panel en vivo.
- `POST informes/contador/enviar` — encola el envío.
- Modal en la vista de la 077, con Select2 para Año/Mes (regla 5 de `CLAUDE.md`), toasts para el
  resultado (regla 3), y sin recarga de página (regla 2).

---

## Configuración

El mail del contador y el nombre del negocio salen de la configuración existente. `razon_social` de los
datos de la empresa alimenta el asunto (FR-004). Si no existe un lugar donde guardar el mail del
contador, se agrega **ese único campo** a la configuración ya existente — no una pantalla nueva.

---

## Dependencia operativa: el worker de cola

FR-021 pide envío en segundo plano. Hoy la cola del proyecto está en modo `sync`, con lo cual encolar
ejecuta en el acto: **el código correcto no alcanza**, hace falta un worker corriendo en el VPS.

Esto se trata como parte de la feature, no como un detalle de infraestructura para después: sin worker,
el envío del cierre mensual con PDFs sigue bloqueando la request, que es justo el escenario que FR-021
quiere evitar. Si al implementar se decide no levantar el worker todavía, la consecuencia debe quedar
dicha explícitamente (el envío será síncrono y puede cortar por tiempo), no descubrirse en producción
un 30 a la noche.

---

## Estrategia de test

1. **`PaqueteContador`** — los cuatro estados del panel de las capturas: sin período, año solo, año y
   mes, y con PDFs. Es la traducción directa de la tabla del relevamiento.
2. **Coherencia listar/generar (SC-004)** — para cada combinación de período y casillas, los nombres
   que devuelve `listar()` son exactamente los de los archivos que devuelve `generar()`. Este test es
   el que sostiene toda la decisión de arquitectura.
3. **`OpcionesEnvio`** — no se puede construir con ambas casillas destildadas (FR-020).
4. **`CuerpoCorreoContador`** — texto mensual vs. anual (FR-014); la lista de archivos del cuerpo
   coincide con la de los adjuntos.
5. **Filtro Electrónicas/Manuales (FR-025)** — el libro adjunto respeta las casillas, apoyándose en la
   clasificación de la 077 (research §4, con su gotcha 1→N).
6. **Envío** — destinatarios múltiples, copia al remitente, direcciones inválidas rechazadas antes de
   enviar, y el correo llega con los adjuntos esperados (con mailer de prueba, sin salir a internet).
7. **Idéntico a la descarga (SC-003)** — el adjunto generado y la descarga del mismo período producen
   el mismo contenido.
8. **Errores** — si el envío falla, queda registrado como fallido y el usuario se entera (FR-019).

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El panel anuncia algo distinto de lo que se envía | `PaqueteContador` como única fuente; test de coherencia listar/generar |
| El envío bloquea la request y el usuario no sabe si salió | Job encolado + worker (dependencia operativa explícita arriba) |
| Adjuntos que exceden el límite del servidor de correo | Verificar el tamaño **antes** de enviar y avisar (FR-022) |
| Doble envío por doble clic | Bloquear el botón y validar en el servidor (FR-023) |
| Divergencia entre lo enviado y lo descargado | Mismo código de generación (research §5); test SC-003 |
| Se "unifican" los nombres de adjunto con los de descarga por prolijidad | Documentado como deliberado en research §6 |
| Correo real enviado desde una prueba | Los tests usan mailer de prueba; **nunca** probar el envío contra el VPS (memoria del proyecto) |

---

## Fuera de alcance

Configuración del servidor de correo (spec 081); formato de los archivos (specs 077 y 086); envío
automático programado.
