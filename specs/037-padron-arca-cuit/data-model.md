# Data Model: Consulta al Padrón Fiscal de ARCA

No se crean tablas nuevas. Esta feature opera sobre entidades ya existentes y modela un resultado
transitorio de consulta (no persistido como entidad propia).

## Resultado de Consulta al Padrón (transitorio, no persistido)

Representación en memoria del resultado de una llamada exitosa a `ws_sr_padron_a13`, devuelta por
`ClientePadron::consultarConstancia()` y consumida por los dos puntos de integración.

| Campo | Tipo | Descripción |
|---|---|---|
| `cuit` | string(11) | CUIT consultado, normalizado sin guiones |
| `encontrado` | bool | `false` si ARCA no tiene el CUIT en el padrón |
| `razon_social` | ?string | Nombre/razón social informado por ARCA |
| `domicilio_fiscal` | ?string | Domicilio fiscal (calle + altura) |
| `localidad_fiscal` | ?string | Localidad/partido informado |
| `condicion_iva_raw` | ?string | Texto de condición frente al IVA tal como lo devuelve ARCA (antes de mapear) |
| `condicion_iva_id` | ?int | FK a `condiciones_iva.id` ya mapeada (R6 de research.md), `null` si no matchea ninguna |
| `activo` | ?bool | Si el CUIT está activo en el padrón (informativo, no bloqueante) |

No tiene tabla propia ni se cachea entre sesiones (research.md: cada consulta es puntual). No
requiere migración.

## `Cliente` (existente — sin cambios de esquema)

Campos ya existentes que esta feature completa/actualiza según el resultado de una Consulta al
Padrón, sin modificar la migración:

- `cuit` (string(11), nullable) — no se sobrescribe por esta feature (es la clave de búsqueda, no
  un resultado).
- `razon_social` (string, nullable) — autocompletado por FR-002 (modal) / FR-007b (conversión de
  orden), sin pisar ediciones manuales (research.md R5).
- `domicilio_fiscal` (string, nullable) — ídem.
- `localidad_fiscal` (string(120), nullable) — ídem.
- `condicion_iva_id` (FK nullable a `condiciones_iva`) — ídem; en el flujo de conversión de orden,
  sólo se completa cuando estaba vacío (mismo criterio que ya aplica `completarDatosFiscalesSinPisar()`
  hoy con el valor por defecto "Consumidor Final" — ahora, si el padrón respondió, usa el valor
  confirmado por el padrón en lugar del default).

No se agregan columnas nuevas a `clientes`.

## `condiciones_iva` (existente — sin cambios de esquema)

Se usa como catálogo de destino del mapeo (research.md R6). No se agregan filas por esta feature.

## `TiendanubeOrden` / `MercadoLibreOrden` (existentes — sin cambios de esquema)

El CUIT del comprador ya existe hoy en estas entidades (`billing_document_number` en Tiendanube;
campo análogo en MercadoLibre) y se usa como entrada de la Consulta al Padrón. No se agrega ninguna
columna para guardar el resultado de la consulta en la orden — el resultado sólo afecta al `Cliente`
resuelto (vía `Cliente`, no vía la orden).
