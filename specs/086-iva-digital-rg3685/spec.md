# Feature Specification: IVA Digital — archivos del régimen RG 3685

**Feature Branch**: `086-iva-digital-rg3685`

**Created**: 2026-08-27

**Status**: Draft

**Input**: Generación de los 4 archivos TXT de ancho fijo del régimen ARCA "IVA Digital" (RG 3685) del período, más el ZIP que los agrupa, como nuevo entregable del informe "Información para tu Contador" (spec 077).

---

## Contexto y fuente de verdad

Esta spec **no se apoya en capturas de pantalla** sino en algo más fuerte: los **archivos reales
generados por Contagram** para el período Agosto 2026 sobre la cuenta del cliente (Pompei Sanitarios),
provistos por el usuario el 27/08/2026 y guardados en `contador/` en la raíz del repo:

| Archivo | Bytes | Líneas | Ancho de línea |
|---|---|---|---|
| `Comprobantes Ventas Agosto 2026 Res 3685.txt` | 7.772 | 29 | 266 |
| `Alicuotas Ventas Agosto 2026 Res 3685.txt` | 1.856 | 29 | 62 |
| `Comprobantes Compras Agosto 2026 Res 3685.txt` | 8.829 | 27 | 325 |
| `Alicuotas Compras Agosto 2026 Res 3685.txt` | 2.322 | 27 | 84 |
| `IVA Digital Ventas y Compras Agosto 2026.zip` | 4.068 | — | contiene los 4 anteriores |

Esos archivos son la **fuente de verdad estructural** de esta spec, en el sentido del principio rector
de `CLAUDE.md`, y además funcionan como **fixture de test**: cada requisito de layout de abajo fue
verificado decodificando los archivos campo por campo, no inferido de documentación.

**Por qué esta feature va separada del envío por correo (spec 087)**: estos archivos los importa el
contador directamente en el aplicativo de ARCA. Un solo carácter corrido de posición y **ARCA rechaza
el archivo entero** — no hay degradación parcial. Ese riesgo merece su propia spec, sus propios tests
posicionales y su propia verificación, en lugar de quedar diluido entre la UI de un modal de correo.

**Relación con la spec 077**: 077 construyó el informe "Información para tu Contador" (tabs IVA Ventas
/ IVA Compras, filtros, totales, export XLSX). Esta spec **reutiliza sin modificar** su resolución de
período fiscal (`data-model.md §2`), su clasificación ARCA/manual (§3) y su derivación de netos, IVA
por alícuota y percepciones (§4). No cambia ninguna de esas reglas: sólo agrega un formato de salida.

---

## Clarifications

### Session 2026-08-27

Resueltas decodificando los archivos reales, sin necesidad de consultar al usuario.

- Q: ¿Qué codificación y terminador de línea usan los archivos? → A: **latin-1 (ISO-8859-1) con CRLF
  (`\r\n`)**, sin BOM, sin fila de encabezado, y **con** terminador en la última línea. Verificado con
  inspección byte a byte de los cuatro archivos. Importa: los nombres de clientes y proveedores
  argentinos llevan acentos y `Ñ`, y en UTF-8 ocuparían 2 bytes, corriendo todas las posiciones
  siguientes del registro.

- Q: ¿Los importes llevan separador decimal o signo? → A: **No.** Son enteros de ancho fijo con
  padding de ceros a la izquierda que representan **centavos** (los últimos 2 dígitos son los
  decimales). Ejemplo verificado: `000000018967617` = $189.676,17. Los importes negativos (notas de
  crédito) **no** se representan con signo: el tipo de comprobante ya indica el signo, y todas las NC
  del fixture aparecen con importes positivos.

- Q: ¿Cómo se alinean los campos alfanuméricos? → A: **Alineados a la izquierda, rellenados con
  espacios a la derecha, y truncados** al ancho del campo. Verificado en `Denominación` (30 chars):
  `BRITOS TRAVIESO, ROSSANA TERES` está truncado exactamente en 30 caracteres, sin puntos suspensivos.
  Los campos numéricos, en cambio, van alineados a la derecha con ceros.

