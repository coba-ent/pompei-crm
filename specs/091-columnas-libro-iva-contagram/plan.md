# Implementation Plan: Columnas del Libro IVA calcadas de Contagram

**Branch**: `091-columnas-libro-iva-contagram` · **Fecha**: 2026-08-28 · **Spec**: [spec.md](./spec.md)

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `maatwebsite/excel ^3.1` — sin librerías nuevas

**Storage**: MySQL. No hay migraciones: todos los datos ya existen

**Testing**: PHPUnit, leyendo el `.xlsx` generado (mismo arnés de la spec 089)

**Project Type**: cambio acotado a una clase de export

**Constraints**: **no puede alterar ningún importe** ni tocar `LibroIvaVentasQuery`, verificada peso
por peso contra Contagram y consumida también por la pantalla y el IVA Digital

**Scale/Scope**: una clase (`LibroIvaExport`) más un componente nuevo de enriquecimiento

## Constitution Check

- **Principio I (docs de dominio)**: el §6.7 documenta hoy 19 columnas en el export; se actualiza (T009).
- **Principio II (spec-driven)**: cumplido — spec 091 con clarify resuelto.
- **Principio III (corrección fiscal)**: el riesgo central es que un IVA a otra alícuota quede sin
  columna y desaparezca del libro. Lo cubre FR-008 (la columna lleva el IVA **total**) más su test.
- **Principio IV (testing fiscal)**: tests de que los importes por comprobante no cambian y de que la
  suma de IVA del período se conserva con alícuotas mixtas.
- **Principio V (Laravel + español)**: rótulos en español calcados del original.

Sin violaciones.

---

## Resumen técnico

`LibroIvaExport` pasa de emitir 19 columnas a 13, con los rótulos y el orden de Contagram. Tres de esas
13 (Total Facturado, Provincia, Medio de Cobro) no las devuelve hoy `LibroIvaVentasQuery`, así que se
resuelven **fuera de la query**, con una consulta por lote sobre las filas ya materializadas.

El formato visual de la spec 089 (encabezado, estilos, totales al pie, apaisado) se conserva íntegro;
sólo cambian las columnas sobre las que se aplica.

---

## La decisión central: no tocar `LibroIvaVentasQuery`

`detalle()` une **tres ramas** con `UNION ALL` —ventas, NC/ND y comprobantes históricos (spec 088)—
que deben mantener exactamente las mismas columnas en las mismas posiciones. Agregarle tres columnas
obligaría a modificar las tres ramas, incluida la de NC/ND que arma filas SQL literales en PHP.

Esa query está verificada peso por peso contra Contagram (specs 077/088, sobre un clon de producción) y
la consumen además la pantalla, el export y el IVA Digital. Tocarla para una necesidad de presentación
pondría en riesgo esa verificación.

**Se resuelve igual que la spec 086 con `DatosFiscalesComprobante`**: un componente aparte que, a partir
de las filas ya materializadas, trae los datos que faltan con una consulta por lote. Patrón ya probado
en este mismo módulo.

---

## Arquitectura

```
LibroIvaExport::array()
        │
        ├── LibroIvaVentasQuery::detalle()          ← SIN CAMBIOS (3 ramas UNION ALL)
        │        └── filas con las 19 columnas de siempre
        │
        └── DatosComercialesComprobante             ← NUEVO
                 ├── provincia   (clientes/proveedores, fiscal con respaldo en comercial)
                 ├── medio       (cobros/pagos → cuentas_tesoreria, el primero)
                 └── total       (suma de los netos + IVA de la propia fila)
                          │
                          ▼
              13 columnas con los rótulos de Contagram
```

---

## Componentes

### 1. `DatosComercialesComprobante` (nuevo — `app/Services/Informes/Contador/`)

Recibe las filas de `detalle()` ya materializadas y devuelve, keyed por la misma clave que ya usa
`DatosFiscalesComprobante` (`comprobante:` / `nota:` / `historico:`):

- **provincia**: `COALESCE(provincia_fiscal, provincia)` del cliente (o proveedor en Compras) — misma
  expresión que ya usa `LibroIvaQuery::filtrarProvincia()`, para que el filtro y la columna no puedan
  divergir. Sin dato → `'-'` (FR-004, como Contagram).
- **medio**: nombre de la cuenta de tesorería del primer cobro (o pago) del comprobante, vía
  `cobros`/`pagos` → `cuentas_tesoreria`. Sin cobro → vacío (FR-005).

Dos consultas por lote (una por cada dato), no una por fila: el volumen del período puede ser de cientos
de comprobantes (Julio 2026: 718) y el export ya se genera dentro de un job con timeout.

Los comprobantes **históricos** (spec 088) no tienen cliente en el CRM ni cobros: devuelven `'-'` y
vacío, sin consultar.

### 2. `LibroIvaExport` (modificación)

- `ENCABEZADOS` pasa a las 13 de Contagram.
- El armado de cada fila mapea desde las 19 columnas de `detalle()` a las 13 de salida:
  - Fecha, Tipo, N° de Comprobante, Razón Social, CUIT / DNI, Condición de IVA → directo.
  - Neto No Grav., Neto Exento, Neto Grav. → directo.
  - **IVA** → **suma de las cinco columnas de alícuota** (FR-008), no sólo `iva_21`.
  - **Total Facturado** → suma de los tres netos más el IVA total más percepciones e impuestos
    internos (los conceptos que hoy tienen columna propia y dejan de tenerla, para que su importe no
    desaparezca del total).
  - Provincia, Medio de Cobro → de `DatosComercialesComprobante`.
- `columnWidths()`, `columnFormats()` y `styles()` se ajustan a 13 columnas (la última pasa de `S` a `M`).
- El pie (spec 089) se recalcula sobre las columnas nuevas, incluyendo el total de Total Facturado
  (FR-010).

