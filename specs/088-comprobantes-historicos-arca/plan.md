# Implementation Plan: Comprobantes Históricos con CAE Real de ARCA

**Branch**: `088-comprobantes-historicos-arca` · **Fecha**: 2026-08-28 · **Spec**: [spec.md](./spec.md)

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent (migración + modelo de sólo lectura), reutiliza
`LibroIvaVentasQuery`/`LibroIvaQuery` (spec 077) e `IvaDigital\*` (spec 086) sin librerías nuevas

**Storage**: MySQL/MariaDB — tabla nueva `comprobantes_historicos_arca`, sin relación FK obligatoria
a `clientes` (ver Decisión 3)

**Testing**: PHPUnit (Feature tests contra SQLite en CI, verificación manual contra MySQL real antes
de deployar — mismo patrón que specs 086/087)

**Target Platform**: mismo backend Laravel del CRM (local + VPS de producción)

**Project Type**: módulo dentro de una app Laravel monolítica existente

**Performance Goals**: N/A — 14 filas fijas, sin crecimiento; no hay requisito de performance
distinto del resto del Libro IVA

**Constraints**: cero efectos secundarios sobre tesorería/stock/otras ventas (ver Regla de oro);
sin flujo de alta en UI (spec FR-007)

**Scale/Scope**: 14 registros fijos, una sola carga, ningún crecimiento previsto

## Constitution Check

*GATE: revisado antes de Fase 0 y re-chequeado tras el diseño de Fase 1.*

- **Principio I (docs de dominio)**: no aplica cambio de regla de negocio de dominio — no se toca
  `documentacion_principal_crm.md` más que para anotar la existencia de estos históricos (ver Fase 1).
- **Principio II (spec-driven)**: cumplido — esta es la spec 088, con clarify ya resuelto antes de plan.
- **Principio III (corrección fiscal)**: cumplido — no hay CAE inventado, los 14 valores vienen de
  ARCA/la base anterior verificados; sin soft delete porque no hay gestión activa (Decisión 4), no
  porque se ignore el principio.
- **Principio IV (testing fiscal)**: cumplido — Estrategia de test dedica los ítems 2-6 explícitamente
  a este código, incluido el test de aislamiento (antes/después) exigido por la Regla de oro.
- **Principio V (convenciones Laravel + español)**: tabla/columnas en español, snake_case, migración
  versionada estándar, sin `empresa_id` (single-tenant).

Sin violaciones. No aplica Complexity Tracking.

---

## Resumen técnico

Se agregan 14 registros fiscales fijos (13 comprobantes de venta con CAE real de ARCA, uno de ellos
con doble CAE) que quedaron fuera de la base actual por la migración de agosto. Viven en una tabla
nueva, completamente ajena al modelo `Venta` — así el aislamiento del resto del CRM (FR-004 a FR-007)
se cumple **por diseño**, no por auditoría de cada módulo que hoy suma ventas.

Se integran al Libro IVA Ventas (spec 077) y al IVA Digital (spec 086) con el mismo mecanismo de
`UNION ALL` que esos módulos ya usan para agregar Notas de Crédito/Débito — no una técnica nueva. La
carga es una migración de Laravel con `INSERT` de valores fijos: reproducible en cualquier entorno
(local, VPS) sin depender de la base anterior (`contagram`), que puede dejar de existir sin que esto
se rompa.

**Constitución aplicable**: principio III (corrección fiscal innegociable — son 14 comprobantes con
CAE real, verificados contra ARCA) y principio IV (testing obligatorio en todo lo fiscal).

---

## Regla de oro de esta feature

**El sistema en producción ya está en uso real (Pompei Sanitarios).** Esta feature es puramente
informativa — completa lo que el contador necesita ver, sin tocar un solo peso de lo que el negocio
ya está operando hoy. Ningún componente de esta feature puede, bajo ninguna circunstancia:

- Crear o modificar un cobro, un movimiento de tesorería, o el saldo de una cuenta.
- Descontar, reintegrar o ajustar stock.
- Alterar una venta existente en `ventas` (las ventas reimportadas de la migración se quedan tal
  como están — no se "corrigen" para que coincidan con la base anterior; son datos independientes).
- Aparecer en ningún cálculo, KPI o informe que no sea explícitamente Libro IVA Ventas / IVA Digital.

Todo el plan de abajo está diseñado alrededor de esta regla, no como un detalle más: la tabla nueva
existe completamente aislada del modelo `Venta` precisamente para que sea imposible, por construcción,
que un futuro cambio en otro módulo empiece a sumarlos sin querer.

## El riesgo real de esta feature (y por qué el plan está diseñado así)