- Q: ¿El total del comprobante se recalcula como suma de sus componentes? → A: **No — se emite el total
  almacenado del comprobante, aunque no cierre exactamente con la suma de sus partes.** En el fixture,
  4 de 29 comprobantes de venta y 1 de 27 de compra tienen un desvío de **±1 centavo** respecto de
  `neto + IVA + no gravado + exento + percepciones + otros tributos`. La causa está verificada: el IVA
  se redondea por alícuota y el total del comprobante se redondea por separado (ej. venta 5669: neto
  $156.757,16 × 21% = $32.919,0036, que redondea a $32.919,00, mientras el total guardado es
  $189.676,17 y la suma da $189.676,16). Se replica el comportamiento de Contagram porque el total
  emitido debe coincidir con el total del comprobante que el cliente ya declaró ante ARCA — recalcularlo
  produciría una diferencia contra el CAE. **Esto es una divergencia deliberada** respecto del criterio
  de la spec 077, que sí corrige la deriva de redondeo en la barra de totales del informe en pantalla:
  ahí la ecuación es informativa y debe cerrar; acá el número debe coincidir con lo declarado.

- Q: ¿Todos los comprobantes tienen al menos una fila de alícuota? → A: **Sí en el fixture** (29/29 y
  27/27), pero **el campo "Cantidad de alícuotas" no siempre coincide con la cantidad real de filas**.
  Ver la sección "Defecto detectado en el origen" más abajo: es un bug de Contagram que esta spec
  **no** replica.

---

## Defecto detectado en el origen (no se replica)

Al validar el fixture apareció una **inconsistencia real en los archivos que genera Contagram**, que
esta spec corrige deliberadamente en lugar de calcar:

Dos comprobantes de compra de MercadoLibre (tipo `006`, nros. 2130918 y 2335174, $3.490,00 cada uno)
declaran **`Cantidad de alícuotas = 0`** y **`Código de operación = ' '` (espacio en blanco)**, pero
**sí tienen** una fila en el archivo de alícuotas (neto $2.884,30, IVA 21%, $605,70) y **sí declaran**
crédito fiscal computable de $605,70 en el registro de comprobantes.

Los tres datos no pueden ser ciertos a la vez. Según el diseño del régimen, cuando la cantidad de
alícuotas es `0` el código de operación debe indicar el motivo (exento, no gravado, etc.) y **no debe
existir** fila de alícuota asociada. Un comprobante con crédito fiscal computable y una alícuota
declarada del 21% tiene, por definición, **una** alícuota.

**Decisión**: el sistema emite `Cantidad de alícuotas` como el **conteo real** de filas que se
escribieron en el archivo de alícuotas para ese comprobante, garantizando por construcción que ambos
archivos concuerden (FR-016). El caso de estos dos comprobantes se emitiría como `1`, no como `0`.

**Riesgo asumido y por qué**: esto significa que, para estos dos comprobantes, el archivo generado por
el CRM **no será byte-idéntico** al de Contagram. Es intencional: el archivo del CRM es el correcto y
el de Contagram es el que tiene el defecto. Los tests de fidelidad posicional (FR-021) contemplan esta
excepción explícitamente en lugar de exigir igualdad byte a byte ciega.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Descargar el ZIP de IVA Digital de un período (Priority: P1)

El responsable administrativo entra al informe "Información para tu Contador", elige un mes y un año, y
descarga un único archivo ZIP con los cuatro TXT del régimen RG 3685. Se lo pasa al contador, que los
importa directamente en el aplicativo de ARCA sin tener que transcribir nada a mano.

**Why this priority**: es el motivo de existir de la feature y el entregable de mayor valor del cierre
mensual. Hoy esa información sólo existe en pantalla y en XLSX, formatos que ARCA no acepta: el contador
debe recargarla manualmente comprobante por comprobante. Con sólo esta historia ya hay valor completo.

**Independent Test**: elegir Agosto 2026 sobre la base con los datos del período, descargar el ZIP, y
verificar que contiene exactamente los 4 TXT con los nombres correctos y que cada uno reproduce el
fixture de `contador/` (con la única excepción documentada de "Cantidad de alícuotas").

**Acceptance Scenarios**:

1. **Given** un período con ventas y compras cargadas, **When** el usuario descarga el IVA Digital de
   ese período, **Then** obtiene un ZIP llamado `IVA Digital Ventas y Compras {Mes} {Año}.zip` que
   contiene los 4 TXT nombrados según el patrón `{Comprobantes|Alicuotas} {Ventas|Compras} {Mes} {Año} Res 3685.txt`.
