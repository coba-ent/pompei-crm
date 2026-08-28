# Data Model: Comprobantes Históricos con CAE Real de ARCA (spec 088)

**Fecha**: 2026-08-28 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

---

## 1. Tabla nueva: `comprobantes_historicos_arca`

Sin relación a `ventas`, sin `deleted_at` (ver plan, Decisión 4). 14 filas fijas, cargadas por
migración, nunca por UI.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | autoincremental propio — **nunca comparar con `ventas.id`** (plan, Decisión 2) |
| `fecha_emision` | date | fecha real del comprobante ante ARCA |
| `tipo_comprobante` | char(1) | `'A'` o `'B'` — la letra sola, mismo formato que `ventas.tipo_comprobante` (plan, Decisión 1) |
| `punto_venta` | string | `'0009'`, mismo formato que se usa para separar `nro_comprobante` en los writers |
| `numero` | string | número de comprobante dentro del punto de venta |
| `cae` | string(14) | CAE real de ARCA |
| `cae_vencimiento` | date | vencimiento del CAE |
| `cliente_nombre` | string, nullable | denormalizado — null sólo para el comprobante reconstruido sin identificar |
| `cliente_documento_tipo` | string, nullable | `'CUIT'`/`'DNI'`/null |
| `cliente_documento_numero` | string, nullable | null cuando no hay documento |
| `neto_no_gravado` | decimal(14,2) | siempre 0 en este set (no aplica a estos 14) |
| `neto_exento` | decimal(14,2) | siempre 0 en este set |
| `neto_gravado` | decimal(14,2) | |
| `iva_2_5`, `iva_5`, `iva_10_5`, `iva_21`, `iva_27` | decimal(14,2) | mismas 5 columnas de alícuota que el Libro IVA (spec 077) — sólo `iva_21` tiene datos en este set, el resto en 0 |
| `perc_iva` | decimal(14,2) | siempre 0 en este set |
| `perc_iibb` | decimal(14,2) | siempre 0 en este set |
| `imp_internos` | decimal(14,2) | siempre 0 en este set |
| `imp_municipales` | decimal(14,2) | siempre 0 en este set |
| `total` | decimal(14,2) | tal como ARCA lo tiene aprobado — no se recalcula (spec FR-003) |
| `origen` | string | literal `'historico_migracion_agosto_2026'` — el campo que blinda `DatosFiscalesComprobante::clave()` (plan, Decisión 2); no se usa para nada más |
| `created_at`, `updated_at` | timestamps | estándar |

### Invariante

`total = neto_no_gravado + neto_exento + neto_gravado + iva_2_5 + iva_5 + iva_10_5 + iva_21 + iva_27
+ perc_iva + perc_iibb + imp_internos + imp_municipales`, con tolerancia de redondeo de centavos
(mismo criterio ya usado en specs 077/086) — se verifica en test contra los 14 valores reales antes de
insertarlos en la migración.

---

## 2. Los 14 registros (valores reales, verificados contra ARCA)

