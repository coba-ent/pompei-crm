# Research: IVA Digital — archivos del régimen RG 3685 (spec 086)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md)

Todas las decisiones de abajo salen de **decodificar los archivos reales** de `contador/` campo por
campo, no de documentación de terceros. Donde el fixture y una fuente externa podrían diferir, manda
el fixture: es lo que ARCA efectivamente aceptó para este contribuyente.

---

## Decisión 1 — Layout exacto de los cuatro archivos

Decodificados posición por posición sobre el fixture. Estos son los cuatro contratos:

### Comprobantes Ventas — 266 caracteres

| Desde | Hasta | Ancho | Campo | Tipo | Ejemplo (línea 1) |
|---:|---:|---:|---|---|---|
| 0 | 8 | 8 | Fecha de comprobante | `AAAAMMDD` | `20260803` |
| 8 | 11 | 3 | Tipo de comprobante | num | `006` |
| 11 | 16 | 5 | Punto de venta | num | `00005` |
| 16 | 36 | 20 | Número de comprobante desde | num | `…5669` |
| 36 | 56 | 20 | Número de comprobante hasta | num | `…5669` |
| 56 | 58 | 2 | Código de documento del comprador | num | `96` |
| 58 | 78 | 20 | Número de identificación del comprador | num | `…18209989` |
| 78 | 108 | 30 | Denominación del comprador | alfa | `Fabio Humberto Maidana` |
| 108 | 123 | 15 | Importe total de la operación | num (centavos) | `000000018967617` |
| 123 | 138 | 15 | Importe total conceptos que no integran el neto gravado | num | `0…0` |
| 138 | 153 | 15 | Percepción a no categorizados | num | `0…0` |
| 153 | 168 | 15 | Importe operaciones exentas | num | `0…0` |
| 168 | 183 | 15 | Importe percepciones/pagos a cuenta de IVA | num | `0…0` |
| 183 | 198 | 15 | Importe de percepciones de IIBB | num | `0…0` |
| 198 | 213 | 15 | Importe de percepciones de impuestos municipales | num | `0…0` |
| 213 | 228 | 15 | Importe de impuestos internos | num | `0…0` |
| 228 | 231 | 3 | Código de moneda | alfa | `PES` |
| 231 | 241 | 10 | Tipo de cambio | num (6 decimales) | `0001000000` |
| 241 | 242 | 1 | Cantidad de alícuotas de IVA | num | `1` |
| 242 | 243 | 1 | Código de operación | alfa | `0` |
| 243 | 258 | 15 | Otros tributos | num | `0…0` |
| 258 | 266 | 8 | Fecha de vencimiento de pago | `AAAAMMDD` | `20260803` |

### Alícuotas Ventas — 62 caracteres

| Desde | Hasta | Ancho | Campo | Ejemplo |
|---:|---:|---:|---|---|
| 0 | 3 | 3 | Tipo de comprobante | `006` |
| 3 | 8 | 5 | Punto de venta | `00005` |
| 8 | 28 | 20 | Número de comprobante | `…5669` |
| 28 | 43 | 15 | Importe neto gravado | `000000015675716` |
| 43 | 47 | 4 | Alícuota de IVA (código) | `0005` |
| 47 | 62 | 15 | Impuesto liquidado | `000000003291900` |

> **Nota**: a diferencia del de Compras, este registro **no** lleva código ni número de documento.
> Confundir ambos layouts corre 22 caracteres y rompe el archivo entero.

### Comprobantes Compras — 325 caracteres

