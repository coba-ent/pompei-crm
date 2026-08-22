# Tasks: Robustez del importador de Productos (stock concurrente y auditoría de precios)

**Feature**: 074-robustez-importacion-productos | **Fecha**: 2026-08-22
**Input**: [spec.md](./spec.md), [plan.md](./plan.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: **obligatorios**. No es una opción en esta feature: la Constitución (Principio IV) exige tests
para toda lógica que involucre movimientos de stock y precios, y esta feature toca exactamente esas dos
áreas.

**Organización**: por historia de usuario, en orden de prioridad. US1 (auditoría de precios) es el MVP.

---

## Formato de tarea

`- [ ] [ID] [P?] [Story?] Descripción con ruta de archivo`

- **[P]**: paralelizable (archivo distinto, sin dependencias pendientes)
- **[US1]/[US2]**: historia a la que pertenece

---

## Phase 1: Setup

- [X] T001 Verificar que la suite arranca en verde antes de tocar nada: `php artisan test` desde la raíz del proyecto, y anotar el resultado como línea de base
- [X] T002 [P] Medir el tiempo de una tanda de importación de ~1.000 filas de Productos **antes** del cambio, para tener el número contra el cual comparar en T037 (SC-005); anotarlo en `specs/074-robustez-importacion-productos/quickstart.md` §D

---

## Phase 2: Foundational (bloquea a ambas historias)

**Propósito**: red de no-regresión del importador. Ambas historias modifican `ImportadorFilas`, así que
esta cobertura tiene que existir **antes** de tocarlo.

- [X] T003 Crear test de no-regresión del importador de Productos en `tests/Feature/ImportacionProductosStockTest.php`: cubre alta por importación, actualización por Id, fila inválida que no aborta el archivo, y advertencia de "Stock Total no coincide" — todo con el comportamiento **actual**, para que cualquier regresión de US1/US2 salte (FR-015, FR-016)

**Checkpoint**: T003 en verde ⇒ se puede empezar cualquiera de las dos historias.

---

## Phase 3: User Story 1 — Auditoría de cambios de precio (P1) 🎯 MVP

**Objetivo**: que todo cambio de precio por lista quede registrado con su valor anterior, su valor nuevo
y su origen, consultable desde la pantalla de Auditoría.

**Test independiente**: cambiar el precio de un producto por cada uno de los cuatro caminos y verificar
que aparece el evento correspondiente. No depende de US2.

### Esquema y contexto de origen

- [X] T004a [US1] Crear migración `database/migrations/2026_08_XX_XXXXXX_agregar_precio_producto_a_tipo_operacion.php` que agregue `precio_producto` al enum `logs_auditoria.tipo_operacion`. **El `DB::statement("ALTER TABLE ... MODIFY ...")` va obligatoriamente dentro de un guard `if (DB::getDriverName() === 'mysql')`**: es sintaxis exclusiva de MySQL y la suite corre en SQLite (`phpunit.xml` fija `DB_CONNECTION=sqlite`, `:memory:`), donde ese statement revienta y **tira abajo la suite entera**. Patrón ya establecido en el proyecto: ver `database/migrations/2026_08_09_060006_add_tiendanube_to_origen_enum_ventas_table.php`. Repetir el enum completo en `up()` y en `down()`; en `down()`, contemplar las filas que ya usen el valor nuevo en vez de fallar con un error críptico de MySQL — ver [data-model.md §1](./data-model.md)
- [X] T004b [US1] Agregar `'precio_producto'` a la lista de valores del `$table->enum('tipo_operacion', [...])` en la migración **original** `database/migrations/2026_08_07_155244_create_logs_auditoria_table.php`. **Sin esto los tests fallan aunque T004a esté bien**: en SQLite, `enum()` se materializa como `varchar` + `CHECK (tipo_operacion IN (...))`, y como el guard de T004a saltea el `ALTER`, la base de tests nunca aprende el valor nuevo — cualquier inserción de un evento de precio viola el CHECK. Es el mismo patrón de dos partes que ya usó el proyecto para `ventas.origen` (la migración original `2026_08_02_060005_add_origen_to_ventas_table.php` fue editada retroactivamente para incluir `tiendanube`, además de la migración de `ALTER` para MySQL)
- [X] T005 [P] [US1] Crear `app/Support/OrigenCambioPrecio.php` con las constantes `IMPORTACION`, `MANUAL`, `EDICION_MASIVA`, `COPIA`, `DESCONOCIDO` y los métodos `durante(string $origen, callable $fn): mixed` y `actual(): string`. `durante()` debe restaurar el origen previo en un `finally` (incluso si el callable lanza), y `actual()` debe devolver `DESCONOCIDO` si nadie lo declaró — ver [contracts/auditoria-precio-producto.md §3](./contracts/auditoria-precio-producto.md)
- [X] T006 [P] [US1] Agregar `'precio_producto' => 'Precio de producto'` a `AuditoriaController::LABELS_OPERACION` en `app/Http/Controllers/AuditoriaController.php`. Esa constante alimenta a la vez el `<select>` del filtro y la columna "Operación", así que **no hay que tocar la vista** (FR-011)

### Escritura en lote de auditoría

- [X] T007 [US1] Agregar a `app/Services/AuditoriaService.php` el modo buffer: `iniciarBuffer()`, `vaciarBuffer()` y el enrutado interno de `registrarEvento()` hacia el buffer cuando está activo. El vaciado persiste con un `INSERT` múltiple, se dispara automáticamente cada 200 eventos, y hereda el contrato de "nunca lanza excepción" (loguea y descarta) — ver [contracts/auditoria-precio-producto.md §6](./contracts/auditoria-precio-producto.md)

### Captura del evento

- [X] T008 [US1] Extender `app/Observers/PrecioProductoObserver.php` con una rama de auditoría en `saved()`, **independiente** de las ramas de Mercado Libre y Tiendanube (un fallo en una no debe impedir las otras). Mapeo: `wasRecentlyCreated` ⇒ `creo`; `wasChanged('precio')` ⇒ `modifico` con `getOriginal('precio')` como anterior; sin cambio ⇒ **no registra nada**. La comparación de "cambió" va sobre el valor normalizado a 2 decimales (FR-010) — ver [contracts §2](./contracts/auditoria-precio-producto.md)
- [X] T009 [US1] Agregar el hook `deleted()` a `app/Observers/PrecioProductoObserver.php` para registrar la eliminación de un precio (`tipo_accion = elimino`, `total = null`, precio anterior = el que tenía)
- [X] T010 [US1] Implementar en el observer el armado del evento: `tipo_operacion = precio_producto`, `entidad_tipo = App\Models\Producto::class`, `entidad_id = producto_id`, `total` = precio nuevo, y `detalle` con la forma `"{Producto} — {Lista}: {anterior} → {nuevo} ({origen})"` usando `sin precio` cuando falta un extremo. El truncado a 255 caracteres debe recortar **el nombre del producto**, nunca los importes ni el rótulo de origen — ver [data-model.md §2](./data-model.md)

### Declaración del origen en cada punto de entrada

- [X] T011 [P] [US1] Declarar `OrigenCambioPrecio::IMPORTACION` en `app/Services/Import/ImportadorFilas.php`, envolviendo el procesamiento de filas de la tanda; activar ahí también el buffer de auditoría (`iniciarBuffer()`) y vaciarlo al terminar la tanda (`vaciarBuffer()`), en un `finally` para que no quede activo si algo falla
- [X] T012 [P] [US1] Declarar `OrigenCambioPrecio::MANUAL` en `ProductoController::store()` y `update()` alrededor de `sincronizarPrecios()`, en `app/Http/Controllers/ProductoController.php`
- [X] T013 [P] [US1] Declarar `OrigenCambioPrecio::EDICION_MASIVA` en `ProductoController::accionAjustarPrecios()` (`app/Http/Controllers/ProductoController.php:542`)
- [X] T014 [P] [US1] Declarar `OrigenCambioPrecio::COPIA` en `ProductoController::copia()` (`app/Http/Controllers/ProductoController.php:419`)

### Corrección del borrado de precios

- [X] T015 [US1] Cambiar `ProductoController::sincronizarPrecios()` (`app/Http/Controllers/ProductoController.php:780`) de `precios()->whereNotIn(...)->delete()` (mass delete de query builder, **no dispara eventos**) a borrado por modelo, para que el hook `deleted()` se dispare por cada precio quitado — ver [research.md D5](./research.md)
- [X] T016 [US1] Verificar el efecto secundario de T015 sobre las integraciones: al pasar a borrado por modelo, esos borrados ahora **sí** entran al observer y por lo tanto a las ramas de Mercado Libre/Tiendanube, cosa que hoy no ocurre. Confirmar que ambas ramas toleran un precio borrado sin romper (FR-017); si no lo toleran, agregar la guarda correspondiente en `app/Observers/PrecioProductoObserver.php`

### Tests de US1

- [X] T017 [P] [US1] Test en `tests/Feature/AuditoriaPrecioProductoTest.php`: cambio de precio por **importación** genera evento `modifico` con anterior, nuevo y rótulo `importación` (CV-1)
- [X] T018 [P] [US1] Test: cambio por **edición manual** desde la ficha genera evento con rótulo `edición manual` (CV-2)
- [X] T019 [P] [US1] Test: **edición masiva** de precios genera un evento por cada precio efectivamente modificado, con rótulo `edición masiva` (CV-3)
- [X] T020 [P] [US1] Test: quitar una lista de precios al guardar el producto genera evento `elimino` con el valor que tenía (CV-4)
- [X] T021 [P] [US1] Test: guardar el **mismo** precio no genera ningún evento, incluyendo el caso `100` vs `100.00` (CV-5, FR-010, SC-004)
- [X] T022 [P] [US1] Test: asignar precio a una lista que no tenía genera evento `creo` sin precio anterior (CV-6)
- [X] T023 [P] [US1] Test: con la escritura de auditoría forzada a fallar, la importación y el guardado del producto terminan bien y el precio queda correctamente guardado (CV-7, FR-012). Cubrir **los dos caminos**: el evento suelto (edición manual) y el vaciado del buffer (importación) — son dos puntos de fallo distintos y el segundo puede arrastrar hasta 200 eventos
- [X] T024 [P] [US1] Test: un cambio de precio en la lista configurada para Mercado Libre/Tiendanube sigue disparando la sincronización (CV-9, FR-017)
- [X] T025 [P] [US1] Test: un cambio de precio por un camino que **no declara** origen queda auditado con el rótulo de origen no identificado (comportamiento por defecto seguro, research D4)

**Checkpoint**: US1 completa e independientemente entregable. Cubre FR-006 a FR-014, SC-001, SC-002, SC-004, SC-007.

---

## Phase 4: User Story 2 — Stock atómico en la importación (P2)

**Objetivo**: que fijar el stock a un valor absoluto no pise operaciones concurrentes.

**Test independiente**: simular un movimiento concurrente entre la lectura y la escritura del ajuste y
verificar que la reconciliación se mantiene. No depende de US1.

- [X] T026 [US2] Agregar `fijar(Producto $producto, ?ProductoVariante $variante, Deposito $deposito, float $cantidadDeseada, ?string $descripcion = null, ?User $usuario = null): float` a `app/Services/Stock/StockService.php`, resolviendo lectura + cálculo del delta + escritura del `MovimientoStock` dentro de una única `DB::transaction()` con `lockForUpdate()` sobre la fila de `stocks`. Si no hay diferencia, no escribe nada y devuelve la cantidad actual — contrato completo en [contracts/stock-service-fijar.md](./contracts/stock-service-fijar.md)
- [X] T027 [US2] Documentar en el PHPDoc de `fijar()` la diferencia con `ajustar()` (delta conocido vs valor absoluto deseado) y la regla para el futuro: cualquier llamador que combine `disponibilidad()` + `ajustar()` para llegar a un valor absoluto tiene el mismo bug y debe migrar a `fijar()`
- [X] T028 [US2] Reemplazar en `ImportadorFilas::actualizarProducto()` (`app/Services/Import/ImportadorFilas.php:778-795`) el bloque `disponibilidad()` + cálculo de diferencia + `ajustar()` por una única llamada a `fijar(..., $cantidadDeseada, 'Ajuste (importación)', $usuario)`. El `continue` por diferencia cero deja de ser responsabilidad del importador (lo garantiza el contrato)
- [X] T029 [US2] Confirmar que `ImportadorFilas::crearProducto()` (línea 737) **no cambia**: en el alta el producto es nuevo, el stock parte de cero, no hay lectura previa y por lo tanto no hay carrera. Sigue usando `ajustar()` con `'Registro inicial (importación)'` (FR-003)

### Tests de US2

- [X] T030 [P] [US2] Test en `tests/Feature/StockFijarConcurrenciaTest.php`: fijar 50 sobre un stock de 10 deja la cantidad en 50 y crea un movimiento `+40` (CV-1)
- [X] T031 [P] [US2] Test: fijar 10 sobre un stock de 10 **no crea ningún movimiento** (CV-2, FR-004)
- [X] T032 [P] [US2] Test: fijar 5 sobre un stock de 10 crea un movimiento `-5` (CV-3)
- [X] T033 [US2] Test de concurrencia (CV-4): con una operación que mueve el mismo stock en el medio, verificar que **ambos movimientos existen** y que `stocks.cantidad` reconcilia con la suma del histórico. **Confirmado que NO es verificable con la configuración actual de la suite**: `phpunit.xml` fija `DB_CONNECTION=sqlite` / `:memory:`, y en SQLite `lockForUpdate()` es un no-op — el test pasaría en verde **sin probar absolutamente nada**, que es el peor resultado posible para el único test que cubre el bug que motivó US2. Implementarlo con un guard explícito que lo marque `skipped` cuando el driver no es MySQL (mensaje: "requiere MySQL: lockForUpdate() es no-op en SQLite"), **nunca** dejarlo pasar en falso verde. La verificación real de SC-003 queda entonces en la validación manual de [quickstart.md §C3](./quickstart.md), que es **obligatoria** para esta historia — anotar su resultado antes de dar US2 por cerrada
- [X] T034 [P] [US2] Test: el movimiento generado conserva `tipo = 'ajuste'`, la descripción exacta `'Ajuste (importación)'` y el `usuario_id` recibido (CV-5, FR-003)
- [X] T035 [P] [US2] Test: un producto que no controla stock (servicio) sigue siendo ignorado por el circuito (FR-005)

**Checkpoint**: US2 completa e independientemente entregable. Cubre FR-001 a FR-005, SC-003.

---

## Phase 5: Polish y verificación transversal

- [X] T036 Ejecutar la suite completa (`php artisan test`) y confirmar que no hay regresiones respecto de la línea de base de T001
- [X] T037 Medir una tanda de ~1.000 filas con todas las columnas de lista mapeadas y precios efectivamente modificados (peor caso de auditoría) y comparar contra la medición de T002: el overhead debe ser marginal y la tanda debe completar sin corte por tiempo (SC-005). Verificar además que `logs_auditoria` crece **a saltos de ~200 filas**, no de a una — evidencia de que el lote realmente se agrupó
- [X] T038 **HECHO 22/08/2026** — resultados completos en [quickstart.md §F](./quickstart.md). Se cubrieron §B y §C enteros contra MySQL real (base `contagram_p074`), incluyendo **§C3, la concurrencia**: `disponibilidad()` atraviesa un lock retenido en 0 s y `fijar()` espera 4,36 s, con el stock quedando en el valor absoluto pedido y reconciliando con el histórico. Además se ejercitaron los cuatro orígenes de precio por navegador (importación 27 / edición manual 11 / edición masiva 1 / copia 1 / sin identificar 0). Nota original: HECHO: todo el camino de importación de §B (cambio de precio, sin cambio, alta de precio, filas fallidas, advertencia de Stock Total) y §C1/§C2 de stock, con la consulta de reconciliación `foto = suma_historico` en verde. Además, reimportar una exportación de 9.186 filas sin modificar generó **0 eventos y 0 movimientos**. FALTA: (a) los otros tres orígenes de precio en navegador —edición manual desde la ficha, edición masiva y copia de producto— que hoy sólo están cubiertos por tests en SQLite; (b) **§C3, la verificación de concurrencia real en MySQL (SC-003)**, que es la única prueba del bug que motivó US2 y que el test automatizado saltea por diseño. Recorrer la validación manual de [quickstart.md](./quickstart.md) §B (B1-B8) y §C (C1-C3), incluyendo la consulta SQL de reconciliación `foto = suma_historico`
- [X] T039 [P] **HECHO 22/08/2026** (MySQL real, base `contagram_p074`, Chrome): el `<select>` de filtro lista `precio_producto:Precio de producto` y la tabla muestra la operación con su detalle `anterior → nuevo (importación)`. Verificar en el navegador que la pantalla de Auditoría muestra la operación "Precio de producto" y que el filtro por operación la incluye (FR-011). Recordar que una suite verde en SQLite no garantiza el comportamiento en MySQL — esta verificación va sí o sí en el navegador
- [X] T040 [P] **HECHO 22/08/2026**: subida → vista previa → mapeo → resumen, idénticos. Resumen mostró `12 importados / 3 no importadas (con motivo por fila) / 1 advertencia`. Confirmar que la UI del asistente de importación no cambió: mismos pasos, mismo mapeo, misma vista previa, mismo resumen de importados/fallidos/advertencias (FR-015)
- [X] T041 [P] **HECHO 22/08/2026**: anotada la base de pruebas `contagram_p074`, el reseteo de contraseña del admin EN ESA COPIA, y el puerto 8123. Actualizar `CREDENCIALES_ACCESO.txt` si durante las pruebas manuales se creó, borró o cambió algún acceso

---

## Dependencias

```text
Phase 1 (Setup: T001-T002)
    ↓
Phase 2 (Foundational: T003)  ← bloquea a ambas historias
    ↓
    ├─→ Phase 3 (US1: T004-T025)  ─┐
    │                               ├─→ Phase 5 (Polish: T036-T041)
    └─→ Phase 4 (US2: T026-T035)  ─┘
```

**US1 y US2 son independientes entre sí**: tocan archivos distintos salvo `ImportadorFilas.php`
(T011 de US1 y T028 de US2). Si se trabajan en paralelo, coordinar ese archivo.

**Dentro de US1**:
- T004a **y T004b** (migración + enum en la migración original) antes de cualquier test que inserte un evento (T017+). Las dos: con una sola, o rompe MySQL o rompe SQLite
- T005 (contexto de origen) antes de T008 y de T011-T014
- T007 (buffer) antes de T011
- T008-T010 (observer) antes de todos los tests de US1
- T015 antes de T020 (el test del borrado) y antes de T016

**Dentro de US2**:
- T026 antes de T028 y de todos los tests
- T028 antes de T033

## Oportunidades de paralelización

- **Setup**: T002 en paralelo con T001
- **US1 arranque**: T005 y T006 en paralelo con T004a/T004b
- **US1 declaración de origen**: T011, T012, T013, T014 en paralelo entre sí (archivos/métodos distintos)
- **US1 tests**: T017 a T025 todos en paralelo una vez que T004-T015 están hechos
- **US2 tests**: T030, T031, T032, T034, T035 en paralelo (T033 aparte por requerir MySQL)
- **Polish**: T039, T040, T041 en paralelo

## Estrategia de implementación

**MVP = User Story 1** (auditoría de precios). Es la prioridad declarada por el usuario y el gap que se
manifiesta **siempre**, no sólo bajo concurrencia: hoy un aumento de precios mal aplicado es
irreversible. Entregable por sí solo, sin tocar nada de stock.

**Incremento 2 = User Story 2** (stock atómico). Defecto más grave técnicamente pero de manifestación
condicionada (requiere operación concurrente durante la importación). Entregable por sí solo.

**Orden sugerido**: Phase 1 → Phase 2 → Phase 3 (validar y, si se quiere, desplegar) → Phase 4 → Phase 5.

## Notas

- **Principio I ya cumplido**: `docs/modelo_datos.md` (§`logs_auditoria` y §`stocks`) y
  `docs/documentacion_principal_crm.md` (§2.4 Importar Datos y §Auditoría) fueron actualizados **antes**
  de generar estas tareas, incluyendo la excepción de FR-009a. No queda pendiente de documentación.
- **La suite corre en SQLite en memoria** (`phpunit.xml` líneas 25-26). Eso tiene dos consecuencias
  concretas ya incorporadas arriba, ambas detectadas en `/speckit-analyze`:
  1. **T004a/T004b**: la migración del enum necesita las dos partes. Con sólo el `ALTER` se cae la suite;
     con sólo el guard, los tests no conocen el valor nuevo y violan el `CHECK` de SQLite.
  2. **T033**: `lockForUpdate()` es no-op en SQLite, así que el test de concurrencia pasaría en verde sin
     probar nada. Va con `skip` explícito y la validación real es manual (quickstart §C3).
  Es la aplicación concreta de algo que el proyecto ya tiene registrado: una suite verde en SQLite no
  garantiza el comportamiento en MySQL.
- **T016 no es opcional**: T015 cambia el comportamiento de un camino que hoy no llega a las
  integraciones. Es el riesgo de regresión más real de la feature.
- **Requisitos sin tarea propia, cubiertos por construcción** (verificado en `/speckit-analyze`, no son
  huecos de cobertura):
  - **FR-013** (inmutabilidad del registro): se satisface por reutilizar `LogAuditoria`, que ya es
    append-only y no expone UPDATE ni DELETE. No requiere trabajo nuevo.
  - **FR-009a** (documentar la excepción de las escrituras que no pasan por el modelo): ya cumplido
    antes de generar estas tareas, en `docs/modelo_datos.md` §`logs_auditoria` y en
    `docs/documentacion_principal_crm.md` §Auditoría.
  - **FR-011** (filtrable en la pantalla): T006 alcanza, porque `AuditoriaController::filtros()` no
    valida `operacion` contra ninguna lista blanca — verificado en el código. No hay que tocar la vista
    ni agregar validación.
