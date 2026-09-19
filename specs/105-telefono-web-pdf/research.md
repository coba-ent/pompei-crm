# Research — Teléfono y sitio web en el encabezado de los comprobantes impresos

**Feature**: 105-telefono-web-pdf | **Date**: 2026-09-18

Sin `NEEDS CLARIFICATION` pendientes de la Technical Context. Lo que sigue son las decisiones de
diseño que toma este plan, con su porqué y las alternativas descartadas.

---

## Decisión 1 — Un único partial compartido, no variantes por comprobante

**Decisión**: agregar los dos datos en
`resources/views/pdf/partials/encabezado-emisor.blade.php` y en ningún otro lado.

**Rationale**: el relevamiento del código muestra que los cinco comprobantes imprimibles ya incluyen
ese mismo partial, y que los cinco controladores ya le pasan `$datosEmpresa` a la vista:

| Comprobante | Vista | Ya incluye el partial |
|---|---|---|
| Venta | `resources/views/ventas/pdf.blade.php:23` | ✅ |
| Presupuesto | `resources/views/presupuestos/pdf.blade.php:21` | ✅ |
| Nota de Crédito/Débito | `resources/views/notas-credito-debito/pdf.blade.php:23` | ✅ |
| Remito | `resources/views/remitos/pdf.blade.php:23` | ✅ |
| Recibo | `resources/views/recibos/pdf.blade.php:15` | ✅ |

Con eso, FR-004 (que los cinco muestren lo mismo) se cumple **por construcción**: es imposible que un
comprobante quede desactualizado respecto de otro, porque no hay dos lugares que puedan divergir. No
hace falta tocar ningún controlador.

**Alternativas consideradas**:
- *Partial extendido sólo para Venta y Presupuesto* (literal al pedido): descartada por el usuario.
  Requeriría un segundo partial o un flag, sería más trabajo, y dejaría al negocio con dos
  encabezados distintos según el comprobante — exactamente el tipo de inconsistencia que después
  aparece como "¿por qué el remito no tiene el teléfono?".
- *Pasar los datos por variable desde cada controlador*: innecesario, `$datosEmpresa` ya llega.

---

## Decisión 2 — Dos columnas nuevas, sin tocar `domicilio_fiscal`

**Decisión**: `telefono` y `sitio_web`, ambas `string(255) NULL`, agregadas por migración aditiva.
No se agrega ninguna columna de dirección.

**Rationale**: el pedido nombra tres datos pero `domicilio_fiscal` ya existe y ya se imprime (línea 3
del bloque de datos del partial). Agregar un "domicilio comercial" sin que el negocio lo haya pedido
explícitamente sería inventar un requisito, y además obligaría a decidir cuál de los dos domicilios
manda en cada comprobante.

`string` nullable, y no un tipo más estricto, porque:
- El teléfono es **texto libre** por necesidad real: el negocio puede querer `11 5555-5555 / WhatsApp
  11 4444-4444`. Un tipo numérico o una validación de formato rompería ese caso.
- Ambos son opcionales igual que todo el resto de la tabla (`razon_social`, `cuit`, `condicion_iva`…
  son todos nullable), así que ser opcional es consistente, no una excepción.

**Alternativas consideradas**:
- *Validar formato de teléfono / URL*: descartada. Agrega fricción sin beneficio; el dato sólo se
  imprime, no se disca ni se navega programáticamente. Además un negocio argentino escribe el
  teléfono de cinco formas distintas y todas son correctas para un humano que lee un comprobante.
- *Columna JSON de "datos de contacto"*: sobre-ingeniería para dos campos en una tabla de una fila.

---

## Decisión 3 — Testear el partial renderizado, no el binario del PDF

**Decisión**: el test de contenido renderiza la **vista** del encabezado (y/o la vista de cada PDF)
y afirma sobre el HTML resultante. Los cinco PDFs se siguen cubriendo con el smoke test de que
responden OK.

**Rationale**: los tests de PDF que ya tiene el proyecto (`ReciboPdfTest`, `VentaPdfBonifTest`, …)
sólo verifican `assertOk()` y que el contenido no esté vacío — no pueden afirmar que un texto
aparece, porque el output de DomPDF es un binario comprimido donde el string no se encuentra con un
`assertStringContainsString`. Para verificar FR-003 y FR-005 hace falta mirar el HTML antes de que
DomPDF lo convierta.

**Alternativas consideradas**:
- *Parsear el PDF generado* (extraer texto del binario): frágil y lento, y agregaría una dependencia
  nueva sólo para este test.
- *Confiar en el smoke test existente*: no alcanza. Un `@if` mal escrito genera un PDF perfectamente
  válido que simplemente no muestra el teléfono, y el smoke test pasaría igual.

---

## Decisión 4 — Migración puramente aditiva sobre producción

**Decisión**: una migración que sólo hace `ADD COLUMN ... NULL` para las dos columnas, con su `down()`
que las dropea.

**Rationale**: el VPS tiene datos reales, incluidos comprobantes con CAE de ARCA. Agregar columnas
nullable a una tabla de **una sola fila** no reescribe datos, no toca ninguna otra tabla y es
reversible. Los registros existentes quedan con ambos campos en `NULL`, que es exactamente el estado
"todavía no lo cargaron" que FR-010 pide preservar, y que el partial ya sabe manejar (no imprime).

**Alternativas consideradas**:
- *Default `''` en vez de NULL*: peor. Obligaría a distinguir "vacío" de "no cargado" en el `@if`, y
  `@if('')` y `@if(null)` se comportan igual acá, así que el default no aporta nada.

---

## Decisión 5 — Reutilizar el mecanismo de guardado que ya tiene la pantalla

**Decisión**: agregar los dos inputs al modal existente `#modal-mi-perfil` y las dos reglas a
`MiPerfilController::guardar()`. No se toca `resources/js/mi-perfil.js`.

**Rationale**: el form ya se envía entero por AJAX (`FormData`) y ya muestra el resultado por toast,
cumpliendo las reglas 2 y 3 de diseño obligatorio de `CLAUDE.md`. Dos inputs de texto más viajan
solos, sin código JS nuevo.

Los campos nuevos son de texto plano: **no** aplican la regla 5 (Select2, que es para selects de datos
dinámicos) ni la regla 6 (`AppFecha`, que es para fechas). Se mencionan acá sólo para dejar
constancia de que se evaluaron y no corresponden.

**Alternativas consideradas**:
- *Endpoint separado para datos de contacto*: partiría en dos el guardado de una misma ficha, sin
  ninguna ganancia.