2. **Given** ese ZIP, **When** se abre cualquiera de los 4 TXT, **Then** todas sus líneas tienen
   exactamente el mismo ancho (266 / 62 / 325 / 84 respectivamente), terminan en CRLF y no hay fila de
   encabezado.
3. **Given** un comprobante con acentos o `Ñ` en el nombre de la contraparte, **When** se genera el
   archivo, **Then** ese registro conserva el ancho exacto de línea (la codificación no corre las
   posiciones siguientes).
4. **Given** un período **sin** movimientos, **When** el usuario descarga el IVA Digital, **Then**
   obtiene igualmente el ZIP con los 4 archivos vacíos (0 bytes), no un error.

---

### User Story 2 - Confiar en que el archivo va a ser aceptado por ARCA (Priority: P1)

Antes de mandarle el ZIP al contador, el responsable necesita saber que los archivos son consistentes
entre sí: que todo comprobante con alícuotas tiene sus filas, que la cantidad declarada coincide con
las filas reales, y que los totales cierran.

**Why this priority**: comparte P1 con la historia 1 porque un ZIP que ARCA rechaza tiene **valor
negativo** — el contador pierde tiempo, la presentación se atrasa y el error aparece recién en la
ventana de vencimiento. La correctitud no es un extra de esta feature: es la feature.

**Independent Test**: generar los archivos de un período conocido y correr las validaciones cruzadas
entre el archivo de comprobantes y el de alícuotas, verificando que no hay comprobantes huérfanos ni
cantidades declaradas que no coincidan.

**Acceptance Scenarios**:

1. **Given** un período generado, **When** se cruzan los dos archivos de Ventas, **Then** cada
   comprobante con `Cantidad de alícuotas > 0` tiene exactamente esa cantidad de filas en el archivo de
   alícuotas, identificadas por la misma clave (tipo + punto de venta + número).
2. **Given** un período generado, **When** se revisa el archivo de alícuotas, **Then** no existe
   ninguna fila cuya clave no corresponda a un comprobante presente en el archivo de comprobantes.
3. **Given** una compra del período, **When** se compara su crédito fiscal computable con la suma del
   IVA de sus filas de alícuota, **Then** ambos coinciden.

---

### User Story 3 - Obtener el IVA Digital sólo cuando corresponde (Priority: P2)

El usuario elige un año **sin** elegir mes. En ese caso el IVA Digital no se ofrece, porque el régimen
RG 3685 es de presentación **mensual** y un archivo anual no tendría sentido para ARCA.

**Why this priority**: es una restricción de alcance observada en el comportamiento real de Contagram
(el adjunto `IVA Digital` aparece sólo cuando hay mes elegido, mientras que los XLSX de IVA Ventas y
Compras se generan también en modo anual). No bloquea el valor principal, pero evita entregar un
archivo inválido.

**Acceptance Scenarios**:

1. **Given** un año elegido sin mes, **When** el usuario mira las opciones de descarga, **Then** el IVA
   Digital no está disponible, y los XLSX de IVA Ventas y Compras sí.
2. **Given** un año y un mes elegidos, **When** el usuario mira las opciones, **Then** el IVA Digital
   está disponible.

---

### Edge Cases

- **Contraparte sin CUIT**: el fixture contiene ventas a consumidor final con tipo de documento `96`
  (DNI) y `99` (sin identificar). Un comprobante sin identificación válida se emite con tipo de
  documento `99` y número en cero, no se omite del archivo ni aborta la generación.
- **Nombre más largo que el campo**: se trunca a 30 caracteres sin marcador, tal como el fixture.
- **Comprobante en moneda extranjera**: todo el fixture está en `PES` con tipo de cambio `1,000000`.
  Un comprobante en otra moneda debe emitir su código y su tipo de cambio reales.
- **Período con movimientos sólo de un lado** (ventas pero no compras): se generan igual los 4
  archivos; los de compras quedan vacíos.
- **Comprobante anulado o eliminado**: no entra al archivo, mismo criterio que la spec 077.
- **Compra con mes de imputación distinto al de emisión**: entra en el período de imputación, no en el
  de emisión (regla heredada de 077). El fixture lo confirma: la primera línea de Comprobantes Compras
  tiene fecha `20260731` y está en el archivo de **Agosto**.

