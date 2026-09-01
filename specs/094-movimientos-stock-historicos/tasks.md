# Tasks — spec 094

`[P]` = paralelizable dentro del bloque.

**Regla de esta feature**: la garantía no es el cuidado de quien la corre, es la verificación
automática. Ninguna tarea de escritura se da por buena sin su verificación asociada.

---

## Fase 1 — Leer el Excel bien

- [x] **T001** `[P]` `LectorInformeStockTest`: el export **repite cada movimiento por depósito** y
      sólo uno lleva cantidad. Fixture con el caso real del producto 27203 (cant=2 en Tiendanube,
      cant=0 en Local y Full). El lector devuelve **una** fila, no tres. Es el error que haría
      cargar 22.326 movimientos fantasma.
- [x] **T002** `[P]` Test de fechas en los **dos formatos** en la misma columna: texto `12/30/2024` y
      serial `45389`. Ambos dan la misma fecha. Una fecha fuera del año del archivo **aborta**.
- [x] **T003** `[P]` Test: `Registro Inicial` con cantidad 0 no produce movimiento (FR-008-ter).
- [x] **T004** `LectorInformeStockContagram`: cabecera en fila 4, descarte de cantidad 0, mapeo de
      depósito, parseo tolerante de fecha.
- [x] **T005** Correr el lector sobre los **tres archivos reales** y verificar los números de la
      spec: 53.844 filas leídas, 22.326 descartadas, **31.518 movimientos**. Si no dan, el lector
      está mal antes de seguir.

## Fase 2 — Matchear con las operaciones

- [x] **T006** `[P]` `ResolvedorOperacionLegacyTest`: venta 15963 de 2024 → `2024-FC-15963` → id
      15963. Compra 1883 de 2025 → `COMPRA-2025-FC-1883` → id 1883. Un ID inexistente **no
      resuelve** y no se fuerza (FR-003).
- [x] **T007** `[P]` Test: producto por `codigo`; un código inexistente se saltea y se reporta
      (FR-005).
- [x] **T008** `ResolvedorOperacionLegacy` **por lote**. ⚠️ Una consulta por fila son 31.518 queries.
      Se traen todos los `legacy_id` de una vez y se matchea en memoria.
- [x] **T009** Medir el resolvedor sobre las 31.518 filas: tiene que resolver en menos de una decena
      de consultas totales, no por fila.

## Fase 3 — El corte

- [x] **T010** `[P]` `FiltroCorteMigracionTest`: una fila cuya operación **ya tiene movimiento** se
      saltea (FR-006). Caso explícito: una compra con `fecha` del 06/08/2026 — anterior al corte del
      13/08 — que ya tiene movimiento. **Un corte por fecha la duplicaría**; este test lo impide.
- [x] **T011** `[P]` Test: fila **sin ID** con fecha ≥ 13/08/2026 se saltea (FR-007).
- [x] **T012** `FiltroCorteMigracion` con el set de operaciones ya movidas precargado.

## Fase 4 — La garantía

- [x] **T013** `[P]` `VerificadorCargaHistoricaTest`: si un producto cambia su `stock_actual` entre
      las dos fotos, el verificador **falla**. Es el test que protege el requisito central.
- [x] **T014** `[P]` Test: si una publicación de ML queda con `stock_pendiente` que no tenía, el
      verificador **falla** (FR-015).
- [x] **T015** `VerificadorCargaHistorica`: foto de `stock_actual` de los 9.781 productos y de las
      publicaciones pendientes; comparación estricta.
- [x] **T016** Migración: `carga_historica_id` en `movimientos_stock`, nullable e indexada.

## Fase 5 — El comando

- [x] **T017** `[P]` Test de integración: insertar por el comando **no dispara**
      `MovimientoStockObserver`. ⚠️ Es el riesgo más grave de la spec: con 31.518 inserciones por
      Eloquent, el sincronizador empujaría stock histórico a ML y Tiendanube. El test verifica que
      ninguna publicación quedó marcada.
