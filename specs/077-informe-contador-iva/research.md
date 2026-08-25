# Research: Información para tu Contador (spec 077)

**Fecha**: 2026-08-24
**Spec**: [spec.md](./spec.md)

Todas las incógnitas técnicas resueltas. No queda ningún `NEEDS CLARIFICATION`.

---

## Decisión 1 — Granularidad: agregar a nivel comprobante, no reusar el detalle por ítem

**Decisión**: la query del libro devuelve **una fila por comprobante**, agregando el desglose impositivo
con `SUM(...)` sobre los ítems dentro de una subconsulta agrupada por comprobante.

**Rationale**: los informes de Compras (spec 067) y Ventas (spec 076) trabajan a nivel **ítem** (una fila
por línea de detalle) porque su pregunta es "qué compré/vendí". El Libro IVA responde otra pregunta —
"qué comprobantes emití/recibí en el período" — y la unidad contable es el comprobante. Reusar la query
de ítems y agrupar después traería el problema conocido de las columnas de comprobante que se repiten por
línea (documentado en `ComprasInformeQuery`), justo la trampa que ese informe tiene con test dedicado.

**Alternativas descartadas**:
- *Reutilizar `ComprasInformeQuery::detalle()` y agrupar*: arrastra las columnas por ítem y el prorrateo
  de conceptos, que acá sobra. Se pagaría complejidad para deshacer trabajo ya hecho.
- *Vista materializada / tabla de libro IVA*: precalcular introduce un estado que puede quedar
  desincronizado con las ventas y compras. Un informe de sólo lectura no justifica ese riesgo.

---

## Decisión 2 — Reutilizar `DesgloseImpositivoVenta` / `DesgloseImpositivoCompra` envolviendo sus expresiones en `SUM`

**Decisión**: no se escribe lógica nueva de clasificación impositiva. Se toman las expresiones SQL que
esos dos servicios ya generan (`sqlNeto`, `sqlIva`, y las de percepciones/impuestos internos) y se las
envuelve en `SUM(...)` dentro del `GROUP BY` por comprobante.

**Rationale**: es la garantía de que este informe **no pueda divergir** de los informes de Ventas y
Compras al clasificar la misma operación (spec, sección Assumptions). Si mañana cambia la regla de qué
cuenta como "no gravado", cambia en un solo lugar y los tres informes se mueven juntos.

**Consecuencia importante**: `DesgloseImpositivoVenta::sqlConceptoProrateado()` **prorratea** percepciones
e impuestos internos por línea usando funciones de ventana. A nivel comprobante ese prorrateo es ruido:
sumar los prorrateos devuelve el total original, pero pasando por un redondeo intermedio innecesario que
puede introducir centavos. Para este informe se consulta el **total del concepto por comprobante**
directamente (la subconsulta `SUM(monto)` sobre los conceptos), sin prorratear.

**Alternativas descartadas**:
- *Copiar las expresiones a un servicio nuevo*: duplicar la regla fiscal es exactamente lo que la
  constitución (principio I) manda evitar.
- *Sumar los prorrateos por línea*: introduce error de redondeo evitable en un informe donde la ecuación
  de totales debe cerrar exacta (FR-011).

---

## Decisión 3 — Resolución del período: una expresión por tipo de origen

**Decisión**: el período no se resuelve con una sola columna sino con una expresión por rama de la unión:

| Origen | Columna de período | Nullable |
|---|---|---|
| Venta (IVA Ventas) | `ventas.fecha_emision` | no |
| Compra (IVA Compras) | `COALESCE(compras.mes_imputacion_iva, compras.fecha_emision)` | `mes_imputacion_iva` sí |
| NC/ND (ambas pestañas) | `notas_credito_debito.mes_imputacion` | no (NOT NULL) |

El filtro compara **año y mes** de esa expresión contra el período elegido, no un rango de fechas: el
mes de imputación se persiste con día fijo `01`, así que un `BETWEEN` contra el último día del mes
funcionaría por casualidad pero expresaría mal la intención.

