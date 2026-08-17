# Tasks: Módulo Informes — Tanda 3 (Rankings, Arma tu Informe)

**Input**: Design documents from `/specs/069-informes-rankings-pivot/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/endpoints.md](./contracts/endpoints.md),
[quickstart.md](./quickstart.md)

**Tests**: obligatorios para el dataset, sus medidas y las vistas guardadas (dinero + escritura
nueva → principios III/IV de la constitución). El drag & drop y el render en sí, al ser de
PivotTable.js, se verifican con `quickstart.md`, no con tests unitarios.

**Organización**: por user story, en orden de prioridad. US1 solo ya es un MVP entregable.

---

## Phase 1: Setup

- [X] T001 Vendorizar PivotTable.js en `public/vendor/pivottable/` (`pivot.min.js`, `pivot.min.css`, `jquery-ui.min.js` como dependencia del drag & drop) — **sólo el core y el renderer Table**, sin los paquetes de heatmap/plotly (research R1)
- [X] T002 [P] Registrar en `config/dz.php` el pagelevel de assets de pivot (`pivottable`, reutilizable por `informe-ventas` e `informe-compras`) siguiendo el patrón ya usado para `informe-gastos`
- [X] T003 [P] Registrar `resources/js/informes-pivot.js` en el array `input` de `vite.config.js`

## Phase 2: Foundational (bloquea todas las user stories)

- [X] T004 Crear la migración `database/migrations/2026_08_16_xxxxxx_create_informes_vistas_table.php` (`informe` enum, `descripcion`, `config` json, `creado_por_id` nullable FK a `users`, timestamps, sin soft delete — data-model.md §Entidad nueva)
- [X] T005 Crear el modelo `app/Models/InformeVista.php` con el cast de `config` a array y el scope `porInforme(string $informe)`
- [X] T006 Crear `app/Services/Informes/DimensionesPivot.php` con el catálogo de dimensiones por informe (research R9): claves, rótulos y a qué columna del dataset apunta cada una
- [X] T007 Declarar las 12 rutas de API de `contracts/endpoints.md` en `routes/web.php` dentro del grupo `middleware('permiso:informes.ver')` existente, con los nombres `informes.ventas.pivot.*` e `informes.compras.pivot.*`
- [X] T007b Declarar las rutas de **página** para entrada directa por URL (research R6): `informes.ventas.ranking.show` (`/informes/ventas/ranking/{dimension}`), `informes.ventas.vista.show` (`/informes/ventas/vista/{vista}`) y sus pares en Compras, resueltas por el `index()` existente de cada controlador con un parámetro de pestaña inicial — sin esto, compartir el enlace de un ranking da 404 (hallazgo de `/speckit-analyze`)
- [X] T008 [P] Crear el test `tests/Feature/Informes/InformesVistasTest.php`: alta, listado filtrado por informe, validación de `accion` incompatible con `dato`, descripción vacía rechazada, descripción duplicada aceptada con aviso, borrado, que una vista de Ventas no aparece en el listado de Compras (data-model invariantes 6-8), y que una vista creada por un usuario aparece en el listado de otro usuario con el mismo permiso (FR-034: compartidas, no privadas)
- [X] T009 [P] Extender `tests/Feature/Informes/InformesAccesoTest.php` para cubrir las 12 rutas nuevas sin el permiso `informes.ver`, incluidas alta y borrado de vistas guardadas

**Checkpoint**: tabla y modelo de vistas listos y testeados, rutas resueltas, catálogo de dimensiones
disponible. Recién acá arrancan las historias.

---

## Phase 3: User Story 1 — Ver un ranking predefinido (P1) 🎯 MVP

**Goal**: pestaña Rankings en Ventas y en Compras, con sus vistas predefinidas renderizando un cruce
correcto contra los mismos filtros del informe.

**Independent Test**: cargar ventas de varios clientes en un período, entrar a Rankings → Clientes y
verificar que el cruce coincide con los datos cargados, cambiando el rango sin recargar.

- [X] T010 [US1] Ampliar la proyección de `app/Services/Informes/VentasInformeQuery.php` con las columnas nuevas de dimensión (`categoria`, `vendedor`, `tipo_producto`, `proveedor`, `descuento_pct`, `etiquetas`) y de medida (`total_venta` con impuestos, `comprobante_id`), **agregadas al final** de la proyección existente sin reordenar ni tocar las columnas ya usadas por el detalle de la tanda 2 (research R5); cada columna de dimensión nueva lleva su rótulo de fallback ("Sin categoría", "Sin vendedor", "Sin tipo de producto", "Sin proveedor") resuelto en SQL, no en el cliente (FR-018)
- [X] T011 [US1] Correr `php artisan test --filter=Informes` completo tras T010 y dejarlo en verde antes de seguir: es la primera modificación de una query en producción de las tandas 1/2
- [X] T012 [US1] Ampliar de forma análoga la proyección de `app/Services/Informes/ComprasInformeQuery.php` (sin `vendedor` — research R9) y repetir T011 para Compras
- [X] T013 [US1] Crear `app/Services/Informes/VentasPivotDataset.php`: reutiliza `VentasInformeQuery::detalle()` con sus mismos filtros, proyecta sólo dimensión+medida, aplica el tope de 50.000 filas (data-model §Dataset, research R2)
- [X] T014 [US1] Crear `app/Services/Informes/ComprasPivotDataset.php`, análogo sobre `ComprasInformeQuery`
- [X] T015 [US1] Crear `tests/Feature/Informes/VentasPivotDatasetTest.php`: el total agregado sin dimensiones concilia con `VentasInformeQuery::kpis()` (invariante 1), `cantidad_ventas` cuenta comprobantes distintos y no líneas (invariante 3), signos de NC/ND (invariante 4), respeto del borrado lógico (invariante 5), tope de 50.000 filas, y una venta sin categoría/vendedor cae en su rótulo de fallback y no se descarta (FR-018)
- [X] T016 [US1] Crear `tests/Feature/Informes/ComprasPivotDatasetTest.php`, análogo sobre Compras (invariante 2)
- [X] T017 [US1] Agregar a `InformeVentasController` la acción `pivotDataset()` (`GET .../pivot/dataset`) que aplica los mismos filtros que `data()`/`stats()` y devuelve el dataset proyectado con `dimensiones`/`datos` (contracts/endpoints.md)
- [X] T018 [US1] Agregar a `InformeComprasController` la acción `pivotDataset()` análoga
- [X] T019 [US1] Crear `resources/views/informes/partials/pivot.blade.php`: contenedor del pivot + selectores "Dato"/"Accion" (sin "Mostrar Como") + botón "Exportar Excel", parametrizable por informe; incluye el mensaje de vacío cuando el dataset no trae filas, en vez de dejar que PivotTable.js renderice una tabla sin datos (SC-007)
- [X] T020 [US1] Agregar la barra de pestañas (detalle / Rankings / Arma tu Informe) a `resources/views/informes/ventas/index.blade.php` y a `resources/views/informes/compras/index.blade.php`, incluyendo el desplegable de Rankings con sus 5 y 4 vistas respectivamente (FR-001, FR-003)
- [X] T021 [US1] Crear `resources/js/informes-pivot.js`: wrapper de PivotTable.js que registra **únicamente** `renderers: { Table: $.pivotUtilities.renderers.Table }`, mapea las 4 medidas y los 7 agregadores a las claves de `data-model.md`, y expone una función de inicialización parametrizable por dimensión inicial (research R1, R7)
- [X] T022 [US1] Cablear en `resources/js/informe-ventas.js` y `resources/js/informe-compras.js` el cambio de pestaña sin recarga vía `history.pushState` (research R6) y la carga del dataset al entrar a un ranking

**Checkpoint**: los rankings predefinidos de Ventas y Compras se pueden usar y demostrar por sí
solos.

---

## Phase 4: User Story 2 — Reacomodar y exportar un ranking (P2)

**Goal**: drag & drop entre ejes, dependencia Accion↔Dato, embudo de exclusión, tope de columnas y
exportación fiel al cruce visible.

**Independent Test**: partir de un ranking, mover una dimensión, cambiar Dato y Acción, y verificar
que la tabla se rearma y que el Excel descargado coincide con lo que está en pantalla.

- [X] T023 [US2] Extender `resources/js/informes-pivot.js`: recorte dinámico de "Accion" cuando "Dato" es un conteo, con caída automática a "Suma" si la Acción vigente deja de aplicar (FR-014, edge case de la spec)
- [X] T024 [US2] Extender `resources/js/informes-pivot.js`: conteo de columnas resultantes antes de renderizar; si supera 1.000, mostrar el aviso por Toastr en vez de dibujar (FR-019b, research R8)
- [X] T025 [US2] Verificar y, si hace falta, ajustar la configuración de PivotTable.js para que el embudo de exclusión por columna y el ordenamiento por encabezado (de fábrica en la librería) queden habilitados y estilados con el template (FR-015)
- [X] T026 [US2] Crear `app/Exports/Informes/PivotExport.php` (`WithMultipleSheets`, reutilizando `HojaInforme`): hoja legible con encabezados de fila/columna anidados + hoja plana de una fila por combinación, a partir de la matriz recibida por POST (contracts/endpoints.md)
- [X] T027 [US2] Agregar a `InformeVentasController` y a `InformeComprasController` la acción `pivotExportar()` (`POST .../pivot/exportar`) que valida el body y descarga el Excel vía `PivotExport`
- [X] T028 [US2] Extender `resources/js/informes-pivot.js`: función que lee el estado actual del pivot (encabezados, celdas, totales tal como PivotTable.js los tiene armados) y arma el body del POST de exportación (research R3)
- [X] T029 [US2] Crear `tests/Feature/Informes/PivotExportTest.php`: el Excel generado a partir de una matriz de prueba reproduce exactamente esa matriz (encabezados, celdas, totales) en la hoja legible, y la hoja plana tiene una fila por combinación
- [X] T030 [P] [US2] Confirmar en `resources/js/informes-pivot.js` que el selector "Mostrar Como" no se instancia ni se renderiza en ningún caso (FR-020/FR-021), con un comentario que documente que es la razón de negocio y no un descuido

**Checkpoint**: los rankings quedan completos y contrastables contra el relevamiento (salvo los 7
modos de render, deliberadamente ausentes).

---

## Phase 5: User Story 3 — Armar y guardar un informe a medida (P3)

**Goal**: builder libre con las 13 dimensiones, guardado como pestaña persistente, aislado por
informe.

**Independent Test**: abrir "Arma tu Informe", arrastrar dos dimensiones, guardarlo con un nombre,
recargar la pantalla y verificar que la pestaña sigue ahí con el mismo cruce.

- [X] T031 [US3] Agregar a `resources/views/informes/partials/pivot.blade.php` el modo "builder": zona de pool con las fichas de `DimensionesPivot` sin asignar, sin dimensión inicial fija (FR-030)
- [X] T032 [US3] Agregar el modal "Guardar Informe" (Bootstrap del template, campo único "Descripción", botones Cancelar/Guardar) a la vista de cada informe, enviado por AJAX (FR-031, FR-043)
- [X] T033 [US3] Crear `app/Http/Controllers/Informes/InformesVistasController.php` con `index()`, `store(Request)` (valida descripción no vacía, `accion` compatible con `dato`, y devuelve `aviso` si la descripción está duplicada en ese informe) y `destroy(InformeVista)` (404 si no pertenece a ese informe); el informe (`ventas`/`compras`) se resuelve por **dos registros de ruta distintos apuntando al mismo método** con el valor fijado en cada registro (`->defaults('informe', 'ventas')` / `'compras'`), no por un segmento `{informe}` de la URL — así el contrato de rutas separadas por informe queda satisfecho sin un controlador por informe (aclara G2 de `/speckit-analyze`)
- [X] T034 [US3] Extender `resources/js/informe-ventas.js` y `resources/js/informe-compras.js`: al guardar, la pestaña "Crear Informe" pasa a rotularse con la descripción y queda fijada en la barra (FR-032); listar las vistas guardadas de ese informe al cargar la pantalla
- [X] T035 [US3] Extender `resources/js/informes-pivot.js`: al abrir una vista guardada, reconstruir el pivot desde su `config` (filas, columnas, dato, accion, exclusiones) contra el dataset vigente (FR-033)
- [X] T036 [US3] Agregar el botón de eliminar en cada pestaña de vista guardada, con confirmación y sin afectar datos de negocio (FR-036)
- [ ] T037 [US3] Ampliar `tests/Feature/Informes/InformesVistasTest.php` con la cobertura de extremo a extremo: guardar vía el endpoint real, listar, y confirmar que reconstruir con datos vigentes (tras editar una categoría usada en la config) sigue funcionando (edge case de la spec)

**Checkpoint**: las tres pestañas del módulo funcionan de punta a punta en Ventas y en Compras.

---

## Phase 6: Polish & Cross-Cutting

- [X] T038 [P] Recorrer `quickstart.md` completo en el navegador y contrastar contra las capturas 05-15 del relevamiento (drag & drop, embudo, ordenamiento, modal de guardado, pestañas persistentes) — regla de oro de CLAUDE.md
- [X] T039 [P] Confirmar sobre las dos pantallas que ningún lugar ofrece mapa de calor, gráfico de líneas/barras ni histograma (SC-008), y que "Mostrar Como" no aparece cuando queda con una sola opción
- [X] T040 [P] Verificar el objetivo de rendimiento de SC-003 (~5.000 comprobantes en el rango) sobre `pivot/dataset` en los dos informes, y que el filtro de rango entra dentro de cada rama del `UNION ALL` heredado
- [X] T041 Correr `php artisan test --filter=Informes` completo (incluidas las tandas 1 y 2) y dejar todo en verde antes de dar la feature por cerrada

---

## Dependencias

```
Setup (T001–T003)
   └─> Foundational (T004–T009, T007b)   ← bloquea todo
          └─> US1 (T010–T022)     ← MVP
                 ├─> US2 (T023–T030)   (necesita el dataset y la pestaña de US1)
                 └─> US3 (T031–T037)   (necesita el wrapper de pivot de US1)
                        └─> Polish (T038–T041)
