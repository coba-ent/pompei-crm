# CONTAGRAM — Remitos

## Informe completo: creación y visualización

*Documento de investigación — combina la documentación oficial (help.contagram.com) con una verificación práctica hecha directamente en una cuenta de prueba de Contagram (creación de un remito real sobre una venta existente, edición y prueba de un segundo remito) · Agosto 2026*

---

## Contenido

1. Qué es un remito en Contagram
2. Concepto de datos: atributos de un remito
3. Cómo crear un remito (paso a paso verificado)
4. Cómo visualizar un remito
5. Editar y eliminar un remito
6. Varios remitos por venta
7. Particularidades y hallazgos prácticos
8. Fuentes

---

## 1. Qué es un remito en Contagram

Un remito es el comprobante que documenta la **entrega física** de la mercadería de una venta: qué productos, en qué cantidad, a través de qué transportista y a qué domicilio. Es un documento interno/logístico, no fiscal.

Punto clave de la documentación oficial:

> "El stock es afectado al momento de vender o comprar, no al momento de emitir el remito. No son remitos oficiales con CAE, pero sirven en la mayoría de los casos."

Esto significa dos cosas:

- El **descuento de stock ya ocurrió** cuando se creó la venta; el remito es solo un comprobante de traslado, no dispara ningún movimiento de inventario adicional.
- El remito de Contagram **no tiene CAE** (no es un remito electrónico oficial ante AFIP/ARCA). Sirve como control interno y para el transportista, pero no reemplaza a un remito fiscal en los casos donde la ley lo exige.

---

## 2. Concepto de datos: atributos de un remito

| Campo | Descripción |
|---|---|
| **Cliente** | Se hereda automáticamente de la venta origen (no editable). |
| **Domicilio de Entrega** | Se precarga con la dirección del cliente; es editable si la entrega es en otro lugar. |
| **Emisión** | Fecha de emisión del remito. |
| **Tipo** | Letra del comprobante (**X** o **R**); en la cuenta de prueba, sin facturación ARCA activa, ambas opciones aparecen deshabilitadas. |
| **N° de comprobante** | Campo numérico manual (formato `____-________`), separado de la numeración de la venta. |
| **Transportista** | Selector con buscador; permite crear un transportista nuevo al vuelo (solo pide **Nombre**). |
| **Nota para el Cliente** | Texto libre a nivel remito completo. |
| **Líneas de producto** | Se precargan automáticamente desde la venta: producto, observaciones (por línea) y cantidad — todo editable. |
| **Total Bultos** | Se autocalcula sumando las cantidades de todas las líneas. |
| **Monto Asegurado** | Checkbox + campo de importe. Desactivado por defecto; al tildarlo, se habilita editable y viene precargado con el total de la venta. |

A diferencia de una venta o una NC/ND, el remito **no tiene campos de precio, IVA o impuestos** — es puramente logístico (qué se entrega, no cuánto vale).

---

## 3. Cómo crear un remito (paso a paso verificado)

Ruta: desde una **venta ya creada** — Ingresos → Ventas.

1. En el listado de ventas, hacé clic en la flechita de opciones junto al **Estado** de la venta y elegí **Crear Remito** (también disponible como botón "Crear Remito" arriba a la derecha dentro del detalle de la venta).
2. Se abre el formulario **"Nuevo Remito Venta ID [n]"**, ya precargado con: cliente, domicilio de entrega, y todas las líneas de productos de la venta con sus cantidades originales.
3. Elegí o creá un **Transportista** (buscador con opción "Crear Transportista" — modal simple que solo pide un nombre).
4. Completá, si querés, el **Tipo** de comprobante y el **N° de comprobante** manual.
5. Agregá una **Nota para el Cliente** (texto general) y, por línea de producto, una **Observación** puntual (por ejemplo, "verificar talles").
6. Ajustá las **cantidades** por producto si el envío es parcial (por defecto trae las cantidades totales de la venta).
7. Si querés declarar un valor asegurado del envío, tildá **Monto Asegurado** — se habilita el campo, precargado con el total de la venta, editable.
8. Revisá el **Total Bultos** (se recalcula solo).
9. Hacé clic en **Guardar**.

Al guardar aparece la confirmación **"Remito [n] creado con éxito"** con un acceso directo **"Ver Remito"**, y en el detalle de la venta se agrega una nueva sección **"Remitos"** (franja amarilla), estructuralmente igual a la de "Cobranzas": Id, Fecha, Transportista, Nota, Total Bultos y Comprobante (con enlace **Ver Remito** y un ícono de lápiz para editar).

