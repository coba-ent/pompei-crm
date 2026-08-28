# Fixture: IVA Digital (régimen RG 3685)

Estos 5 archivos son una copia exacta de los generados por Contagram para el período **Agosto 2026**
de la cuenta real del cliente (carpeta `contador/` del repo, fuera de control de versiones).

Son la **fuente de verdad del formato**: todas las decisiones de layout de `research.md` (spec 086)
salen de decodificarlos posición por posición, no de documentación de terceros de ARCA.

## Contenido

- `Comprobantes Ventas Agosto 2026 Res 3685.txt` — 29 líneas, 266 caracteres cada una.
- `Alicuotas Ventas Agosto 2026 Res 3685.txt` — 29 líneas, 62 caracteres cada una.
- `Comprobantes Compras Agosto 2026 Res 3685.txt` — 27 líneas, 325 caracteres cada una.
- `Alicuotas Compras Agosto 2026 Res 3685.txt` — 27 líneas, 84 caracteres cada una.
- `IVA Digital Ventas y Compras Agosto 2026.zip` — los 4 anteriores empaquetados, tal como lo entrega Contagram.

## Defecto de origen conocido

Dos comprobantes de compra de MercadoLibre (`MERCADOLIBRE S.R.L.` y `MELI LOG SRL`) declaran
`Cantidad de alícuotas = 0` en `Comprobantes Compras...txt` pese a traer una fila de alícuota al 21%
con crédito fiscal computable en `Alicuotas Compras...txt`. Es un defecto de Contagram, no del CRM: se
corrige a propósito (FR-022, research.md Decisión 5) y el test de caracterización de estos archivos
(T003) lo fija como comportamiento esperado del fixture, no como bug a reproducir.
