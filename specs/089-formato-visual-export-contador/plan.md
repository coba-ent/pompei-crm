# Implementation Plan: Formato visual del Excel del Libro IVA

**Branch**: `089-formato-visual-export-contador` · **Fecha**: 2026-08-28 · **Spec**: [spec.md](./spec.md)

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `maatwebsite/excel ^3.1` (PhpSpreadsheet) — ya en el proyecto, sin librerías nuevas

**Storage**: N/A — no toca la base de datos

**Testing**: PHPUnit; se lee el `.xlsx` generado con PhpSpreadsheet y se asserta el formato celda por celda

**Target Platform**: mismo backend Laravel (descarga desde pantalla + adjunto del correo, spec 087)

**Project Type**: cambio acotado a una clase de export dentro de la app monolítica

**Performance Goals**: N/A — mismo volumen que hoy (un mes de comprobantes)

**Constraints**: **no puede cambiar ni un número** del contenido ya verificado peso por peso contra
Contagram (specs 077/088); sólo presentación

**Scale/Scope**: una clase de export, usada por 4 puntos de entrada (descarga Ventas, descarga Compras,
adjunto Ventas, adjunto Compras)

## Constitution Check

*GATE: revisado antes de Fase 0 y re-chequeado tras el diseño.*

- **Principio I (docs de dominio)**: el §6.7 de `documentacion_principal_crm.md` no documenta hoy el
  formato del export; se agrega esa sección en la implementación (tarea T011).
- **Principio II (spec-driven)**: cumplido — spec 089 con clarify resuelto antes del plan.
- **Principio III (corrección fiscal)**: no se toca ningún cálculo. El riesgo específico de esta feature
  es *romper* números al reordenar filas para meter el encabezado — se cubre con el test de no-regresión
  de contenido (Estrategia de test 1), que es obligatorio acá.
- **Principio IV (testing fiscal)**: los importes no cambian, pero pasan a emitirse con formato
  numérico; un error de tipo (número escrito como texto) rompería la sumatoria del contador. Cubierto
  por tests de tipo de celda.
- **Principio V (Laravel + español)**: se sigue la convención de exports ya establecida
  (`WithStyles`/`WithColumnWidths`, mismo patrón que `MovimientosExport`), nombres en español.

Sin violaciones. No aplica Complexity Tracking.

---

## Resumen técnico

`LibroIvaExport` implementa hoy sólo `FromArray` + `WithTitle`: vuelca un array y no toca una sola
propiedad de formato. Se le agregan los *concerns* de estilo que el proyecto ya usa en otros exports, se
reordena el array para intercalar el encabezado del negocio arriba y los totales desglosados al pie, y
se fija el formato por tipo de columna.

No se crea una clase nueva: es la misma que consumen los 4 puntos de entrada (dos descargas + dos
adjuntos del correo), así que modificarla propaga el formato a todos sin trabajo adicional (SC-005).

---

## Referencia de formato (extraída del fixture, no inventada)

`tests/Fixtures/LibroIvaExport/IVA Ventas Agosto 2026 Contagram.xlsx`, leído con PhpSpreadsheet:

| Elemento | Valor exacto del fixture |
|---|---|
| Tipografía | Arial en todo el documento |
| Razón social / CUIT (filas 1-2, col A) | negrita, 11 pt, alineado izquierda |
| Título del libro (fila 2, centrado) | negrita, **18 pt**, centrado |
| Período (fila 3, centrado) | negrita, 11 pt, centrado |
| Fila 4 | vacía (separador) |
| Encabezados de columna (fila 5) | 10 pt, **fondo `0E5DA1`**, texto blanco `FFFFFF`, centrado, borde inferior fino negro, alto de fila 27 |
| Filas de datos | 10 pt, fondo blanco, alto 13.5 |
| Fechas | valor de fecha real, `numFmt = DD/MM/YYYY`, centrado |
| Importes | `numFmt = 0.00;(0.00)`, alineado derecha |
| Texto (razón social, condición IVA) | alineado izquierda |
| Tipo / N° comprobante | centrado |
| Totales al pie | 3 filas: `Por Facturación:` · `Por Nota de Crédito:` · `Totales:` — rótulo en negrita; importes de las 2 primeras normales, los de `Totales:` **en negrita** |
| Grilla | **oculta** (`showGridLines = false`) |
| Orientación | apaisada |

El azul `0E5DA1` ya se usa en `MovimientosExport` (Tesorería): es el corporativo de Contagram, no una
paleta nueva.