No es "agregar una tabla" — es tocar `LibroIvaVentasQuery::detalle()` e `IvaDigitalPaquete`, que son
código fiscal en producción, ya probado, con ~340 comprobantes reales pasando por ahí cada mes. El
riesgo no es que fallen los 14 históricos: es que se rompa algo de lo que ya funciona.

Investigando el código existente para este plan se encontró un problema concreto que el `UNION ALL`
por sí solo **no** resuelve: `DatosFiscalesComprobante::resolverVentas()` (IVA Digital) decide si una
fila de `detalle()` es "comprobante" o "nota" mirando sólo el prefijo de `tipo` (`NC`/`ND` vs. el
resto), y para cualquier "comprobante" hace `Venta::whereIn('id', $idsComprobante)`. Los 14 históricos
tienen `id`s propios de su tabla nueva — si alguno coincide numéricamente con el `id` de una venta real
actual (varios ya coinciden: id 1, 68, 71...), `Venta::whereIn` **la encuentra igual** y le pega al
histórico los datos de la venta equivocada. Es exactamente el tipo de bug silencioso que la
constitución (principio III) no tolera. Por eso el plan agrega una tercera categoría explícita
(`historico`), no solo dos.

---

## Arquitectura

```
LibroIvaVentasQuery::detalle()
        │
        ├── queryVentas()      (spec 077, sin cambios)
        ├── queryNotas()       (spec 077, sin cambios)
        └── queryHistoricos()  (NUEVO) ── UNION ALL, mismo contrato de 19 columnas
                    │
                    ▼
            comprobantes_historicos_arca   (tabla nueva, ajena a `ventas`)


IvaDigitalPaquete → ComprobantesVentasWriter / AlicuotasVentasWriter
        │
        ▼
DatosFiscalesComprobante::clave()      ── EXTENDIDO: 'comprobante' | 'nota' | 'historico'
DatosFiscalesComprobante::resolverVentas()   ── agrega rama histórico, sin tocar Venta::whereIn
```

**Por qué `UNION ALL` y no una query separada combinada después**: `LibroIvaVentasQuery` ya resuelve
ahí el período fiscal, el orden determinístico y las 19 columnas del contrato de spec 077 (DataTables +
export). Combinar en un punto más arriba (controller/export) obligaría a tocar cada consumidor de
`detalle()` — DataTables, `totales()`, `LibroIvaExport`, `IvaDigitalPaquete` — para que sepa sumar una
segunda fuente. Con `UNION ALL`, todos esos consumidores siguen viendo "una lista de filas", sin
saber que una fracción viene de otro lado.

---

## Componentes

### 1. Migración `comprobantes_historicos_arca` (nueva)

Crea la tabla y **carga los 14 registros con `INSERT` de valores fijos en la misma migración** — no
una consulta a la base `contagram` anterior en tiempo de ejecución. Columnas: `fecha_emision`, `tipo`
(A/B), `punto_venta`, `numero`, `cae`, `cae_vencimiento`, `cliente_nombre`, `cliente_documento_tipo`,
`cliente_documento_numero` (nullable, para el caso sin identificar), `neto_no_gravado`, `neto_exento`,
`neto_gravado`, `iva_2_5`..`iva_27` (mismas 5 columnas de alícuota que `venta_items`/Libro IVA),
`perc_iva`, `perc_iibb`, `imp_internos`, `imp_municipales`, `total`. Sin `deleted_at` (no aplica soft
delete: es un conjunto fijo, no se borra desde el sistema — ver spec, Out of Scope).

Los 14 valores exactos (neto, IVA, total, CAE) ya están relevados: 12 de la base `contagram` anterior,
1 reconstruido de la respuesta de `FECompConsultar` de ARCA, y la venta con doble CAE aporta dos filas
con el mismo detalle económico y distinto número/CAE (spec, Clarifications).

### 2. `queryHistoricos()` en `LibroIvaVentasQuery` (extensión)

Nuevo método privado, mismo patrón que `queryNotas()`: selecciona de la tabla nueva con las 19
columnas exactas del contrato (mismo alias que usa `queryVentas()`/`queryNotas()`), filtra por
`fecha_emision` con `filtrarPeriodo()` (ya existe, reusado tal cual), y se agrega al `unionAll()` de
`detalle()`. El campo `tipo` de esta rama es la letra sola (`'A'`/`'B'`), igual que un comprobante
normal — así clasifica como aprobado por ARCA en el filtro Electrónicas/Manuales sin tocar
`filtrarArcaManuales()` (research: ver Decisión 1 abajo).

### 3. Extensión de `DatosFiscalesComprobante` (IVA Digital)

- `clave()` gana una tercera rama: `historico:{id}` cuando el `id` corresponde a la tabla nueva (se
  distingue por un campo propio del `id`, no por rango numérico — ver Decisión 2).
