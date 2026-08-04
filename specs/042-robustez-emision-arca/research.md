# Research: Robustez de datos fiscales en la emisión de CAE (ARCA)

Sin `NEEDS CLARIFICATION` pendientes en el Technical Context (spec y clarify ya resolvieron el único
punto de ambigüedad material: tolerancia de redondeo). Este documento consolida las decisiones de
diseño derivadas de inspeccionar el código real de `app/Services/Arca/`.

## 1. Quiénes llaman a `EmisorComprobante::emitir()` hoy

- **`VentaController::enviarArca()`** (spec 040): arma `$datos` con `neto`/`iva`/`total` **agregados**
  de toda la Venta (`subtotal_con_descuento`, `total - subtotal_con_descuento`), sin desglose por
  ítem — es el llamador afectado por el incidente.
- **`NotaCreditoDebitoController`**: arma `$datos` con `neto`/`iva` calculados a partir del `monto`
  total de la NC/ND **asumiendo siempre 21%** (`monto / 1.21`) — no tiene ítems propios (la NC/ND se
  emite sobre un monto, no sobre un detalle de ítems). Fuera de alcance de esta spec tocar ese cálculo
  (spec 040 FR-010: "no se modifica la lógica de NC/ND").
- **`CompraController`**: **no llama** a `EmisorComprobante::emitir()`. El CAE de una Compra lo declara
  el Proveedor en su propio comprobante; el CRM sólo registra esos datos
  (`registrarComprobanteFiscalProveedor`). No hay nada que corregir ahí.

**Decisión**: `MapeadorComprobante::mapear()` acepta un nuevo parámetro **opcional** `items` (lista de
`{neto, iva_pct}` por alícuota). Si está presente (caso Venta), arma un bloque `AlicIva` por cada
alícuota distinta. Si está ausente (caso NC/ND), conserva el comportamiento actual (un único bloque,
alícuota indicada explícitamente vía `alicuota_iva_id` o 21% por defecto) — sin cambiar el
comportamiento observable de NC/ND, consistente con que esta spec no toca esa lógica.

**Rationale**: evita forzar a `NotaCreditoDebitoController` a tener un desglose de ítems que no posee,
preservando el contrato existente para ese caso, mientras corrige el caso real que causó el incidente
(Ventas, que sí tienen ítems con `iva_pct` propio).

## 2. Códigos ARCA de alícuota de IVA (`AlicIva.Id`)

Tabla oficial WSFEv1 (`FEParamGetTiposIva`), estándar y estable:

| Código ARCA | Alícuota |
|---|---|
| 3 | 0% |
| 4 | 10,5% |
| 5 | 21% |
| 6 | 27% |
| 8 | 5% |
| 9 | 2,5% |

**Decisión**: mapear `VentaItem.iva_pct` → código ARCA vía una tabla constante en
`MapeadorComprobante` (mismo patrón que la tabla `CBTE_TIPO_FACTURA` ya existente). Un `iva_pct` sin
entrada en la tabla es el caso de FR-004 (rechazo de precondición, alícuota no soportada).

**Alternativas consideradas**: consultar `FEParamGetTiposIva` en vivo contra ARCA en cada emisión —
descartado, agrega una llamada SOAP extra por emisión para un catálogo que no cambia en la práctica;
la tabla estática ya es el patrón usado para `CbteTipo`.

## 3. Tolerancia de redondeo ($0.01, ya fijada en clarify)

**Decisión**: `ValidadorDatosFiscales` suma `Importe` de todos los bloques `AlicIva` armados y compara
contra `ImpIVA` total (y análogamente `BaseImp` vs `ImpNeto`); si la diferencia absoluta supera $0.01,
rechaza antes de contactar a ARCA (FR-004). $0.01 cubre redondeo de centavos al prorratear neto/IVA
entre ítems de distinta alícuota, sin dejar pasar una inconsistencia real como la del incidente
(~$1500 de diferencia).

## 4. `CondicionIVAReceptorId`

- **Fuente**: `Cliente->condicionIva->codigo_afip` (tabla `condiciones_iva`, seed
  `CondicionIvaSeeder`) — ya usa los códigos oficiales de "Condición IVA Receptor" (RG 5616): 1
  Responsable Inscripto, 4 Exento, 5 Consumidor Final, 6 Monotributista, 7 No Categorizado.
- **Caso sin cliente identificado** (Consumidor Final anónimo, `DocTipo=99` en
  `MapeadorComprobante::documentoReceptor()`): se informa código 5 (Consumidor Final) por defecto,
  sin exigir que el `Cliente` tenga la relación `condicionIva` cargada (FR-007).
- **Caso con cliente identificado pero sin Condición de IVA cargada**: rechazo de precondición
  (FR-006) — evita mandar una solicitud que ARCA rechazará de todos modos a partir del 01/09/2026, y
  fuerza a completar un dato del cliente que debería estar cargado.

**Decisión de ubicación**: el mapeo `condicionIva → CondicionIVAReceptorId` vive en
`MapeadorComprobante::mapear()` (mismo lugar que ya arma `DocTipo`/`DocNro`), recibiendo el código ya
resuelto en `$datos['cliente']['condicion_iva_codigo']` — la resolución de "cuál es el código, y si
falta" (FR-006/FR-007) vive en `ValidadorDatosFiscales`, no en el controlador, siguiendo el patrón ya
existente de que `ValidadorDatosFiscales` es responsable de las precondiciones de datos fiscales antes
de armar la request.

## 5. Compatibilidad con `verificarPendiente()` / reconciliación (FR-011 spec 034)

`EmisorComprobante::verificarPendiente()` no vuelve a armar la solicitud (`FeCAEReq`) — sólo consulta
`FECompConsultar` por número de comprobante. No requiere cambios: los campos nuevos sólo afectan la
solicitud saliente (`FECAESolicitar`), no la consulta de reconciliación.