---

## 4. Cómo visualizar un remito

Desde el detalle de la venta, en la sección **Remitos**, el enlace **Ver Remito** abre un **PDF** con:

- Encabezado **"REMITO"** con la letra del comprobante (X en el caso probado).
- **Nro. Remito** (queda en blanco si no se facturó/numeró oficialmente) y **Fecha de Emisión**.
- **Transportista**.
- Datos del cliente: Apellido y Nombre/Razón Social, Teléfono, Persona de Contacto, Condición IVA y CUIT (estos dos últimos en blanco si el cliente no tiene datos fiscales cargados), y el **Domicilio de Entrega**.
- Tabla de **Código / Productos / Observaciones / Cantidad** — sin precios, sin montos, sin IVA.

El **Monto Asegurado** que se carga al crear el remito **no aparece impreso en el PDF** — es un dato interno del sistema, no parte del comprobante visible.

---

## 5. Editar y eliminar un remito

Desde la sección "Remitos" del detalle de la venta, el ícono de lápiz junto a "Ver Remito" abre **"Editar Remito Venta ID [n]"**, con el mismo formulario de creación, pero con una diferencia importante frente a las Notas de Crédito/Débito: **acá no hay ningún campo bloqueado**. Cliente, transportista, nota, observaciones, cantidades y Monto Asegurado son todos editables libremente después de creado el remito. El formulario de edición incluye, además de Cancelar y Guardar, un botón **Eliminar** directo.

---

## 6. Varios remitos por venta

El botón **"Crear Remito"** sigue disponible en el detalle de la venta incluso después de haber creado uno (pensado para envíos parciales: por ejemplo, entregar hoy una parte del pedido y el resto después). Al crear un segundo remito sobre la misma venta se comprobó que:

- El formulario vuelve a precargar las **cantidades totales originales de la venta** en cada línea de producto — el sistema **no descuenta ni recuerda** lo que ya se remitió en el remito anterior.
- Es responsabilidad del usuario ajustar manualmente las cantidades de cada remito parcial; Contagram no lleva un control automático de "cantidad pendiente de remitir".

---

## 7. Particularidades y hallazgos prácticos

- **No mueve stock:** crear, editar o eliminar un remito no afecta el inventario — el stock ya se descontó al crear la venta.
- **No es fiscal:** sin CAE; el N° de Remito queda vacío si no hay facturación electrónica configurada, igual que ocurre con el "Tipo" de comprobante (X/R aparecen deshabilitados sin ARCA activo).
- **El transportista es una entidad propia**, reutilizable entre remitos, con un único atributo (Nombre) — no pide CUIT, patente ni contacto en el alta rápida.
- **Domicilio editable:** aunque se hereda del cliente, se puede cambiar puntualmente para ese envío, sin afectar el domicilio guardado en la ficha del cliente.
- **Monto Asegurado es opcional y no se imprime:** funciona como un dato de referencia interna (por ejemplo, para el seguro del transportista), no como parte del comprobante.
- **Sin control de cantidades entregadas:** al permitir múltiples remitos por venta sin descontar lo ya remitido, el seguimiento de envíos parciales queda a cargo del usuario (por ejemplo, usando las Observaciones o la Nota para anotar qué remito corresponde a qué parte del pedido).
- **Edición sin restricciones:** a diferencia de las NC/ND (donde el tipo y si afecta stock quedan bloqueados tras crear la nota), el remito se puede reeditar por completo en cualquier momento.

---

## 8. Fuentes

Documentación oficial de Contagram (help.contagram.com), consultada en agosto de 2026, más verificación práctica directa en la aplicación (cuenta de prueba, agosto 2026):

- Remitos — help.contagram.com/es/articles/1319079-remitos (Módulo de ventas)
- Recorrido práctico en app.contagram.com: creación de un remito sobre la Venta 5 (cliente María Emilia López), alta de transportista, visualización en PDF, edición y prueba de un segundo remito — 12 capturas de pantalla en la carpeta **Capturas-Remitos**

> **Nota:** informe elaborado a partir de la documentación de ayuda pública de Contagram y de un recorrido real hecho en una cuenta de prueba. Los hallazgos de las secciones 6 y 7 fueron observados directamente en la interfaz y pueden variar según el plan de la cuenta o futuras actualizaciones de la app.
