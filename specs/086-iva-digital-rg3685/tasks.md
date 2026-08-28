# Tasks: IVA Digital — archivos del régimen RG 3685 (spec 086)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Data model**: [data-model.md](./data-model.md)

`[P]` = paralelizable con las tareas marcadas igual dentro de la misma fase.

---

## Fase 0 — Fixture y andamiaje

- [X] **T001** Copiar los 5 archivos de `contador/` a `tests/Fixtures/IvaDigital/` (los 4 TXT + el ZIP)
      y agregar un `README.md` corto explicando qué son, de dónde salieron (Contagram, Agosto 2026,
      cuenta real) y que **son la fuente de verdad del formato**.
      *La suite no puede depender de una carpeta de trabajo del usuario.*

- [X] **T002** `[P]` Escribir un helper de test `parsearRegistro(string $linea, array $layout): array`
      que descomponga una línea en campos según las tablas de research §1, y las 4 constantes de layout
      (`LAYOUT_COMPROBANTES_VENTAS`, etc.). *Es la herramienta que hace legibles todos los tests
      posteriores: sin esto, comparar 266 columnas es ilegible.*

- [X] **T003** `[P]` Test de caracterización del fixture: verificar que los 4 archivos del fixture
      cumplen las invariantes de formato (anchos 266/62/325/84, CRLF, latin-1, sin encabezado) y las
      cruzadas (sin alícuotas huérfanas, crédito fiscal = suma de IVA).
      **Debe fallar exactamente en los 2 comprobantes de MercadoLibre** con `Cantidad de alícuotas = 0`.
      *Fija el defecto del origen como hecho verificado antes de escribir una línea de producción.*

## Fase 1 — Primitiva de ancho fijo (US1, US2)

- [X] **T004** Crear `app/Support/ArchivosFiscales/RegistroAnchoFijo.php` con `numerico`, `importe`,
      `alfanumerico`, `fecha`, `alicuota` y `linea`. La conversión a latin-1 va **antes** del padding.
      `linea()` **verifica el ancho total y lanza** si no coincide (plan §Componentes 1).

- [X] **T005** Tests unitarios de `RegistroAnchoFijo`, cubriendo los casos que rompen el archivo:
      importe con decimales que redondean hacia arriba; texto más largo que el campo (truncado, sin
      puntos suspensivos); texto con `Ñ` y acentos (ancho **en bytes**); valor nulo; y `linea()` con
      ancho equivocado (debe lanzar).
      *FR-023 se decide acá: es el test que atrapa una regresión a UTF-8.*

## Fase 2 — Códigos ARCA

- [X] **T006** Extender `MapeadorComprobante` con lo que falta: tipo de documento `99` (sin
      identificar) y los tipos de comprobante de compra observados en el fixture (`001`, `002`, `003`,
      `006`). **Extender, no duplicar** (research §6).

- [X] **T007** `[P]` Tests de los códigos nuevos, verificando que producen exactamente los valores del
      fixture (`80`, `96`, `99`, `0005`, `001`, `006`).

## Fase 3 — Writers (US1)

- [X] **T008** `AlicuotasVentasWriter` (62) — layout declarado como tabla espejo de research §1.
      Devuelve, además de escribir, el **conteo de filas por comprobante**, que consume T010.

- [X] **T009** `[P]` `AlicuotasComprasWriter` (84). **Ojo**: lleva código y número de documento del
      vendedor que el de ventas no tiene — 22 caracteres de diferencia (plan §Arquitectura).

- [X] **T010** `ComprobantesVentasWriter` (266). Recibe el conteo de T008 para el campo "Cantidad de
      alícuotas": así FR-016 se cumple **por construcción**. Depende de T008.

- [X] **T011** `ComprobantesComprasWriter` (325). Recibe el conteo de T009 y calcula el crédito fiscal
      computable como suma del IVA de las alícuotas (FR-018). Depende de T009.

- [X] **T012** Tests posicionales campo por campo de cada writer contra el fixture, usando el helper de
      T002 y reportando `(línea, campo, esperado, obtenido)`. **No** un `assertEquals` de archivos
      completos (plan §Estrategia de test 1).