| Desde | Hasta | Ancho | Campo | Ejemplo (línea 1) |
|---:|---:|---:|---|---|
| 0 | 8 | 8 | Fecha de comprobante | `20260731` |
| 8 | 11 | 3 | Tipo de comprobante | `001` |
| 11 | 16 | 5 | Punto de venta | `00052` |
| 16 | 36 | 20 | Número de comprobante | `…207552` |
| 36 | 52 | 16 | Despacho de importación | *(espacios)* |
| 52 | 54 | 2 | Código de documento del vendedor | `80` |
| 54 | 74 | 20 | Número de identificación del vendedor | `…30501991070` |
| 74 | 104 | 30 | Denominación del vendedor | `JOHNSON ACERO S. A. INDUSTRIAL` |
| 104 | 119 | 15 | Importe total de la operación | `000000014977618` |
| 119 | 134 | 15 | Importe total de conceptos que no integran el neto gravado | `0…0` |
| 134 | 149 | 15 | Importe de operaciones exentas | `0…0` |
| 149 | 164 | 15 | Importe de percepciones/pagos a cuenta de IVA | `000000000353802` |
| 164 | 179 | 15 | Importe de percepciones de otros impuestos nacionales | `0…0` |
| 179 | 194 | 15 | Importe de percepciones de IIBB | `000000000353802` |
| 194 | 209 | 15 | Importe de percepciones de impuestos municipales | `0…0` |
| 209 | 224 | 15 | Importe de impuestos internos | `0…0` |
| 224 | 227 | 3 | Código de moneda | `PES` |
| 227 | 237 | 10 | Tipo de cambio | `0001000000` |
| 237 | 238 | 1 | Cantidad de alícuotas de IVA | `1` |
| 238 | 239 | 1 | Código de operación | `0` |
| 239 | 254 | 15 | Crédito fiscal computable | `000000002476614` |
| 254 | 269 | 15 | Otros tributos | `0…0` |
| 269 | 280 | 11 | CUIT emisor / vendedor por cuenta de terceros | `00000000000` |
| 280 | 310 | 30 | Denominación del emisor por cuenta de terceros | *(espacios)* |
| 310 | 325 | 15 | IVA comisión | `0…0` |

### Alícuotas Compras — 84 caracteres

| Desde | Hasta | Ancho | Campo | Ejemplo |
|---:|---:|---:|---|---|
| 0 | 3 | 3 | Tipo de comprobante | `001` |
| 3 | 8 | 5 | Punto de venta | `00052` |
| 8 | 28 | 20 | Número de comprobante | `…207552` |
| 28 | 30 | 2 | Código de documento del vendedor | `80` |
| 30 | 50 | 20 | Número de identificación del vendedor | `…30501991070` |
| 50 | 65 | 15 | Importe neto gravado | `000000011793400` |
| 65 | 69 | 4 | Alícuota de IVA (código) | `0005` |
| 69 | 84 | 15 | Impuesto liquidado | `000000002476614` |

---

## Decisión 2 — Codificación latin-1 y CRLF, no UTF-8

**Elegido**: escribir en ISO-8859-1 con terminador `\r\n`, incluida la última línea.

**Por qué**: verificado en el fixture. Y no es cosmético: el ancho del registro se mide en **bytes**.
Un proveedor llamado `Peirano Muñoz` escrito en UTF-8 ocupa 14 bytes para 13 caracteres, corriendo
todo lo que sigue y rompiendo el archivo desde esa línea en adelante. Laravel trabaja en UTF-8 por
defecto, así que la conversión debe ser **explícita** en el punto de escritura.

**Riesgo**: un carácter que no existe en latin-1 (por ejemplo un emoji pegado en el nombre de un
cliente) no tiene representación. Se translitera o se reemplaza antes de padear, nunca después: si se
reemplaza después del padding, el ancho ya quedó mal.

---

## Decisión 3 — Reutilizar `LibroIvaVentasQuery` / `LibroIvaComprasQuery`, no reconsultar

**Elegido**: la generación consume las queries que ya construyó la spec 077.

**Por qué**: son el único lugar donde vive la resolución de período fiscal (incluido el
`COALESCE(mes_imputacion_iva, fecha_emision)` de compras y el `mes_imputacion` de NC/ND) y la
derivación de netos por alícuota. Duplicar esa lógica para el TXT garantizaría que en algún momento el
informe en pantalla y el archivo de ARCA muestren números distintos del mismo período — el peor
resultado posible, porque el usuario revisa en pantalla y presenta el archivo.