## Requirements *(mandatory)*

### Functional Requirements — alcance y disponibilidad

- **FR-001**: El sistema MUST permitir descargar, para un período mensual elegido, un archivo ZIP con
  los cuatro archivos del régimen RG 3685.
- **FR-002**: El ZIP MUST llamarse `IVA Digital Ventas y Compras {Mes} {Año}.zip`, con el mes en
  castellano y con inicial mayúscula (`Agosto`), tal como el fixture.
- **FR-003**: Los archivos contenidos MUST llamarse `Comprobantes Ventas {Mes} {Año} Res 3685.txt`,
  `Alicuotas Ventas {Mes} {Año} Res 3685.txt`, `Comprobantes Compras {Mes} {Año} Res 3685.txt` y
  `Alicuotas Compras {Mes} {Año} Res 3685.txt`. El nombre lleva `Alicuotas` **sin acento**, como el
  fixture.
- **FR-004**: El sistema MUST ofrecer esta descarga **sólo** cuando hay mes y año elegidos; con año
  solo, MUST NOT ofrecerla.
- **FR-005**: El sistema MUST generar los archivos aun cuando el período no tenga movimientos,
  produciendo archivos vacíos en lugar de un error.
- **FR-006**: El sistema MUST determinar qué comprobantes integran el período reutilizando **sin
  modificar** la resolución de período fiscal de la spec 077 (emisión para ventas, mes de imputación
  con fallback a emisión para compras, mes de imputación para NC/ND).
- **FR-007**: El sistema MUST excluir los comprobantes eliminados, igual que la spec 077.

### Functional Requirements — formato de archivo

- **FR-008**: Todos los archivos MUST usar codificación **latin-1 (ISO-8859-1)**, sin BOM.
- **FR-009**: Todas las líneas MUST terminar en **CRLF**, incluida la última.
- **FR-010**: Los archivos MUST NOT tener fila de encabezado.
- **FR-011**: Todas las líneas de un mismo archivo MUST tener exactamente el mismo ancho: 266
  (Comprobantes Ventas), 62 (Alícuotas Ventas), 325 (Comprobantes Compras), 84 (Alícuotas Compras).
- **FR-012**: Los campos numéricos MUST emitirse alineados a la derecha, rellenados con ceros a la
  izquierda, expresando **centavos** sin separador decimal ni signo.
- **FR-013**: Los campos alfanuméricos MUST emitirse alineados a la izquierda, rellenados con espacios
  a la derecha, y **truncados** al ancho del campo cuando el valor es más largo.
- **FR-014**: Los importes MUST emitirse en valor absoluto; el signo queda determinado por el tipo de
  comprobante.

### Functional Requirements — contenido y consistencia

- **FR-015**: El importe total de cada comprobante MUST emitirse tal como está almacenado, **sin
  recalcularlo** como suma de sus componentes, admitiendo la deriva de redondeo de hasta ±1 centavo
  descrita en Clarifications.
- **FR-016**: El campo "Cantidad de alícuotas" de cada comprobante MUST ser el **conteo real** de filas
  emitidas para ese comprobante en el archivo de alícuotas correspondiente.
- **FR-017**: Toda fila del archivo de alícuotas MUST corresponder a un comprobante presente en el
  archivo de comprobantes del mismo lado (ventas o compras).
- **FR-018**: El crédito fiscal computable de cada compra MUST coincidir con la suma del IVA de sus
  filas de alícuota.
- **FR-019**: Los archivos MUST ordenarse por fecha y, a igual fecha, de forma estable y determinística,
  de modo que dos generaciones del mismo período produzcan archivos idénticos.
- **FR-020**: El sistema MUST derivar netos, IVA por alícuota y percepciones reutilizando la lógica ya
  construida por la spec 077, sin duplicar reglas de cálculo.

### Functional Requirements — verificación

- **FR-021**: La generación MUST estar cubierta por tests que validen el contenido **campo por campo y
  posición por posición** contra el fixture real de `contador/`, no sólo el ancho total de línea ni una
  comparación byte a byte global.
- **FR-022**: Los tests MUST cubrir explícitamente los dos comprobantes con el defecto de "Cantidad de
  alícuotas" documentado arriba, verificando que el sistema emite el conteo correcto.
