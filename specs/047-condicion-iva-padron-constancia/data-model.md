# Data Model: Condición de IVA en el autocompletado del Padrón de ARCA

Sin migraciones nuevas — no se agregan columnas ni tablas. Se extiende un resultado transitorio ya
existente (spec 037) y se documenta la forma real de la respuesta SOAP consumida.

## Consulta de Constancia de Inscripción (nueva, transitoria)

Resultado de una consulta puntual a `ws_sr_constancia_inscripcion` (`personaServiceA5`, método `getPersona`)
por un CUIT/CUIL. No se persiste como entidad propia — vive sólo durante la request que la origina (igual
que "Consulta de Padrón" de la spec 037).

| Campo | Origen (respuesta SOAP real) | Notas |
|---|---|---|
| `cuit` | parámetro de entrada | mismo CUIT consultado |
| `encontrado` | `personaReturn.datosGenerales` presente | `false` si `personaReturn` es `null` o sin `datosGenerales` |
| `condicionIvaRaw` | derivado de `datosRegimenGeneral.impuesto[]` / `datosMonotributo` (ver research.md R4) | no es un campo directo de la respuesta — se calcula |
| `condicionIvaId` | mapeo de `condicionIvaRaw` a `condiciones_iva.id` | mismo catálogo y mismo criterio de fallback (`null` si no matchea) ya definido en spec 037 R6 |

## `ResultadoConsultaPadron` (extendido, ya existente — `app/Services/Arca/ResultadoConsultaPadron.php`)

Pasa a poder construirse fusionando dos fuentes en vez de una sola. Los campos ya existentes
(`razonSocial`, `domicilioFiscal`, `localidadFiscal`, `activo`) siguen viniendo exclusivamente de
`ws_sr_padron_a13` (sin cambios — ese servicio ya los resuelve bien). El campo `condicionIvaId`
(ya existente en la clase, hoy siempre `null` en la práctica por el bug de R1) pasa a completarse desde la
segunda consulta cuando está disponible.

| Campo | Fuente (antes) | Fuente (después de esta feature) |
|---|---|---|
| `cuit` | input | sin cambios |
| `encontrado` | `ws_sr_padron_a13` | sin cambios |
| `razonSocial` | `ws_sr_padron_a13` | sin cambios |
| `domicilioFiscal` | `ws_sr_padron_a13` | sin cambios |
| `localidadFiscal` | `ws_sr_padron_a13` | sin cambios |
| `activo` | `ws_sr_padron_a13` | sin cambios |
| `condicionIvaRaw` | `ws_sr_padron_a13` (nunca poblado — bug) | `ws_sr_constancia_inscripcion`, best effort |
| `condicionIvaId` | `ws_sr_padron_a13` (nunca poblado — bug) | `ws_sr_constancia_inscripcion`, best effort |

No cambia el contrato público de `Cliente` (`condicion_iva_id` ya existe en la tabla `clientes`, spec
original de Base de Datos) ni el de `condiciones_iva`.

## Sin nuevas entidades de dominio

`Cliente`, `condiciones_iva`, `Orden` (Tiendanube/MercadoLibre) se usan tal cual ya existen — ver
`docs/modelo_datos.md`. Esta feature no requiere cambios ahí.
