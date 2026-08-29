# Tasks: Historial de importaciones — archivo descargable e informe de qué cambió

**Feature**: `093-historial-importaciones-detalle` | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

`[P]` = paralelizable dentro del bloque (archivos distintos, sin dependencia).

**Regla de esta feature**: el informe habla de precios y stock. Un informe que reporta un cambio que
no ocurrió es peor que no tenerlo — hace desconfiar de todo lo demás. Los tests de US1 usan el
**formato real del snapshot de producción**, no uno inventado.

---

## Fase 1 — El informe (US1, P1) 🎯 MVP

Es la mitad que no necesita migraciones: los datos ya están.

### Tests primero

- [x] **T001** `[P]` `InformeCambiosImportacionTest`: reproducir la **corrida real 5** — 192 filas,
      0 campos, 0 precios, 18 con cambio de stock, con el −181 de EMBALAJE JPD primero en la lista.
      Es el caso que motivó la spec.
- [x] **T002** `[P]` Fijar el **formato del JSON**: `precios_anteriores` y `stock_anterior` como
      **arrays de objetos**. ⚠️ Un lector que los trate como mapa `id => valor` tiene que hacer
      fallar este test — es el error que reportó 192 cambios inexistentes.
- [x] **T003** `[P]` Corrida **sin filas de snapshot** produce `informe_disponible: false` con
      motivo, no un informe de cero cambios (FR-007).
- [x] **T004** `[P]` Producto con **actividad posterior** queda marcado (FR-006); producto
      **eliminado** se identifica sin romper (FR-008); corrida **deshecha** se señala (FR-009).
- [x] **T005** `[P]` Lista de precios y depósito **eliminados** después de la importación: se nombran
      por id y el informe no rompe.

### Implementación

- [x] **T006** `InformeCambiosImportacion`: recibe una corrida y devuelve el resumen y los detalles
      del contrato. **Sólo lectura** (FR-011).
      ⚠️ **Consultas agregadas por corrida, no por fila**: 1.117 filas × 3 consultas son más de 3.000
      queries. Se traen productos, precios y stock de todos los ids de una vez y se compara en
      memoria.
- [x] **T007** Detección de actividad posterior reusando `limite_movimiento_stock_id`,
      `limite_venta_item_id` y `limite_compra_item_id` del snapshot — **ya existen** para el
      deshacer, no se inventa un mecanismo nuevo.
- [x] **T008** Endpoint del informe, con `advertencia_metodo` **en la respuesta** y no en la vista:
      es una limitación del dato y tiene que viajar con él.
- [x] **T009** Modal del informe: resumen, campos, precios por lista y stock ordenado por magnitud.
      Bootstrap + AJAX, sin recargar (FR-023).
- [x] **T010** Columna e ícono en el historial para abrir el informe; deshabilitado y explicado
      cuando no hay detalle.
- [x] **T011** Medir el informe sobre la corrida de **1.117 filas**: tiene que responder sin que la
      pantalla parezca colgada. Si no, revisar T006 antes de seguir.

**Punto de control**: abrir la corrida 5 en el historial y ver el −181 de EMBALAJE JPD sin tocar la
base de datos.

---

## Fase 2 — El archivo descargable (US2, P2)

- [x] **T012** Migración: `archivo_guardado_ruta`, `archivo_guardado_en`, `archivo_vencido_en` en
      `importacion_corridas`. Los tres estados se **derivan**, no se guarda un enum.
- [x] **T013** `[P]` `ArchivoImportacionDescargaTest`: descarga con nombre original; **403** sin
      permiso; **410** si venció; **422** si está registrado pero ilegible; nunca un archivo vacío.
- [x] **T014** `[P]` Test: dos corridas con el **mismo nombre de archivo** conservan cada una su
      copia (FR-017).
- [x] **T015** `[P]` Test: si el guardado del archivo falla, **la importación termina igual** y se
      registra que no se pudo guardar (FR-016). Es la garantía de que un disco lleno no impida
      actualizar precios.
- [x] **T016** Al confirmar, conservar el archivo asociándolo a la corrida — **fuera del camino
      crítico**: su fallo se registra y no propaga.
- [x] **T017** Endpoint de descarga con el permiso de importaciones (FR-014) y los códigos del
      contrato.
- [x] **T018** Columna de archivo en el historial con los **tres estados** distinguidos: disponible,
      nunca guardado, vencido.

---

## Fase 3 — La limpieza (US3, P3)

- [x] **T019** `[P]` `LimpiezaArchivosImportacionTest`: borra lo vencido y marca la corrida; **no**
      toca lo que está dentro del plazo; **no** toca archivos de importaciones sin confirmar (FR-022).
- [x] **T020** `[P]` Test: los archivos **sueltos sin corrida** también se eliminan (FR-021).
- [x] **T021** Comando `importaciones:limpiar-archivos` con `--dias` y `--dry-run`, agendado diario.
- [x] **T022** Plazo configurable, 90 días por defecto (FR-019).
- [ ] **T023** ⚠️ **PENDIENTE — requiere el VPS.** Primera corrida en producción con `--dry-run` y
      revisión de la lista antes de borrar: barre archivos que no están referenciados por ninguna
      fila, incluidos los **23 huérfanos actuales (9,2 MB)**. En local se verificó el `--dry-run`
      (detecta los sueltos y no borra nada), pero la corrida real en producción no se hizo: no se
      prueba en producción sin OK explícito.

---

## Fase 4 — Cierre

- [x] **T024** Suite en verde (las 3 fallas de `ImportacionFlujoTest` ya existían en `main`).
- [x] **T025** Verificado en el **navegador contra MySQL** (29/08/2026): historial con las columnas
      nuevas, modal del informe sobre la corrida de 1.117 filas, y descarga con nombre original.
- [x] **T026** Verificado contra las **4 corridas de la base local** (1.117 / 148 / 148 / 0 filas),
      incluida la que no tiene snapshot → `informe_disponible: false`. Rendimiento: **1,1 s y 10
      queries** para las 1.117 filas.
- [x] **T027** `documentacion_principal_crm.md` §2.4 actualizado con el informe, los tres estados
      del archivo, la limpieza y la nota de que `importacion_filas_snapshot` dejó de ser temporal.

---

## Dependencias

```
Fase 1  informe          ← MVP, entregable solo, sin migraciones
Fase 2  archivo          ← independiente de la Fase 1
   └──► Fase 3  limpieza (necesita las columnas de la Fase 2)
Fase 4  cierre
```

## Estrategia de entrega

- **Entregable mínimo: Fase 1.** Es la que responde la pregunta que originó todo, y no toca la base.
- **Fase 2 sin Fase 3 acumula archivos.** Si se entrega la 2, la 3 va en el mismo tramo.