**Rationale**: `notas_credito_debito.mes_imputacion` fue creado por la spec 045 con el propósito
explícito de alimentar este informe; ignorarlo sería dejar sin uso un campo que ya se le pide al usuario
en el modal de alta. `compras.mes_imputacion_iva` es nullable porque el campo "Contador" es opcional en
el formulario, de ahí el `COALESCE`.

**Alternativas descartadas**:
- *Usar siempre `fecha_emision`*: rompería el motivo de existir del campo "Contador" y daría un libro IVA
  contablemente incorrecto para compras recibidas fuera de término.
- *Rango de fechas `BETWEEN inicio y fin de mes`*: funciona pero oculta que la comparación es de
  período fiscal, no de fecha. Con `mes_imputacion` a día 01 el rango es engañoso.

---

## Decisión 4 — "Aprobadas por ARCA" vs "Manuales": partición por existencia de CAE aprobado

**Decisión**: en IVA Ventas, la clasificación se hace con un `EXISTS` sobre `comprobantes_fiscales` con
`estado = 'aprobado'` para la venta. Firme = existe al menos uno; manual = no existe ninguno.

**Rationale**: es la única partición que cumple FR-017 (mutuamente excluyentes + cobertura total), lo que
hace verificable SC-004. Además resuelve de una la cardinalidad 1→N documentada en `modelo_datos.md`: una
venta reintentada tiene una fila rechazada **y** una aprobada; con `EXISTS` sobre las aprobadas cuenta
una sola vez y como firme (FR-018), sin necesidad del `CASE WHEN estado = 'aprobado' THEN 0 ELSE 1 END`
del `morphOne` `comprobanteFiscal()`.

**Gotcha heredado**: `modelo_datos.md` advierte que usar el `morphOne` para filtrar devuelve el rechazo
más viejo y hace que el sistema se comporte como si la venta no tuviera CAE (incidente de la Venta
24447). Este informe **no** debe usar `comprobanteFiscal()` para clasificar; usa `EXISTS` sobre
`comprobantesFiscales()`.

**Alternativas descartadas**:
- *Filtrar por `ventas.nro_comprobante` no nulo*: esa columna existe como fallback sin validez fiscal
  (spec 008/030), así que clasificaría como firme algo que nunca fue a ARCA.
- *`JOIN` a `comprobantes_fiscales`*: duplicaría la fila de la venta con varios intentos.

---

## Decisión 5 — Servicio de query propio, fuera del controlador

**Decisión**: `App\Services\Informes\LibroIvaQuery` (más los dos servicios finos que resuelven cada
pestaña), devolviendo un Query Builder para que DataTables pagine en SQL. El controlador sólo valida y
delega.

**Rationale**: es el patrón ya establecido por `ComprasInformeQuery`, `VentasInformeQuery` y
`GastosInformeQuery`, y lo que exige la constitución (principio IV): los tests de dinero tienen que
ejercitar el cálculo sin pasar por HTTP. Además permite testear la ecuación de totales (FR-011)
directamente sobre el servicio.

---

## Decisión 6 — Totales: una sola query agregada, no la suma de la página

**Decisión**: endpoint separado de totales que corre la misma query filtrada envuelta en un `SUM` global.
`Total Facturado` se calcula **en PHP** como la suma de los cuatro componentes ya redondeados a 2
decimales, no como un quinto `SUM` en SQL.

**Rationale**: garantiza FR-011 por construcción — si el quinto total se calculara aparte en SQL,
reaparecería la deriva de centavos que se detectó en la propia pantalla de Contagram (ver Clarifications
de la spec). Calcularlo como suma de los otros cuatro hace la ecuación verdadera por definición, no por
suerte de redondeo.

---

## Decisión 7 — Dos pestañas, una sola pantalla, estado independiente

**Decisión**: una ruta `informes/contador` que renderiza la pantalla con las dos pestañas, y endpoints
separados por pestaña (`.../ventas/data`, `.../compras/data`, y sus `stats` y `exportar`). El estado de
período, filtros y columnas vive por pestaña en el cliente.

**Rationale**: FR-030 exige que las pestañas no se contaminen entre sí. Rutas separadas por pestaña
mantienen cada query simple y explícita, en vez de un parámetro `libro=ventas|compras` que obligue a
ramificar por dentro. Sigue además la regla de memoria del proyecto de no usar URLs con `#fragmento`
para navegación: las pestañas son un control dentro de una pantalla, no dos páginas.