- **FR-023**: Los tests MUST cubrir un registro con acentos o `Ñ` en la denominación, verificando que
  el ancho de línea se mantiene.

### Key Entities

- **Registro de Comprobante (Ventas)**: una línea de 266 caracteres por comprobante de venta del
  período. Campos, en orden: fecha, tipo, punto de venta, número desde, número hasta, código y número
  de documento de la contraparte, denominación, importe total, no gravado, percepciones a no
  categorizados, exento, percepción de IVA, percepción de IIBB, percepción municipal, impuestos
  internos, moneda, tipo de cambio, cantidad de alícuotas, código de operación, otros tributos y fecha
  de vencimiento de pago.
- **Registro de Alícuota (Ventas)**: una línea de 62 caracteres por combinación de comprobante y
  alícuota: tipo, punto de venta, número, neto gravado, código de alícuota e IVA liquidado.
- **Registro de Comprobante (Compras)**: una línea de 325 caracteres. Se diferencia del de ventas en
  que incluye despacho de importación, crédito fiscal computable, y CUIT y denominación del emisor por
  cuenta de terceros, y en que no lleva número hasta ni fecha de vencimiento de pago.
- **Registro de Alícuota (Compras)**: una línea de 84 caracteres. A diferencia del de ventas, **sí**
  incluye código y número de documento del emisor.
- **Paquete IVA Digital**: el ZIP del período que agrupa los cuatro archivos anteriores.

## Success Criteria *(mandatory)*

- **SC-001**: El contador puede importar los cuatro archivos en el aplicativo de ARCA sin ningún
  rechazo por formato ni ninguna corrección manual previa.
- **SC-002**: Para el período Agosto 2026, los archivos generados por el sistema reproducen el fixture
  real con **una única divergencia esperada y documentada**: el campo "Cantidad de alícuotas" de los
  dos comprobantes de MercadoLibre afectados por el defecto de origen.
- **SC-003**: El 100% de los comprobantes del período aparece en el archivo correspondiente: ninguno se
  pierde ni se duplica.
- **SC-004**: El 100% de las filas de alícuota tiene un comprobante asociado, y el 100% de los
  comprobantes declara una cantidad de alícuotas igual a la cantidad de filas realmente emitidas.
- **SC-005**: Generar el mismo período dos veces produce archivos byte a byte idénticos.
- **SC-006**: El responsable administrativo obtiene el ZIP del período en un solo paso, sin abrir
  comprobante por comprobante.

## Assumptions

- El régimen aplicable es el de **presentación mensual**; no se contempla presentación por período
  distinto del mes calendario.
- Los códigos de tipo de comprobante, tipo de documento y código de alícuota que usa el sistema son los
  de la tabla oficial de ARCA que ya emplea el módulo de facturación electrónica del CRM; esta spec no
  introduce una tabla de códigos nueva. Los valores observados en el fixture (tipos `001`, `002`,
  `003`, `006`, `008`; documentos `80`, `96`, `99`; alícuota `0005` = 21%) son un subconjunto de esa
  tabla, no su totalidad.
- El campo "Impuestos municipales" se emite en cero, coherente con la brecha ya documentada en la spec
  077 (el CRM no modela ese impuesto).
- Los campos de emisor por cuenta de terceros se emiten vacíos: el negocio no opera bajo esa figura, y
  así están en todo el fixture.
- La descarga la realiza un usuario ya autenticado con permiso sobre el módulo de Informes; esta spec
  no introduce reglas de permisos nuevas.

## Dependencies

- **Spec 077** (Información para tu Contador): aporta la resolución de período fiscal, la derivación
  impositiva y la pantalla desde la que se ofrece la descarga. Esta spec la extiende, no la modifica.
- **Fixture `contador/`**: los cinco archivos reales del período Agosto 2026 son requisito para poder
  verificar FR-021.

## Out of Scope

- El **envío por correo** de estos archivos al contador y el modal que lo opera: es la **spec 087**.
- La generación de los XLSX de IVA Ventas e IVA Compras: ya existe (spec 077).
- La presentación o el envío automático de la información a ARCA: el archivo se entrega al contador,
  que lo presenta.
- El régimen de información de compras y ventas para períodos anteriores a la vigencia del layout
  actual.