---

## Componentes

### 1. `LibroIvaExport` (modificación — `app/Exports/Informes/`)

Pasa de `FromArray, WithTitle` a implementar además `WithStyles`, `WithColumnWidths`,
`WithColumnFormatting`, `WithStrictNullComparison` y `WithEvents`.

- **`array()`** — se reordena: 4 filas de encabezado del negocio → fila de títulos de columna → filas de
  datos → fila vacía → 3 filas de totales. Deja de emitir la barra de KPIs de arriba (FR-015). Guarda en
  propiedades los índices de fila de cada bloque (mismo patrón que `MovimientosExport::$filasTotal`),
  porque `styles()` necesita saber dónde aplicar cada estilo y el array es de largo variable.
- **`styles()`** — aplica la tabla de referencia de arriba a los rangos calculados.
- **`columnWidths()`** — ancho por columna según su contenido esperado.
- **`columnFormats()`** — `DD/MM/YYYY` para Emisión, `0.00;(0.00)` para las 12 columnas de importe.
- **`registerEvents()`** — `AfterSheet` para lo que no cubren los concerns: ocultar la grilla y fijar la
  orientación apaisada.

**`WithStrictNullComparison` no es opcional** (mismo motivo ya documentado en `MovimientosExport`): sin
él PhpSpreadsheet compara cada celda contra `null` con `==`, y como en PHP `0 == null`, un comprobante
con importe 0 real —de los que este libro tiene muchos, todas las columnas de alícuotas que no
aplican— no se escribiría en el archivo.

### 2. Totales desglosados (nuevo cálculo de presentación)

Hoy `LibroIvaQuery::totales()` devuelve los 5 KPIs de la barra de pantalla. El pie del Excel necesita
otro corte: **facturación vs. notas de crédito, por columna de importe**.

Se resuelve **en PHP sobre las filas ya materializadas** (`detalle()->get()`), separando por el prefijo
de `tipo` (`NC`/`ND` vs. el resto) — el mismo criterio que ya usan `DatosFiscalesComprobante::clave()` y
`ComprobantesVentasWriter::esNota()`. No se agrega una query nueva ni se toca `totales()`: son los mismos
números, agrupados distinto para mostrarlos.

### 3. Datos del negocio en el encabezado

Razón social y CUIT salen de `DatosEmpresa::instancia()` — el mismo origen que ya usan los PDF de
comprobantes y el asunto del correo al contador (spec 087). Si no hay fila cargada, los renglones van
vacíos (FR-004).

El nombre del mes en castellano ya está resuelto en `Periodo` (spec 087,
`app/Services/Informes/Contador/Periodo.php`): se reutiliza en vez de duplicar el array de meses.

---

## Decisiones de diseño

### Decisión 1 — Fechas como valor de fecha, divergiendo de `MovimientosExport`

**Elegido**: `Date::PHPToExcel()` + `numFmt = DD/MM/YYYY`, como el fixture.

**Por qué**: `MovimientosExport` las escribe como texto a propósito, para que el locale del lector no las
reinterprete — criterio correcto **para su caso**: dos fechas sueltas en una cabecera, que nadie ordena.
Acá es una columna de un libro contable que el contador ordena y filtra; como texto, `01/09` se ordena
antes que `03/08`. El fixture de Contagram las emite como fecha y fija el formato en la celda, que es la
misma mitigación. Divergencia deliberada y acotada, no un descuido — queda dicho acá y en Clarifications
de la spec para que no se "corrija" después por consistencia mal entendida.

**Gotcha ya documentado en la memoria del proyecto**: `DateTimeInterface` no se graba como fecha sin
`Date::PHPToExcel()`; hay que testearlo con round-trip real (leer el archivo generado), no confiando en
el array de PHP.

### Decisión 2 — El encabezado se arma en `array()`, no en `styles()`

**Elegido**: las filas del encabezado del negocio se agregan como filas del array, y `styles()` sólo las
formatea.

**Por qué**: `styles()` corre sobre una hoja ya escrita; insertar filas ahí desplazaría todo lo demás y
obligaría a recalcular cada rango. Con las filas ya en el array, los índices son conocidos y fijos
(1-4 encabezado, 5 títulos, 6..n datos), y `styles()` se limita a aplicar rangos — más simple y menos
frágil.

### Decisión 3 — No se toca `LibroIvaQuery::totales()`

**Elegido**: el desglose facturación/notas del pie se calcula en el export, no en la query.