---

## Decisión 8 — Selector de Mes/Año con `<select>` nativos, no `daterangepicker` ni `input type="date"`

**Decisión**: dos `<select>` (Mes con 12 opciones fijas, Año con un rango acotado a los años con datos),
sin Select2 y sin componentes de fecha.

**Rationale**: `CLAUDE.md` #6 prohíbe `input type="date"` (bug de locale día/mes). `CLAUDE.md` #5 exige
Select2 sólo para **datos dinámicos**; Mes es una lista fija de 12 y Año una lista corta, así que un
`<select>` nativo es lo correcto y es además lo que muestran las capturas. El `daterangepicker` que usan
Compras y Gastos no aplica: acá el período es mes/año, no un rango libre.

**Nota**: los selects de **Cliente**, **Proveedor**, **Tipo de Comprobante**, **Condición de IVA**,
**Medio de Cobro/Pago** y **Provincia** del panel de filtros **sí** son datos dinámicos y van con Select2
(los dos primeros con `ajax`, por catálogo grande).

---

## Decisión 9 — Largo de la URL de DataTables: enviar los filtros por POST

**Decisión**: el endpoint de datos se sirve por **POST**, no por GET.

**Rationale**: es la lección directa del incidente de hoy (24/08/2026) en producción: el informe de
Compras con 36 columnas generaba una URL que superaba `large_client_header_buffers` de Nginx y devolvía
**414 Request-URI Too Large**, sin dejar rastro en `laravel.log` porque nunca llegaba a Laravel. Este
informe tiene 19 columnas, así que hoy entraría — pero el margen depende de una config de infraestructura
en el VPS, no del código. Con POST el problema no puede aparecer.

**Consecuencia**: la exportación sigue por GET (el navegador tiene que recibir el archivo), pero manda
sólo los filtros, no el descriptor de columnas de DataTables, que es lo que inflaba la URL.

**Alternativas descartadas**:
- *Confiar en el fix de Nginx aplicado hoy*: arreglar la infra fue correcto, pero dejar el código
  dependiendo de ese ajuste repite el problema en cualquier ambiente nuevo.
- *Reducir columnas*: rompería la fidelidad estructural con Contagram.

---

## Decisión 10 — "Imp. Municipales" se emite en cero y se documenta como brecha

**Decisión**: la columna existe en la tabla y en el export, siempre con valor `0`.

**Rationale**: el principio rector de `CLAUDE.md` prioriza no divergir estructuralmente de Contagram. El
modelo no tiene hoy un concepto de impuesto municipal diferenciado (`venta_conceptos`/`compra_conceptos`
manejan `percepcion` e `impuesto_interno`). Emitir la columna en cero preserva la estructura y deja el
lugar listo. Como FR-011a la deja fuera de la ecuación de totales, un cero no descuadra nada.

**Seguimiento**: queda anotado como brecha en `docs/documentacion_principal_crm.md §5`.

---

## Decisión 11 — Filtro de Medio de Cobro/Pago con `EXISTS`, no `JOIN`

**Decisión**: `EXISTS` sobre `cobros` (o `pagos`) con la cuenta de tesorería elegida.

**Rationale**: FR-031 pide que el comprobante aparezca si **alguno** de sus cobros usa ese medio, sin
duplicar la fila. Un `JOIN` a `cobros` multiplicaría la venta por cantidad de cobros y rompería tanto el
conteo como los totales — es el mismo error de cardinalidad que este informe evita en la Decisión 1.

---

## Decisión 12 — Excel de una hoja por pestaña, con todas las columnas

**Decisión**: `Maatwebsite\Excel` con una hoja, encabezado de totales del período arriba y el detalle
completo debajo, con las 19 columnas siempre presentes (FR-034).

**Rationale**: el informe de Compras (spec 067) usa dos hojas (formateada + plana) porque su Excel de
Contagram trae 35 columnas y necesita una versión legible. Acá la pantalla y el libro coinciden, y un
Libro IVA se consume como una planilla única que el contador procesa. Las capturas muestran un solo
botón "Exportar" sin variantes.
