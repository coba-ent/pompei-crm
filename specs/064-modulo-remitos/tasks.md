# Tasks: Módulo de Remitos (Ventas y Compras)

**Feature**: `064-modulo-remitos` | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

**Criterio que ordena todo**: el remito **no mueve nada**. No hay observers de recálculo, ni
transacciones sobre saldos, ni CAE. Es un CRUD con documento imprimible; la dificultad está en la
**fidelidad estructural** al informe con capturas, no en la lógica.

**Tests**: el caso negativo es obligatorio. Un módulo que se define por lo que **no** hace necesita el
test que verifique que efectivamente no lo hace (mismo criterio que la spec 063).

---

## Phase 1: Setup y datos

- [x] T001 Crear la migración `..._create_transportistas_table.php` (`id`, `nombre`, timestamps)
- [x] T002 Crear la migración `..._create_remito_items_table.php` con `remito_id` (cascade on delete), `producto_id` (nullable), `codigo`, `descripcion`, `observacion`, `cantidad` decimal(14,3)
- [x] T003 Crear la migración `..._add_campos_a_remitos.php` con `transportista_id` (nullable), `domicilio_entrega`, `nota`, `monto_asegurado` (nullable), `tipo` (default `X`)
- [x] T004 Crear la migración `..._hacer_venta_id_nullable_en_remitos.php` — **corrige el bug que hace imposible crear un remito de Compra** (research §R2). Sin esto US4 no funciona
- [x] T005 [P] Crear `app/Models/Transportista.php` con `remitos()`
- [x] T006 [P] Crear `app/Models/RemitoItem.php` con `remito()` y `producto()`
- [x] T007 Ampliar `app/Models/Remito.php`: `items()`, `transportista()`, accessor `totalBultos()` derivado de la suma de cantidades (no persistido), y casts de los campos nuevos

---

## Phase 2: Foundational

**Bloquea todas las user stories.**

- [x] T008 Crear `app/Http/Controllers/RemitoController.php` con la resolución de origen (Venta o Compra) desde la ruta, para no duplicar la lógica entre `VentaController` y `CompraController`
- [x] T009 Registrar en `routes/web.php` las rutas de Ventas y Compras (`nuevo`, `store`, `editar`, `update`, `destroy`) más `remitos/{remito}/pdf`, calcando el patrón de página completa que estableció NC/ND (spec 059). **Cuidado con el orden**: los sub-grupos con prefijo literal van antes que las rutas con `{param}` de un solo segmento
- [x] T010 [P] Crear `StoreRemitoRequest` y `UpdateRemitoRequest`: al menos una línea, cantidades > 0 (FR-009), y validación de que la operación de origen existe
- [x] T011 [P] Crear `TransportistaController::store()` (alta al vuelo, sólo `nombre`, **reutiliza el existente si el nombre ya está** en vez de duplicar) y el endpoint de opciones para el buscador. **Sin pantalla de ABM**, por decisión de alcance (FR-021, FR-022, FR-023)

---

## Phase 3: User Story 1 — Emitir el remito (P1) 🎯 MVP

**Goal**: que el remito documente qué se entrega y pueda imprimirse para acompañar la mercadería.

**Independent Test**: crear un remito sobre una Venta con productos, verlo en la sección del detalle y
abrir su documento con las líneas correctas — **sin que cambie el stock**.