**Por qué**: `totales()` alimenta la barra de KPIs de la pantalla, verificada peso por peso contra
Contagram (specs 077/088). Cambiarle la forma para servir también al Excel arriesga esa verificación por
una necesidad puramente de presentación. El export ya tiene las filas materializadas; agrupar por tipo
ahí es aritmética sobre datos ya calculados, no una segunda derivación de los números.

---

## Estrategia de test

1. **No-regresión de contenido (el más importante)** — para un período con datos, las filas de datos del
   Excel nuevo tienen exactamente los mismos valores, en las mismas columnas y el mismo orden, que las
   del Excel actual. Es el test que protege lo verificado peso por peso: el formato puede cambiar, los
   números no. Sin esto, un error de índice al correr las filas 4 posiciones hacia abajo pasa
   desapercibido.
2. **Encabezado del negocio** — razón social, CUIT, título correcto según Ventas/Compras, y período en
   castellano; más el caso de `DatosEmpresa` inexistente (no rompe, renglones vacíos).
3. **Tipos de celda** — las fechas se leen como fecha (no string) y los importes como número (no string).
   Es lo que hace que el contador pueda ordenar y sumar (SC-001, SC-002).
4. **Formato aplicado** — fondo `0E5DA1` y fuente blanca en la fila de títulos; `numFmt` correcto en
   fechas e importes; Arial en el documento.
5. **Totales del pie** — los tres renglones existen, y facturación + notas = totales en cada columna
   (FR-013). Se prueba con un período que tenga NC/ND reales.
6. **Período vacío** — se genera el archivo con encabezado, títulos y totales en cero, sin excepción.
7. **Acentos** — un cliente con `Ñ`/tildes y los rótulos fijos ("Emisión", "Condición de IVA") se leen
   correctamente del archivo generado (FR-014).
8. **Ambas hojas** — los tests corren para Ventas y para Compras, porque es la misma clase con dos
   configuraciones y una regresión podría afectar sólo a una.

> Todos los tests leen el `.xlsx` **generado** con PhpSpreadsheet y verifican contra él. Verificar el
> array de PHP antes de escribirlo no prueba nada del formato — gotcha ya documentado en la memoria del
> proyecto para este mismo paquete.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Correr las filas para el encabezado desalinea los datos y cambia números ya verificados | Test de no-regresión de contenido (Estrategia 1), obligatorio antes de dar por cerrada la feature |
| Un importe queda como texto y el contador no puede sumarlo | Test de tipo de celda (Estrategia 3) |
| Las fechas se ven M/D/A en un Excel con locale inglés | `numFmt` explícito en la celda, misma mitigación que usa el fixture de Contagram |
| Se aprovecha el refactor para "mejorar" columnas o cálculos | FR-010 y SC-004 lo prohíben explícitamente; el test de no-regresión lo detecta |
| El formato se aplica a Ventas pero no a Compras | Los tests corren para las dos (Estrategia 8) |
| Filas con importe 0 desaparecen del archivo | `WithStrictNullComparison`, con el motivo documentado en el código |

---

## Project Structure

### Documentación (esta feature)

```text
specs/089-formato-visual-export-contador/
├── plan.md
├── spec.md
├── tasks.md              # /speckit-tasks
└── checklists/
    └── requirements.md
```

### Código (repo root)

```text
app/Exports/Informes/
└── LibroIvaExport.php          # única clase modificada: + WithStyles, WithColumnWidths,
                                #   WithColumnFormatting, WithStrictNullComparison, WithEvents

tests/Fixtures/LibroIvaExport/
└── IVA Ventas Agosto 2026 Contagram.xlsx    # fuente de verdad del formato (ya guardado)

tests/Feature/Informes/
└── LibroIvaExportFormatoTest.php            # tests de formato + no-regresión de contenido

docs/documentacion_principal_crm.md          # §6.7: documentar el formato del export
```

**Structure Decision**: sin componentes nuevos. Es una modificación de una clase existente, siguiendo la
convención de exports con estilo que el proyecto ya tiene (`MovimientosExport`, `ProductosExport`,
`HojaInforme`).

---

## Fuera de alcance

Cambiar columnas, orden o cálculo (spec 077); formato de los TXT del IVA Digital (spec 086, ancho fijo
sin presentación); cuerpo del correo y nombres de adjuntos (spec 087); formato de impresión más allá de
la orientación apaisada; aplicar este formato a los demás informes del módulo.