- `resolverVentas()` agrega una consulta a `comprobantes_historicos_arca` para esos ids, sin pasar por
  `Venta::whereIn`.
- `netoPorAlicuotaVenta()` no se usa para históricos: sus alícuotas ya vienen fijas en la fila (mismas
  columnas `iva_2_5`..`iva_27` que la query expone), igual que hoy pasa con las NC/ND.

### 4. Writers de IVA Digital (ajuste mínimo)

`ComprobantesVentasWriter`/`AlicuotasVentasWriter` ya reciben el neto por alícuota como parámetro
opcional (mecanismo que NC/ND ya usa, cayendo a `[]` cuando no aplica `netoPorAlicuotaVenta`). Para un
histórico, el neto por alícuota sale directo de las columnas de la fila, igual que para NC/ND — no
hace falta lógica nueva, sólo que `ComprobantesVentasWriter::escribir()` detecte `historico` (no sólo
`esNota()`) para no llamar a `netoPorAlicuotaVenta((int) $fila->id)` con un id que no es de `ventas`.

### 5. Modelo `ComprobanteHistoricoArca` (nuevo, opcional/documentación)

Modelo Eloquent simple, sólo lectura, sobre la tabla nueva — usado únicamente por la migración (para
el `INSERT` vía `Model::insert()`, más legible que SQL crudo) y por tests. No se expone en ninguna
pantalla, no tiene controlador, no tiene rutas (spec, FR-007: sin flujo de alta futuro).

---

## Decisiones de diseño

### Decisión 1 — El `tipo` de la fila histórica es la letra sola, no un prefijo nuevo

**Elegido**: la columna `tipo` que expone `queryHistoricos()` es `'A'` o `'B'`, exactamente como
`queryVentas()` para un comprobante normal.

**Por qué**: `filtrarArcaManuales()` (spec 077, FR-014) sólo se aplica a la rama `queryVentas()` de
`LibroIvaVentasQuery::detalle()` — los históricos, al ser una tercera rama con `UNION ALL`, no pasan
por ese filtro en absoluto y siempre aparecen (correcto: FR-010 de la spec 088 exige que sean
**siempre** electrónicos, nunca filtrables como manuales). Usar la letra sola evita que
`ComprobantesVentasWriter::tipoLetra()` (`substr($tipo, -1)`) necesite un caso especial: `'A'` y `'B'`
ya funcionan tal cual con el código existente.

### Decisión 2 — Distinguir "histórico" sin depender del rango de `id`

**Elegido**: `DatosFiscalesComprobante::clave()` identifica un histórico por un campo explícito en la
fila (`$fila->origen === 'historico'`, agregado por `queryHistoricos()` como literal SQL), no por
comparar rangos de `id`.

**Por qué**: los `id`s de la tabla nueva son autoincrementales propios (1 a 14) y **coinciden en valor**
con `id`s reales de `ventas` (la venta actual con `id=1` existe). Cualquier heurística basada en el
valor del id es una bomba de tiempo — el día que la tabla `ventas` tenga más de 14 filas (ya tiene
miles), un id de histórico bajo puede coincidir con un id de venta real bajo. Un campo de origen
explícito, literal en el SQL de la propia query, es inequívoco y no depende de que los rangos no se
crucen nunca.

### Decisión 3 — Sin relación a `Cliente` para el caso sin identificar

**Elegido**: la tabla nueva guarda `cliente_nombre`/`cliente_documento_*` como columnas propias, no
un `cliente_id` obligatorio — nullable para el comprobante reconstruido sólo desde ARCA (spec,
Clarifications: sin cliente vinculado, Consumidor Final sin identificar).

**Por qué**: de los 14, 12 sí tienen un cliente real (mismo `cliente_id` que en la base actual — spec,
Assumptions) pero 2 (los del CAE duplicado de la venta 122) también, y uno (el reconstruido) no tiene
ninguno. Forzar una FK a `clientes` para el caso sin identificar exigiría crear un cliente ficticio
"Consumidor Final sin identificar" — que es exactamente el tipo de dato inventado que el principio de
corrección fiscal de la constitución no permite. Columnas propias, nullable, evitan esa invención:
para los 12/14 que sí tienen cliente real, se guarda su nombre/documento tal cual (denormalizado, pero
correcto — no van a cambiar nunca, es historia congelada).

### Decisión 4 — Sin soft delete, sin actualización, tabla de sólo lectura

**Elegido**: la tabla no tiene `deleted_at` ni se expone ningún flujo de edición/borrado.