- [x] T012 [US1] Implementar `create()`: precarga cliente, domicilio del cliente, fecha de hoy y **todas las líneas de la Venta con sus cantidades originales** (FR-001, FR-002)
- [x] T013 [US1] Crear `resources/views/remitos/form.blade.php` — página completa, compartida entre alta y edición, calcando la captura 02: cliente no editable, domicilio editable, Emisión, Tipo, N° comprobante, Transportista con buscador, Nota, tabla Producto/**Observaciones**/Cantidad con tachito, Total Bultos y Monto Asegurado (FR-003, FR-004)
- [x] T014 [US1] Implementar `store()`: guarda cabecera + líneas, asigna número correlativo con `Remito::siguienteNumero()` (FR-004, FR-008)
- [x] T015 [US1] Crear `resources/js/remitos.js`: quitar líneas, **recalcular Total Bultos** al vuelo (FR-006), interruptor de Monto Asegurado que habilita el importe precargado (FR-007), Select2 en el transportista con buscador (FR-021) y modal de alta al vuelo por AJAX que pide sólo el nombre (FR-022, captura 04)
- [x] T015a [US1] Implementar la advertencia no bloqueante cuando una cantidad supera la de la operación de origen (FR-009a) — avisa, no impide guardar
- [x] T016 [US1] Agregar la sección **Remitos** en `resources/views/ventas/detalle.blade.php`, estructuralmente igual a la de Cobranzas: Id, Fecha, Transportista, Nota, Total Bultos, Comprobante (FR-013, captura 09). **Acá se corrige que los remitos ya cargados nunca se rendericen** (FR-026)
- [x] T017 [US1] Crear `resources/views/remitos/pdf.blade.php` calcando la captura 10, y `pdf()` con `Content-Disposition: inline` para el modal PDF compartido. **Sin precios, sin IVA, sin totales, sin Monto Asegurado** (FR-012, FR-014)
- [x] T017a [US1] Cubrir en el documento el caso de **cliente sin CUIT ni condición de IVA**: esos campos salen vacíos y la emisión no se bloquea (FR-015, verificado en la captura 10)
- [x] T018 [US1] Crear `tests/Feature/RemitoVentaTest.php`: se precargan las líneas; se guarda con su número; se ve en el detalle; el documento responde 200 y **no contiene importes de dinero**; un cliente sin datos fiscales no rompe el documento (FR-015)
- [x] T019 [US1] Crear `tests/Feature/RemitoNoMueveNadaTest.php` — **el test que más importa**: crear un remito no altera stock, movimientos de stock, tesorería, cobros ni el total de la Venta, **y no genera ningún comprobante fiscal ni llamada a ARCA** (FR-010, FR-011, SC-003)

**Checkpoint**: acá el remito ya sirve para despachar mercadería. Es el MVP.

---

## Phase 4: User Story 2 — Corregir o anular (P1)

- [x] T020 [US2] Implementar `edit()` y `update()` reusando el mismo formulario, **sin ningún campo bloqueado** (FR-016, captura 11)
- [x] T021 [US2] Implementar `destroy()` con **borrado real** (no soft delete — FR-017) y confirmación en modal + toast, sin recargar la página
- [x] T022 [US2] Agregar el botón **Eliminar** en el formulario de edición, junto a Cancelar y Guardar (captura 11)
- [x] T023 [US2] Implementar la cascada al eliminar la Venta/Compra: sus remitos se borran con ella (FR-018). Se hace en el `deleting` del modelo, **sin tocar** la lógica de reversión de cobros y stock que ya vive ahí
- [x] T024 [US2] Extender los tests: editar cambia los datos y el documento los refleja; eliminar lo saca de la sección; eliminar la Venta se lleva sus remitos; **ninguna de las tres operaciones mueve stock ni dinero**

---

## Phase 5: User Story 3 — Envíos parciales (P2)

- [x] T025 [US3] Verificar que el botón "Crear Remito" sigue disponible después del primero (FR-019)
- [x] T026 [US3] Verificar que el segundo remito precarga **las cantidades totales originales**, sin descontar lo ya remitido (FR-020) — es fidelidad al original, no un bug
- [x] T027 [US3] Agregar el test de dos remitos conviviendo sobre la misma Venta, cada uno con su número, cantidades y documento

---

## Phase 6: User Story 4 — Compras (P2)

**Depende de T004**: sin la corrección de nulabilidad, esto no puede funcionar.