- [x] **T018** `[P]` Test: el comando **nunca escribe en `stocks`** (FR-012).
- [x] **T019** `[P]` Test: `--dry-run` es el default y no escribe nada (FR-017).
- [x] **T020** `[P]` Test: correr dos veces no duplica movimientos (FR-019).
- [x] **T021** `[P]` Test: `--deshacer` borra exactamente lo de esa corrida y nada más (FR-018).
- [x] **T022** `stock:importar-movimientos-historicos` con inserción por **query builder**, lotes de
      500, transacción y verificación final que revierte.
- [x] **T023** Informe de la corrida: leídas, descartadas por cantidad 0, salteadas por corte,
      matcheadas, huérfanas, productos no encontrados, y filas del depósito Tiendanube.
- [x] **T024** Verificación de `Saldo Stock` (FR-016) con `--verificar-saldos`.
      ⚠️ **`Saldo Stock` no es el saldo del producto: es el TOTAL del inventario.** Se descubrió al
      implementarlo — interpretarlo como saldo por producto daba 28.391 discrepancias sobre 28.853
      comparaciones. Con la semántica correcta (`saldo[i-1] - saldo[i] == cantidad[i-1]`, porque el
      export va del más nuevo al más viejo): **53.841 pares comparados en los tres años, 0
      discrepancias.**

## Fase 6 — Validación real ⚠️ ninguna de estas es opcional

- [x] **T025** Dump fresco del VPS (01/09/2026), md5 verificado en origen y destino, restaurado en
      `contagram_094_prueba` (S-01, S-02). El dump temporal se borró del VPS.
- [x] **T026** Dry-run sobre el dump fresco: **30.712 a cargar** (4 menos que en el clon, porque el
      corte detectó 54 operaciones ya movidas en vez de 50 — actividad nueva de producción).
- [x] **T027** Corrida real sobre el dump fresco con `--escribir` (S-03).
- [x] **T028** Verificado: las **9.177 filas de stock idénticas** (md5 `8e00fb31...`), 3 pendientes
      de ML sin cambios y las 13 de Tiendanube **ya estaban pendientes en producción** — confirmado
      consultando el VPS, no asumido (S-04, S-05).
- [x] **T029** `--deshacer` sobre el dump fresco: **1.394 → 32.106 → 1.394**, md5 idéntico (S-06).
- [x] **T030** Verificado el historial de la alacena 27204: 24 movimientos desde enero 2024, en
      orden cronológico y con el saldo acumulado coherente. Verificado además que
      `InformeStockController` ordena y filtra por `mov.fecha` (no por `created_at`), y que el saldo
      acumulado se calcula `ORDER BY fecha, id` — o sea que los movimientos históricos aparecen en
      su lugar cronológico, no amontonados al final (SC-001, S-07).

## Fase 7 — Producción

- [x] **T031** Backup de producción: `contagram_ANTES_094_20260901_005104.sql.gz`, 10 MB (S-08).
- [x] **T032** Dry-run en producción: **30.712 a cargar, 0 discrepancias de saldos** — idéntico a la
      prueba sobre el dump. Los tres Excel se subieron con md5 verificado en origen y destino (S-09, S-10).
- [x] **T033** Corrida real con OK del usuario (01/09/2026): **30.712 movimientos cargados**,
      corrida `20260901035814`.
- [x] **T034** Verificación posterior: **md5 de `stocks` idéntico** (`99460ea3...`, 9.177 filas),
      ML y Tiendanube sin cambios, 0 eventos de auditoría, y los pendientes de sincronización en los
      mismos valores previos (3 y 13). **7.963 de 9.156 productos (87%) cierran exacto** (S-12 a S-15).
- [ ] **T035** Actualizar `documentacion_principal_crm.md` y `modelo_datos.md`:
      `carga_historica_id` y la nota de que el histórico arranca en 2024.

---

## Dependencias

```
Fase 1 (lector)  ──►  Fase 2 (matcheo)  ──►  Fase 3 (corte)  ──►  Fase 5 (comando)
Fase 4 (garantía) ─────────────────────────────────────────────►
Fase 6 (clon) ──► Fase 7 (producción)
```

## Nota de entrega

**La Fase 6 no se saltea.** El usuario pidió una garantía absoluta; la única forma de darla es correr
el proceso completo, incluido el deshacer, sobre una copia real antes de tocar producción.