**Por qué**: spec FR-007 — conjunto cerrado y fijo, no una funcionalidad operativa. El principio III de
la constitución exige soft delete para documentos fiscales que el sistema **gestiona activamente**;
acá no hay gestión activa, es un registro congelado de algo que ya pasó y que el sistema sólo declara
en informes de sólo lectura. Si algún día hiciera falta corregir un valor, es una migración nueva
puntual (mismo patrón que la carga inicial), no una función de edición en la UI.

---

## Estrategia de test

1. **Test de aislamiento (el más importante — FR-004 a FR-007)**: cargar los 14 históricos (vía la
   migración real, no un factory) y verificar que Reporte Final, KPIs de ventas, Informe de Stock y
   Cuenta Corriente de Clientes dan exactamente el mismo resultado que sin ellos. No alcanza con "no
   rompe" — hay que probar contra un before/after real.
2. **Test de integración al Libro IVA Ventas**: los 14 aparecen en `detalle()` del período correcto,
   con los importes exactos verificados contra ARCA, sumando a los totales del período.
3. **Test de integración al IVA Digital**: el archivo generado para Agosto 2026 incluye las 14 líneas
   con el formato posicional correcto — mismo patrón de test que ya usa spec 086 (parseo campo por
   campo contra valores esperados, no `assertEquals` de archivo completo).
4. **Test de la venta con doble CAE**: las dos filas aparecen separadas, cada una con su número/CAE
   propio, ninguna se pisa ni se fusiona.
5. **Test de que `DatosFiscalesComprobante` no confunde histórico con venta real**: el caso concreto
   que motivó la Decisión 2 — crear una Venta real con `id=1` (coincide con el histórico id 1) y
   verificar que el IVA Digital no le pega los datos de la venta real al histórico ni viceversa.
6. **Verificación manual con MySQL real** (memoria del proyecto: la suite verde en SQLite no
   garantiza el comportamiento en MySQL) — comparar el Libro IVA Ventas y el IVA Digital de Agosto
   2026 generados en local contra los importes ya verificados con ARCA antes de deployar.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un histórico "roba" los datos de una venta real con el mismo id (o viceversa) | Decisión 2: campo de origen explícito, nunca inferencia por rango de id; test dedicado |
| El `UNION ALL` nuevo cambia el orden/totales del Libro IVA para datos que no son históricos | `queryVentas()`/`queryNotas()` quedan sin tocar; test de regresión con datos reales del período que ya pasa hoy |
| El comprobante sin cliente identificado rompe `MapeadorComprobante::documentoReceptor()` | Ya soporta `tipo_documento: null` → código 99 (mismo camino que hoy usa un cliente real sin CUIT/DNI) |
| La migración con `INSERT` fijo se corre dos veces y duplica los 14 registros | Migración estándar de Laravel: `migrate` no re-corre una migración ya aplicada: mismo mecanismo que toda migración con seed en este proyecto |
| Se filtra un histórico como "Manual" por error, o aparece en compras | Decisión 1 (tipo = letra sola, elude `filtrarArcaManuales`); la tabla sólo tiene comprobantes de VENTA, no hay rama de compras |

---

## Fuera de alcance

Corregir la base anterior (`contagram`); resolver ante ARCA el comprobante duplicado de la venta 122
(Nota de Crédito, consulta al contador); una pantalla o flujo de alta para futuros casos similares;
tocar `LibroIvaComprasQuery` (no hay comprobantes históricos de compra en este caso).

---

## Project Structure

### Documentación (esta feature)

```text
specs/088-comprobantes-historicos-arca/
├── plan.md              # este archivo
├── data-model.md         # Fase 1
├── tasks.md              # /speckit-tasks
└── checklists/
    └── requirements.md
```

### Código (repo root)

```text
database/migrations/
└── 2026_08_28_XXXXXX_create_comprobantes_historicos_arca_table.php   # crea tabla + INSERT de los 14

app/Models/
└── ComprobanteHistoricoArca.php     # modelo simple, sólo lectura, usado por la migración y tests

app/Services/Informes/
└── LibroIvaVentasQuery.php          # + método privado queryHistoricos(), sumado al unionAll() de detalle()

app/Services/Informes/IvaDigital/
├── DatosFiscalesComprobante.php     # clave() gana rama 'historico'; resolverVentas() la resuelve sin Venta::whereIn
└── ComprobantesVentasWriter.php     # detecta 'historico' además de esNota() antes de llamar netoPorAlicuotaVenta()

tests/Feature/Informes/
└── ComprobantesHistoricosArcaTest.php   # test de aislamiento + integración Libro IVA/IVA Digital
```

**Structure Decision**: módulo agregado dentro de la app Laravel monolítica existente, sin carpeta
propia — sigue el mismo patrón que specs 077/086 (`Services/Informes/`), porque es una extensión de
esos dos módulos, no un módulo nuevo con su propia UI/rutas.
