# Condiciones de IVA del receptor — tabla real de ARCA

**Fuente**: `FEParamGetCondicionIvaReceptor`, consultado contra **ARCA producción** (CUIT
20273351249) el 18/09/2026. No es documentación de terceros: es la respuesta del servicio.

## La tabla

| Id | Descripción | Clases de comprobante |
|---|---|---|
| 1 | IVA Responsable Inscripto | **A / ALEY / C** |
| 6 | Responsable Monotributo | **A / ALEY / C** |
| 13 | Monotributista Social | A / ALEY / C |
| 16 | Monotributo Trabajador Independiente Promovido | A / ALEY / C |
| 4 | IVA Sujeto Exento | **B / C** |
| 7 | Sujeto No Categorizado | **B / C** |
| 8 | Proveedor del Exterior | B / C |
| 9 | Cliente del Exterior | B / C |
| 10 | IVA Liberado – Ley N° 19.640 | B / C |
| 15 | IVA No Alcanzado | B / C |
| 5 | Consumidor Final | **C / 49** |

## Qué significa `Cmp_Clase`

Es la lista de clases de comprobante para las que ese código es válido. **ARCA rechaza la
combinación, no el código**: el error 10243 dice *"no es valido para la clase de comprobante
informado"*, y por eso un código correcto en sí mismo puede ser rechazado según qué factura se
emita.

Las clases no son intercambiables con el tipo de comprobante del CRM sin leer esta tabla:

- **A** → Factura A
- **B** → Factura B
- **C** → Factura C (monotributista emisor)
- **ALEY**, **49** → regímenes especiales

## Contraste con `condiciones_iva` del CRM

| CRM | `codigo_afip` | En ARCA | Clases válidas | ¿Sirve para A? | ¿Para B? |
|---|---|---|---|---|---|
| Responsable Inscripto | 1 | ✅ Id 1 | A/ALEY/C | **Sí** | **No** |
| Monotributista | 6 | ✅ Id 6 | A/ALEY/C | **Sí** | **No** |
| Consumidor Final | 5 | ✅ Id 5 | C/49 | **No** | **No** |
| Exento | 4 | ✅ Id 4 | B/C | **No** | **Sí** |
| No Categorizado | 7 | ✅ Id 7 | B/C | **No** | **Sí** |

**Los cinco códigos existen y están bien mapeados.** El problema nunca fue el mapeo.

## El hallazgo

**Ninguna condición de IVA sirve para Factura B y para Factura A a la vez.** Las clases están
partidas: 1 y 6 son de A, y 4, 5 y 7 son de B/C. Así que la validez depende de qué comprobante se
emite, y el CRM hoy manda el código del cliente sin mirar eso.

Consecuencias medidas sobre las ventas desde el 13/08/2026:

- **Factura A**: 67 a Responsable Inscripto (código 1, válido para A) — correctas. Y **1 a
  Consumidor Final** (código 5, sólo C/49) → **ARCA la rechaza**.
- **Factura B**: 387 a Consumidor Final (código 5, sólo C/49) → **todas serían rechazadas**, más 15
  a Responsable Inscripto y 2 a Monotributista (códigos de clase A) → también rechazadas.
- 256 ventas B con cliente **sin condición cargada**: caen al default Consumidor Final (5), que
  tampoco vale para B.

O sea: el caso más común del negocio —**Factura B a Consumidor Final**— usa un código que ARCA no
admite para esa clase.

## Qué NO dice esta tabla

Cuál es el código correcto para una **Factura B a Consumidor Final**. La tabla lista 5 como C/49
únicamente, y no hay ninguna fila de clase B que describa a un consumidor final. Las candidatas por
descripción (15 "IVA No Alcanzado", 7 "Sujeto No Categorizado") no son equivalentes conceptuales, y
elegir una por parecido sería exactamente la adivinanza que FR-007 quiso evitar.

**Esto necesita una decisión informada** —contador o prueba contra homologación— antes de tocar el
mapeo. No se resuelve leyendo la tabla.

## Sobre la venta 25191

FLORDANA es **Responsable Inscripto**, código **1**, y la venta es **Factura A**. Según esta tabla
la combinación **es válida**: clase A está en `A/ALEY/C`.

Así que el rechazo 10243 de la 25191 **no se explica con esta tabla**. Queda pendiente reproducirlo
con la respuesta cruda del envío: el error pudo venir de otro campo del mismo comprobante, o de un
envío anterior con datos distintos.