**Alternativa descartada**: query propia optimizada para el formato de ancho fijo. Más rápida de
escribir, pero rompe el principio I de la constitución y la invariante que hace confiable al informe.

---

## Decisión 4 — Emitir el total almacenado, no la suma de componentes

**Elegido**: `Importe total de la operación` sale del total del comprobante tal como está guardado.

**Por qué**: verificado que en el fixture 4 de 29 ventas y 1 de 27 compras tienen ±1 centavo de
diferencia contra la suma de sus partes, por doble redondeo (IVA por alícuota vs. total). El total
declarado ante ARCA en el CAE es el total del comprobante; si el TXT trae otro número, la declaración
no concilia con la factura electrónica ya emitida. La coherencia interna del archivo importa menos que
la coherencia con lo ya declarado.

**Contraste deliberado con la spec 077**: allí la barra de totales **sí** se corrige para que la
ecuación cierre, porque es un número informativo en pantalla. Acá es un número declarativo. Misma
aritmética, criterios opuestos, y la spec lo dice explícitamente para que no parezca un descuido.

---

## Decisión 5 — Corregir el defecto de "Cantidad de alícuotas" del origen

**Elegido**: emitir el conteo real de filas de alícuota escritas para ese comprobante.

**Por qué**: el fixture tiene dos comprobantes de compra de MercadoLibre que declaran `0` alícuotas
pero traen una fila de alícuota al 21% y crédito fiscal computable de $605,70. Es internamente
contradictorio. Construir el campo como `count()` de lo que efectivamente se escribió hace que la
inconsistencia sea **imposible por construcción**, en lugar de depender de que un campo derivado se
mantenga sincronizado.

**Consecuencia aceptada**: el archivo del CRM no será byte-idéntico al de Contagram en esos dos
registros. El test de fidelidad lo contempla como excepción nombrada (FR-022), no con una tolerancia
genérica que taparía regresiones reales.

---

## Decisión 6 — Códigos ARCA: reutilizar `MapeadorComprobante`

**Elegido**: los códigos de tipo de comprobante, tipo de documento y alícuota salen del
`MapeadorComprobante` que ya usa la facturación electrónica.

**Por qué**: ya existe y está probado contra ARCA en producción. Sus constantes coinciden exactamente
con lo observado en el fixture: `ALICUOTAS_IVA['21'] = 5` → campo `0005`; `DOC_TIPOS['CUIT'] = 80` y
`['DNI'] = 96` → los códigos `80` y `96` del fixture; `CBTE_TIPO_FACTURA['B'] = 6` → tipo `006`.
Mantener una segunda tabla de códigos sería una fuente garantizada de divergencia.

**Brecha a cubrir**: el fixture tiene tipo de documento `99` (sin identificar) que hoy no está en
`DOC_TIPOS`, y tipos de comprobante de compra que el mapeador no necesita porque sólo emite ventas. Se
extiende el mapeador con lo que falte, en lugar de crear una tabla paralela.

---

## Decisión 7 — Escritura en streaming a archivo temporal, no en memoria

**Elegido**: cada TXT se escribe línea por línea a un archivo temporal y luego se agrega al ZIP.

**Por qué**: un período mensual del cliente ronda las 30 líneas, pero el mismo código debe poder
generar un período histórico con miles de comprobantes. Armar el contenido completo en un string en
memoria escala mal y no aporta nada: el formato es línea a línea, sin encabezado ni totales al pie que
requieran conocer el archivo entero de antemano.

---

## Decisión 8 — Orden determinístico explícito

**Elegido**: ordenar por fecha ascendente y, a igual fecha, por identificador ascendente — el mismo
orden que ya usa el informe en pantalla.

**Por qué**: sin un desempate explícito, MySQL puede devolver dos generaciones del mismo período en
orden distinto, y el archivo dejaría de ser reproducible (SC-005). Además coincide con el orden del
fixture, lo que permite comparar línea a línea contra él en los tests.
