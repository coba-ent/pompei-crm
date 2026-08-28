# Data Model: Enviar Información a tu Contador por Correo (spec 087)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

---

## 1. Tabla nueva: `envios_contador`

Única tabla nueva de la spec. Da constancia de cada envío (FR-024).

| Campo | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `user_id` | FK → `users` | quién lo envió |
| `destinatarios` | text | direcciones separadas por coma, tal como se enviaron |
| `copia_remitente` | bool | si se mandó copia al usuario |
| `anio` | smallint | período |
| `mes` | tinyint **nullable** | `null` = envío anual. **Es el discriminador** entre los dos modos |
| `incluye_electronicas` | bool | casilla |
| `incluye_manuales` | bool | casilla |
| `incluye_pdfs` | bool | casilla |
| `archivos` | json | nombres de los adjuntos efectivamente enviados |
| `asunto` | string | tal como salió (el usuario pudo editarlo) |
| `estado` | string | `pendiente` / `enviado` / `fallido` |
| `error` | text nullable | motivo cuando falló |
| `enviado_en` | timestamp nullable | |
| `timestamps` | | |

**Por qué se guarda `archivos` y no sólo las casillas**: las reglas de qué archivo corresponde pueden
cambiar con el tiempo. Guardar los nombres reales permite responder "esto fue lo que se le mandó" sin
tener que reconstruirlo con la lógica de hoy, que es justamente lo que se necesita cuando el contador
reclama meses después.

**Por qué `mes` es nullable y no `0`**: la distinción año-solo / año-y-mes decide qué archivos van, cómo
se llaman y qué dice el correo. `null` la expresa; `0` sería un mes inválido disfrazado de dato.

---

## 2. Configuración: mail del contador

Se guarda **un solo dato nuevo** en la configuración existente del sistema:

| Campo | Notas |
|---|---|
| mail del contador | precarga el destinatario del modal (FR-003); puede tener varias direcciones separadas por coma |

El nombre del negocio para el asunto (FR-004) **ya existe** como `razon_social` en los datos de la
empresa: no se agrega nada para eso.

---

## 3. Objetos de valor (sin persistencia)

### `Periodo`

`anio` obligatorio, `mes` opcional. Expone si es mensual o anual. Es el que decide, en un solo lugar:

| | Año solo | Año y mes |
|---|---|---|
| XLSX IVA Ventas | `IVA Ventas - {Año}.xlsx` | `IVA Ventas {Mes} - {Año}.xlsx` |
| XLSX IVA Compras | `IVA Compras - {Año}.xlsx` | `IVA Compras {Mes} - {Año}.xlsx` |
| IVA Digital | **no corresponde** | `IVA Digital {Mes} - {Año}.zip` |
| PDFs (si tildado) | *(ver nota)* | `PDFs Facturas de Venta {Mes} - {Año}.zip` |
| Cuerpo del correo | "del año {Año}" | "del mes de {Mes} de {Año}" |

> **Nota sobre PDFs en modo anual**: las capturas sólo muestran la casilla de PDFs con un mes elegido.
> Un ZIP con los PDF de un año entero sería enorme. Se resuelve ofreciendo los PDF **sólo en modo
> mensual**, igual que el IVA Digital — decisión conservadora y coherente, marcada como asunción en la
> spec por no estar cubierta por el relevamiento.

### `OpcionesEnvio`

`incluyeElectronicas`, `incluyeManuales`, `incluyePdfs`. **Invariante**: al menos una de las dos
primeras es verdadera (FR-020), garantizada al construirse.

---

## 4. Origen de los archivos adjuntos

Ninguna derivación nueva de números (FR-026):

| Adjunto | Origen |
|---|---|
| IVA Ventas `.xlsx` | export del libro IVA Ventas de la spec 077, con el filtro de las casillas |
| IVA Compras `.xlsx` | export del libro IVA Compras de la spec 077 |
| IVA Digital `.zip` | `IvaDigitalPaquete` de la spec 086 |
| PDFs Facturas de Venta `.zip` | generador de PDF de facturas ya existente |
| Adjuntos propios | subidos por el usuario en el modal |

---

## 5. Invariantes

1. Los nombres que muestra el panel son **exactamente** los de los archivos enviados (SC-004).
2. Un adjunto generado es idéntico al que se descargaría desde la pantalla para el mismo período y las
   mismas casillas (SC-003).
3. No existe un envío con `incluye_electronicas = false` y `incluye_manuales = false`.
4. Todo envío deja registro, exitoso o fallido (FR-024).