| # | Fecha | Tipo | Punto Vta | Número | CAE | Cliente | Documento | Neto (21%) | IVA (21%) | Total |
|---|---|---|---|---|---|---|---|---:|---:|---:|
| 1 | 2026-08-04 | B | 0009 | 1 | 86316816160690 | TANIA 1157822317 | — | 254189.89 | 53379.87 | 307569.76 |
| 2 | 2026-08-06 | B | 0009 | 2 | 86327160481043 | Michela 1171029567 | — | 2924.80 | 614.21 | 3539.01 |
| 3 | 2026-08-06 | B | 0009 | 3 | 86327177560817 | SILVINA 1159342461 | — | 128387.16 | 26961.30 | 155348.46 |
| 4 | 2026-08-07 | A | 0009 | 1 | 86327351450623 | ROBERTO 1162714317 | CUIT 23247526749 | 187674.81 | 39411.71 | 227086.52 |
| 5 | 2026-08-10 | B | 0009 | 4 | 86327719127823 | Valentin 1157505257 | — | 15482.06 | 3251.23 | 18733.29 |
| 6 | 2026-08-10 | A | 0009 | 2 | 86327738189254 | Freddy 1124594187 | CUIT 33504728469 | 19268.88 | 4046.46 | 23315.34 |
| 7 | 2026-08-10 | A | 0009 | 3 | 86327741754158 | MARTIN 1125427360 | CUIT 30717708446 | 164280.47 | 34498.90 | 198779.37 |
| 8 | 2026-08-10 | A | 0009 | 4 | 86327769430011 | ELBA 1145584795 | CUIT 30708230827 | 58066.96 | 12194.06 | 70261.02 |
| 9 | 2026-08-11 | A | 0009 | 5 | 86327942867854 | Arq Luis 1165325151 | CUIT 30594765350 | 299253.64 | 62843.27 | 362096.91 |
| 10 | 2026-08-12 | B | 0009 | 5 | 86328052554930 | Marina 1124695933 | — | 15942.86 | 3348.00 | 19290.86 |
| 11 | 2026-08-12 | A | 0009 | 6 | 86328111707744 | Carlos 1144702571 | CUIT 20106618489 | 25433.46 | 5341.03 | 30774.49 |
| 12 | 2026-08-13 | A | 0009 | 7 | 86338170324738 | STEPCZUKCARLOSJACOBO | CUIT 30710948581 | 34205.22 | 7183.10 | 41388.32 |
| 13 | 2026-08-13 | A | 0009 | 8 | 86338170408851 | STEPCZUKCARLOSJACOBO | CUIT 30710948581 | 34205.22 | 7183.10 | 41388.32 |
| 14 | 2026-08-13 | B | 0009 | 6 | 86338264884938 | *(sin identificar)* | — | 86742.81 | 18215.99 | 104958.80 |

Filas 12 y 13: la venta con doble CAE (misma operación, dos aprobaciones — spec Clarifications). Fila
14: reconstruida únicamente desde `FECompConsultar` de ARCA (spec, Assumptions) — sin cliente, sin
detalle de items, `DocTipo:99` de ARCA.

**Total agregado**: neto $1.326.058,24 + IVA $278.472,23 = **$1.604.530,47** entre los 14 (corregido:
la suma real de la tabla de arriba no cerraba con el total agregado que figuraba antes aquí —
diferencia de $520,00 — así que se recalculó a partir de las 14 filas, que son la fuente verificada
contra ARCA).

---

## 3. Cómo se lee esta tabla desde el resto del sistema

- `LibroIvaVentasQuery::queryHistoricos()` selecciona con las 19 columnas del contrato de spec 077,
  **en el mismo nombre y orden** que `queryVentas()`/`queryNotas()` (un desalineamiento en el
  `UNION ALL` no siempre falla en runtime — puede mezclar valores de columnas distintas en silencio).
  Filtra `fecha_emision` con `filtrarPeriodo()` (reusado sin cambios). El campo `tipo` que expone es
  `tipo_comprobante` tal cual (`'A'`/`'B'`) — no pasa por `filtrarArcaManuales()` (esa función sólo
  aplica sobre la rama de `queryVentas()`), así que siempre está presente, cumpliendo FR-010.
  El campo `nro_comprobante` que expone es la **concatenación** `CONCAT(punto_venta, '-', numero)` —
  la tabla guarda `punto_venta`/`numero` por separado (más natural para el `INSERT` de la migración),
  pero el resto del pipeline (`ComprobantesVentasWriter::separarNroComprobante()`) espera el string
  combinado `"PPPP-NNNNNNNN"`, igual que `ventas.nro_comprobante`.
- `DatosFiscalesComprobante::clave($fila)` devuelve `"historico:{id}"` cuando `$fila->origen ===
  'historico_migracion_agosto_2026'` (literal agregado por `queryHistoricos()`, no una columna real
  del resultado final más que como discriminador de rama).
- El neto por alícuota para los writers de IVA Digital sale directo de las columnas `iva_2_5`..`iva_27`
  de la fila — igual que ya pasa hoy para las filas de NC/ND, que tampoco llaman
  `netoPorAlicuotaVenta()`.

---

## 4. No-invariantes (a propósito)

- No hay `cliente_id` FK — ver plan, Decisión 3.
- No hay `venta_id` ni ninguna relación a `ventas` — es la base del aislamiento (spec FR-004).
- No hay `deleted_at` — ver plan, Decisión 4.
- No hay endpoint ni vista que liste esta tabla de forma independiente — sólo aparece mezclada dentro
  del Libro IVA Ventas / IVA Digital, igual que cualquier otro comprobante del período.