```

- **US2 y US3 dependen de US1** (dataset, controlador y wrapper de pivot ya construidos), pero son
  independientes entre sí: se pueden desarrollar en paralelo una vez cerrado US1.
- T011 es un checkpoint de seguridad, no una tarea de negocio: bloquea a T012 en el sentido de que
  hay que confirmar la suite en verde antes de replicar el mismo cambio en Compras.

## Oportunidades de paralelismo

- T002 y T003 en paralelo con T001.
- T008 y T009 en paralelo entre sí.
- T013/T015 (Ventas) en paralelo con T014/T016 (Compras): archivos y tablas distintos.
- T017 en paralelo con T018.
- T030 en paralelo con el resto de US2: es una verificación, no bloquea nada.
- T038, T039 y T040 en paralelo al cierre.

## Estrategia de implementación

1. **MVP**: Setup + Foundational + US1 → Rankings predefinidos usables y demostrables en los dos
   informes.
2. **Incremento 2**: US2 → reacomodo libre, tope de columnas y export fiel.
3. **Incremento 3**: US3 → Arma tu Informe con guardado persistente.
4. **Cierre**: Polish, con T041 como condición de salida obligatoria.

## Nota de documentación (constitución I)

Antes de dar la spec por lista para implementar hay que actualizar `docs/documentacion_principal_crm.md`
§6 (registrar la tanda 3 como implementada, con su alcance acotado ya anotado el 15/08) y
`docs/modelo_datos.md` (agregar `informes_vistas` y el mapeo de dimensiones de Compras sin
`vendedor`, research R9). Si durante la implementación aparece una regla nueva, se actualiza **en el
mismo cambio**.