- [X] **T013** Test de la excepción nombrada (FR-022): los 2 comprobantes de MercadoLibre deben
      emitirse con `Cantidad de alícuotas = 1`, **afirmando la diferencia contra el fixture**.
      *Fija la corrección como comportamiento buscado, no como tolerancia genérica.*

## Fase 4 — Paquete ZIP (US1)

- [X] **T014** `IvaDigitalPaquete`: orquesta los 4 writers, arma el ZIP con los nombres de FR-002/FR-003
      (mes en castellano, `Alicuotas` sin acento). Escritura en streaming a temporal (research §7).

- [X] **T015** Test del paquete: el ZIP contiene exactamente 4 entradas con los nombres exactos.

- [X] **T016** Test de período vacío (FR-005): ZIP válido con 4 archivos de 0 bytes, sin excepción.

- [X] **T017** Test de determinismo (SC-005): generar dos veces el mismo período da bytes idénticos.

## Fase 5 — Endpoint y UI (US1, US3)

- [X] **T018** Ruta y método `ivaDigital()` en `InformeContadorController`, delgado como los existentes
      (toda la lógica en el servicio). Devuelve la descarga del ZIP.

- [X] **T019** Test HTTP del endpoint: descarga correcta con mes+año; rechazo cuando falta el mes
      (FR-004); respeta los permisos del módulo de Informes.

- [X] **T020** En la vista de la 077, agregar la acción de descarga del IVA Digital, **habilitada sólo
      con mes elegido** (US3). Notificación por toast según la regla 3 de `CLAUDE.md`; sin recarga de
      página.

- [X] **T023** Test de completitud e integridad del período (SC-003, SC-004): sobre un período
      conocido, verificar que la cantidad de comprobantes emitidos coincide con la del informe en
      pantalla de la spec 077 — **ninguno se pierde ni se duplica** — y que toda fila de alícuota
      tiene comprobante y todo comprobante declara el conteo real.
      *Es la comparación que garantiza que el archivo y la pantalla cuentan lo mismo.*

## Fase 6 — Verificación final

- [X] **T021** Verificación manual en local **con MySQL** (no sólo SQLite): generar Agosto 2026 sobre
      la base de referencia y comparar contra el fixture con el helper de T002.
      *Memoria del proyecto: la suite verde en SQLite no garantiza el comportamiento en MySQL.*

- [X] **T022** Actualizar `docs/documentacion_principal_crm.md` (el informe del contador ahora entrega
      IVA Digital) y dejar registrado el defecto de origen detectado, para que no se "corrija" de nuevo
      en el futuro creyendo que es un bug propio.

---

## Orden de ejecución

```
T001 → T002/T003 → T004 → T005 → T006 → T007
     → T008/T009 → T010/T011 → T012 → T013
     → T014 → T015/T016/T017
     → T018 → T019 → T020
     → T023 → T021 → T022
```

**MVP**: hasta T017 ya existe la capacidad completa de generar el paquete correcto; T018–T020 lo
exponen al usuario.

## Trazabilidad

| Requisito | Tareas |
|---|---|
| FR-001, FR-002, FR-003 | T014, T015 |
| FR-004 | T019, T020 |
| FR-005 | T016 |
| FR-006, FR-007, FR-020 | T008–T011 (consumen las queries de la 077 sin modificarlas) |
| FR-008, FR-011 | T004, T005, T012 |
| FR-009, FR-010 | T005, T012 (CRLF en la última línea; ausencia de encabezado) |
| FR-012, FR-013, FR-014 | T004, T005 |
| FR-015 | T010, T011, T012 |
| FR-016 | T008, T009, T010, T011, T013 |
| FR-017, FR-018 / SC-004 | T003, T011, T012, T023 |
| SC-003 | T023 |
| SC-006 | T019, T020 |
| FR-019 / SC-005 | T017 |
| FR-021 | T002, T012 |
| FR-022 | T013 |
| FR-023 | T005 |
| SC-001, SC-002 | T012, T013, T021 |