- [x] T028 [US4] Implementar el origen Compra en `RemitoController`: datos del proveedor, y domicilio de entrega precargado con el **depósito que recibe**, no el del proveedor (FR-005)
- [x] T029 [US4] Agregar la sección Remitos en `resources/views/compras/detalle.blade.php`, igual a la de Ventas
- [x] T030 [US4] Crear `tests/Feature/RemitoCompraTest.php`: crear un remito de Compra **ya no falla** (antes fallaba por `venta_id` NOT NULL), se ve en el detalle, el documento sale con los datos del proveedor, y no mueve stock

---

## Phase 7: Correcciones de lo preexistente

- [x] T031 Corregir el tag mal cerrado del botón "Crear Remito" en `resources/views/ventas/detalle.blade.php:37` (falta el `>`, por eso no se ve el ícono — FR-024) y apuntarlo al formulario nuevo
- [x] T032 Corregir el link del menú de fila en `resources/views/ventas/_row_actions.blade.php:26`, que apunta a `ventas.show#remitos` — ancla inexistente **y** violación de la regla de no usar URLs con `#` (FR-025)
- [x] T033 Retirar `remitoStore()` de `VentaController` y `CompraController`, y sus rutas viejas, ya reemplazados por `RemitoController`
- [x] T034 Verificar que los 2 remitos históricos (N° 1 y N° 2, sin ítems ni transportista) se muestran en la sección y su documento abre sin error, con la tabla vacía (FR-026)

---

## Phase 8: Polish

- [x] T035 Recorrer el checklist `checklists/fidelidad-y-no-impacto.md` contra las 12 capturas, campo por campo. **No alcanza con que funcione: tiene que coincidir estructuralmente**
- [x] T036 Correr la suite completa y verificar que no hay regresiones nuevas respecto de la línea base del momento (al 12/08/2026 eran ~301 fallos preexistentes, ajenos a esta feature)
- [ ] T037 Eliminar en producción el remito N° 3 (creado por accidente el 12/08/2026 sobre la Venta 24038), conservando el N° 1 y el N° 2 — decisión del usuario. **Hacer backup antes**
- [x] T038 Registrar en `docs/importacion_casos_a_revisar.md` el hallazgo que excede esta spec: revisar si hay **más tablas** con el patrón `venta_id`/`compra_id` donde una de las dos quedó NOT NULL en la migración (ya pasó en `notas_credito_debito` y en `remitos`; podría haber otras sin detectar)

---

## Dependencias

```
Phase 1 (migraciones + modelos)
   └─> Phase 2 (controlador, rutas, requests)
          ├─> Phase 3 (US1 · emitir)         ← MVP
          │      ├─> Phase 4 (US2 · editar/eliminar)
          │      └─> Phase 5 (US3 · parciales)
          └─> Phase 6 (US4 · Compras)        ← necesita T004
                 └─> Phase 7 (correcciones)
                        └─> Phase 8 (polish)
```

- **T004 bloquea toda la Phase 6**: sin la corrección de nulabilidad, los remitos de Compra fallan.
- **T033 depende de T009 y T031**: no retirar los `remitoStore` viejos antes de que el botón nuevo
  funcione, o el botón queda apuntando a la nada.
- **US3 depende de US1**, porque sólo verifica el comportamiento del formulario ya construido.

## Paralelizables

- T005 y T006 (modelos distintos, sin dependencia)
- T010 y T011 (requests vs controlador de transportista)
- Phase 6 (Compras) respecto de las Phases 4-5, una vez lista la Phase 3

## MVP sugerido

**Phase 1 + 2 + 3** (T001-T019). Con eso el remito ya cumple su función: documenta la entrega y se
puede imprimir para que viaje con la mercadería. Editar, envíos parciales y Compras vienen después.

Si se busca el arreglo más barato con valor inmediato, **T031 + T032 + T016** (los tres bugs de UI)
hacen visible lo que hoy ya se crea, sin construir nada nuevo — aunque el remito siga sin ítems.