### 3. Compras

La misma clase sirve a los dos libros. El único rótulo que cambia es el de la última columna
("Medio de Cobro" / "Medio de Pago"), que se deriva del título ya recibido en el constructor.

---

## Decisiones de diseño

### Decisión 1 — La columna "IVA 21%" lleva el IVA total

**Elegido**: la columna rotulada `IVA 21%` (calcado de Contagram) contiene la suma de las cinco
alícuotas.

**Por qué**: es la salvaguarda que hace segura la reducción de columnas. Hoy el negocio factura sólo al
21% (verificado: las otras cuatro alícuotas suman 0,00 en Julio 2026), así que en la práctica el rótulo
es exacto. Pero si mañana aparece una venta al 10,5%, con una columna que sólo mirara `iva_21` ese IVA
**desaparecería del libro** — subdeclaración silenciosa, justo lo que el principio III prohíbe. Con la
suma, el rótulo queda impreciso en un caso hipotético; sin ella, el importe se pierde. Se prefiere lo
primero, y queda documentado.

### Decisión 2 — Total Facturado incluye percepciones e impuestos internos

**Elegido**: `total = netos + IVA total + perc. IVA + perc. IIBB + imp. internos + imp. municipales`.

**Por qué**: esos cuatro conceptos hoy tienen columna propia y dejan de tenerla. Si no entraran en el
total, sus importes desaparecerían por completo del archivo. Hoy son 0,00 en los datos reales, pero el
criterio debe ser correcto para cuando no lo sean. Coincide además con la definición de Total Facturado
de la barra de KPIs de la spec 077.

### Decisión 3 — Los datos comerciales se resuelven fuera de la query

Ver "La decisión central" arriba. Mismo patrón que `DatosFiscalesComprobante` (spec 086), ya probado.

### Decisión 4 — La pantalla no cambia

**Elegido**: las 19 columnas siguen en la pantalla del informe, con su selector de visibilidad.

**Por qué**: en pantalla las columnas vacías no molestan (hay selector) y el detalle por alícuota es
útil para revisar. El problema es del archivo que recibe el contador. Cambiar ambas cosas ampliaría el
alcance y el riesgo sin pedido del usuario.

---

## Estrategia de test

1. **Títulos calcados** — la fila de títulos coincide con la del fixture
   `tests/Fixtures/LibroIvaExport/IVA Ventas Contagram 13 columnas.xlsx`, nombre por nombre y posición
   por posición (SC-001). Se compara contra el archivo real, no contra una lista escrita a mano.
2. **No-regresión de importes** — para un comprobante conocido, los netos y el IVA del archivo nuevo son
   los mismos que emite el CRM hoy (SC-004). Es la contraparte del test T001 de la spec 089: allá se
   protegió el contenido al cambiar el formato, acá al cambiar las columnas.
3. **IVA total con alícuotas mixtas (el test crítico)** — un comprobante al 10,5% aparece con su
   importe en la columna de IVA, y la suma del período coincide con el IVA total (FR-008, SC-003).
   Sin este test, la reducción de columnas puede subdeclarar en silencio.
4. **Total Facturado** — coincide con el total del comprobante, incluidas percepciones cuando existen.
5. **Provincia** — fiscal, respaldo en comercial, y `'-'` cuando no hay ninguna.
6. **Medio de Cobro** — cuenta del cobro; vacío sin cobro; el primero cuando hay varios.
7. **Compras** — mismas 13 columnas con "Medio de Pago" en la última.
8. **El formato de la spec 089 sobrevive** — encabezado, fondo azul de títulos, fechas como fecha,
   importes numéricos, apaisado y los tres renglones del pie siguen ahí sobre las columnas nuevas.
9. **Los totales del pie cierran** — facturación + notas = totales, ahora también en Total Facturado.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un IVA a alícuota distinta de 21% desaparece del libro | Decisión 1 (columna = IVA total) + test 3, que es el que lo detecta |
| Percepciones e impuestos internos desaparecen al perder su columna | Decisión 2 (entran en Total Facturado) + test 4 |
| Tocar `LibroIvaVentasQuery` rompe la verificación peso por peso o la pantalla | No se toca: los datos nuevos se resuelven aparte (Decisión 3) |
| El mapeo de 19 a 13 columnas desalinea un importe | Test 2 de no-regresión, sobre un comprobante con valores conocidos |
| Se rompe el formato de la spec 089 al reducir el rango de columnas | Test 8, que reverifica las marcas de formato ya logradas |
| Una consulta por fila para provincia/medio hace lento el export de un mes grande | Dos consultas por lote, no por fila (718 comprobantes en Julio 2026) |

---

## Project Structure

```text
app/Exports/Informes/
└── LibroIvaExport.php                    # 13 columnas, mapeo desde las 19 de detalle()

app/Services/Informes/Contador/
└── DatosComercialesComprobante.php       # NUEVO: provincia + medio de cobro/pago por lote

tests/Fixtures/LibroIvaExport/
└── IVA Ventas Contagram 13 columnas.xlsx # fuente de verdad de los rótulos (ya guardado)

tests/Feature/Informes/
└── LibroIvaExportFormatoTest.php         # se adapta a 13 columnas + tests nuevos de esta spec

docs/documentacion_principal_crm.md       # §6.7: el export ya no emite 19 columnas
```

**Structure Decision**: un componente nuevo y una clase modificada, siguiendo el patrón que la spec 086
ya estableció en este módulo para enriquecer filas sin tocar las queries verificadas.

---

## Fuera de alcance

Las columnas de la pantalla (spec 077); el formato visual (spec 089, se conserva); los archivos del IVA
Digital (spec 086); recuperar en otro lado las columnas que se quitan.
