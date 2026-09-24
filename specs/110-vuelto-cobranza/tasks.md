# Tasks — Vuelto en la cobranza de una Venta (spec 110)

**Branch**: `110-vuelto-cobranza` | **Fecha**: 2026-09-24
**Docs**: [spec.md](spec.md) · [plan.md](plan.md) · [research.md](research.md) · [data-model.md](data-model.md) · [contracts/](contracts/cobranzas-api.md) · [quickstart.md](quickstart.md)

**Tests**: OBLIGATORIOS. La constitución (principio IV) los exige para toda lógica que toque saldos
de tesorería y cuenta corriente, que es exactamente lo que hace esta spec.

---

## Phase 1: Setup

- [x] T001 Verificar que MySQL local esté levantado y tomar la línea de base de saldos ejecutando `SELECT ROUND(SUM(monto),2) FROM movimientos_tesoreria WHERE deleted_at IS NULL` contra la base `contagram`, anotando el resultado en el PR (se compara al final, [quickstart.md](quickstart.md) §4.2)

---

## Phase 2: Foundational (BLOQUEANTE — nada de US1/US2/US3 puede empezar antes)

- [x] T002 [P] Crear migración `database/migrations/*_add_vuelto_to_cobros_table.php` agregando `vuelto` decimal(14,2) nullable y `cuenta_vuelto_id` bigint nullable con FK a `cuentas_tesoreria` `nullOnDelete`, ambas después de `monto`
- [x] T003 [P] Crear migración `database/migrations/*_add_vuelto_to_movimientos_tesoreria_tipo_enum.php` con `ALTER TABLE movimientos_tesoreria MODIFY COLUMN tipo ENUM(...,'vuelto')`, documentando en el `down()` que revertir con filas `tipo='vuelto'` vivas falla y hay que reasignarlas primero
- [x] T004 [P] Crear migración `database/migrations/*_add_cuenta_vuelto_to_configuracion_ventas_table.php` agregando `cuenta_vuelto_id` bigint nullable con FK a `cuentas_tesoreria` `nullOnDelete`
- [x] T005 Correr `php artisan migrate` contra MySQL local y verificar el ENUM con `SHOW COLUMNS FROM movimientos_tesoreria LIKE 'tipo'` — **la suite en SQLite no valida esto** ([quickstart.md](quickstart.md) §2)
- [x] T006 **[CRÍTICO]** Filtrar la relación existente `movimientoTesoreria()` por `->where('tipo','cobro')` y agregar `movimientoVuelto()` filtrada por `->where('tipo','vuelto')` en `app/Models/Cobro.php` — sin este filtro el `morphOne` devuelve cualquiera de los dos movimientos y anular un cobro deja el ingreso vivo en la cuenta ([data-model.md](data-model.md) §4)
- [x] T007 Agregar `vuelto` y `cuenta_vuelto_id` al `$fillable` de `app/Models/Cobro.php`, más la relación `cuentaVuelto()` y un accessor `recibido()` que devuelva `monto + COALESCE(vuelto,0)`
- [x] T008 [P] Agregar `cuenta_vuelto_id` al `$fillable` de `app/Models/ConfiguracionVentas.php` con su relación `cuentaVuelto(): BelongsTo`

**Checkpoint**: migraciones aplicadas en MySQL y modelos listos. T006 es el que evita el saldo fantasma.

---

## Phase 3: User Story 1 — Registrar una cobranza con vuelto (P1) 🎯 MVP

**Objetivo**: el operador cobra $155.000 con $15.000 de vuelto en una sola operación; la venta se
imputa por el neto y quedan dos movimientos de tesorería reales.

**Test independiente**: cargar una cobranza con vuelto sobre una venta pendiente y verificar que la
venta queda saldada por el neto, la cuenta de cobro sube por lo recibido y la de vuelto baja por el
vuelto.

### Tests (van primero — principio IV)

- [x] T009 [P] [US1] Crear `tests/Feature/Cobranzas/VueltoCobranzaTest.php` con el caso feliz: venta de $140.000, cobranza de $155.000 con $15.000 de vuelto ⇒ `cobros.monto` = 140.000, venta "cobrada", movimiento `cobro` +155.000 y movimiento `vuelto` −15.000
- [x] T010 [P] [US1] Agregar a ese archivo los tests de rechazo: `vuelto >= monto` (FR-006), `vuelto > 0` sin `cuenta_vuelto_id` (FR-011), neto menor al saldo y neto mayor al saldo (FR-007), todos esperando 422 con el campo correcto en `errors`
- [x] T011 [P] [US1] Agregar el test de atomicidad (FR-008): forzar un fallo después del primer movimiento y verificar que no queda ni el cobro ni ningún movimiento
- [x] T012 [P] [US1] Agregar el test de compatibilidad (FR-014): una cobranza sin vuelto produce **un solo** movimiento y se comporta igual que antes

### Implementación

- [x] T013 [US1] Extender `app/Http/Requests/StoreCobroRequest.php`: campos `vuelto` (`nullable|numeric|gte:0|lt:monto`) y `cuenta_vuelto_id` (`required_if` vuelto>0, `exists`), y cambiar la regla del tope para que mida el **neto** (`monto − vuelto`) exigiendo igualdad con el saldo pendiente cuando hay vuelto ([contracts](contracts/cobranzas-api.md) §POST)
- [x] T014 [US1] Agregar los mensajes de error en español del contrato a `messages()` de `StoreCobroRequest`
- [x] T015 [US1] Extender `Cobranzas::registrarCobro()` en `app/Services/Ingresos/Cobranzas.php` para recibir vuelto y cuenta de vuelto, guardar el **neto** en `cobros.monto`, registrar el movimiento `cobro` por el **recibido** y, si hay vuelto, el movimiento `vuelto` con monto **negativo** — todo dentro de la transacción ya existente
- [x] T016 [US1] Actualizar `VentaController::cobranzaStore()` para pasar los campos nuevos al service y devolver `recibido`, `vuelto` y `cuenta_vuelto` en el JSON de respuesta
- [x] T017 [US1] Agregar al modal de cobranza en `resources/views/ventas/detalle.blade.php` el campo "Vuelto" y el select de cuenta de vuelto (**Select2** con `dropdownParent` al modal), visibles siempre y vacíos por defecto
- [x] T018 [US1] Actualizar `resources/js/ventas.js` para enviar los campos nuevos por AJAX, mostrar los errores 422 en el modal con **toast de NexaDash** y refrescar la ficha sin recargar la página
- [x] T019 [US1] Mostrar el vuelto en la tabla de Cobranzas de la ficha de la venta (FR-015), de modo que se entienda por qué lo recibido difiere de lo imputado

**Checkpoint**: MVP funcional. US2 y US3 son independientes de acá en adelante.

---

## Phase 4: User Story 2 — Caja por defecto del vuelto (P2)

**Objetivo**: configurar una vez la cuenta habitual del vuelto y que venga preseleccionada.

**Test independiente**: configurar la cuenta, abrir una cobranza y verificar que aparece
preseleccionada.

- [x] T020 [P] [US2] Crear el test de que el default se guarda y se devuelve preseleccionado, y de que cambiar la cuenta en una cobranza puntual **no** modifica la configuración global (FR-010)
- [x] T021 [US2] Agregar el select de "Cuenta por defecto para vueltos" (Select2) a la vista de Configuración & Ajustes → Ventas y su validación en el request correspondiente
- [x] T022 [US2] Preseleccionar esa cuenta en el modal de cobranza desde `VentaController` / `resources/js/ventas.js`, dejándola editable (FR-010) y exigiendo elección cuando no hay default configurado (FR-011)

---

## Phase 5: User Story 3 — Editar y anular con vuelto (P3)

**Objetivo**: corregir o anular una cobranza con vuelto sin dejar movimientos inconsistentes.

**Test independiente**: editar el monto y el vuelto y verificar ambos movimientos; anular y
verificar que no queda ninguno vivo.

### Tests

- [x] T023 [P] [US3] Test de edición (FR-012) con los tres casos: tenía vuelto y sigue teniendo, no tenía y ahora sí, tenía y ahora no (el movimiento de vuelto se soft-deletea)
- [x] T024 [P] [US3] **[CRÍTICO]** Test de anulación (FR-013): tras anular una cobranza con vuelto, la consulta de movimientos por `origen` devuelve **0 filas** — es la verificación de que T006 evitó el saldo fantasma
- [x] T025 [P] [US3] Revisar los 3 asserts de 422 de `tests/Feature/Cobranzas/ActualizarCobroTest.php` (líneas 167, 184, 218) y ajustarlos al tope nuevo medido contra el neto

### Implementación

- [x] T026 [US3] Extender `app/Http/Requests/UpdateCobroRequest.php` con los campos nuevos y el tope calculado como `saldo pendiente + neto actual del cobro`
- [x] T027 [US3] Extender `Cobranzas::actualizarCobro()` para actualizar in-place el movimiento de cobro y, según el caso, actualizar / crear / soft-deletear el de vuelto, todo en una transacción
- [x] T028 [US3] Extender `Cobranzas::anularCobro()` para soft-deletear **ambos** movimientos, usando las relaciones filtradas de T006 y conservando el fallback `movimientoHuerfanoDe('cobro', ...)` para los cobros importados

---

## Phase 6: Cross-cutting — que el tipo nuevo no quede invisible

> ⚠️ **Precedente real**: al agregar el tipo `ingreso`, el flujo de caja seguía filtrando
> `tipo IN ('cobro')` y dejó **$34.570.442,27 invisibles**. Nada falla: la plata simplemente no
> aparece. Estas tareas no son cosméticas.

- [x] T029 **[CRÍTICO]** Agregar `'vuelto'` al desglose de egresos en `app/Services/Tesoreria/Tesoreria.php:204` (`$pagos = $desglose(['pago','gasto'], absoluto: true)`) — sin esto el vuelto no se cuenta como egreso en el flujo de caja
- [x] T030 [P] Agregar `'vuelto' => 'Vuelto'` al mapa `LABELS` de `app/Http/Controllers/CuentaTesoreriaController.php` y al filtro de tipo de operación del ledger, para que no se muestre en blanco
- [x] T031 [P] Revisar `app/Services/Tesoreria/SeccionesMovimientos.php` y los exports del ledger por listas fijas de tipos que deban incluir `vuelto`
- [x] T032 [P] Verificar que el informe de Gastos **no** incluya `tipo='vuelto'` (FR-005, SC-002) y agregar el test que lo fije
- [x] T033 [US1] Actualizar `VentaController::reciboCobranza()` y `resources/views/recibos/pdf.blade.php` para imprimir **recibido / vuelto / neto** cuando hay vuelto, dejando el recibo sin vuelto exactamente como está hoy (FR-016)

---

## Phase 7: Validación final

- [x] T034 Correr la suite completa (`php artisan test`) y dejarla en verde
- [x] T035 Ejecutar el recorrido completo de [quickstart.md](quickstart.md) §3 en el navegador contra **MySQL local** — obligatorio: la suite en SQLite no valida el ENUM ni el comportamiento real. Cubre SC-001 (una sola operación), SC-004 (los saldos coinciden peso por peso con el movimiento físico) y SC-005 (el total de la venta no se infla)
- [x] T036 Verificar que la suma total de `movimientos_tesoreria` coincide con la línea de base de T001 antes de cargar datos de prueba (SC-006: la migración no mueve ni un peso)
- [x] T037 Verificar en el navegador que una cobranza **sin** vuelto sigue comportándose igual que antes (FR-014) y que el recibo sin vuelto no cambió

---

## Dependencias

```
Phase 1 (T001)
   ↓
Phase 2 (T002-T008)  ← BLOQUEANTE; T006 es la barrera anti saldo fantasma
   ↓
   ├─ Phase 3 US1 (T009-T019)  🎯 MVP
   ├─ Phase 4 US2 (T020-T022)  ← independiente de US1
   └─ Phase 5 US3 (T023-T028)  ← necesita US1 para tener qué editar
         ↓
   Phase 6 (T029-T033)  ← puede empezar apenas exista el tipo (T005)
         ↓
   Phase 7 (T034-T037)
```

## Paralelizables

- **Phase 2**: T002, T003, T004 (migraciones distintas) y T008
- **Phase 3**: T009-T012 (todos en el mismo archivo de test, pero secciones distintas; escribir antes de implementar)
- **Phase 6**: T030, T031, T032 (archivos distintos)

## MVP

**Phase 1 + Phase 2 + Phase 3 (US1)** = el pedido del cliente resuelto: cobrar con vuelto en una
sola operación, sin Gasto falso ni Nota de Débito.

**Pero T029 y T033 no son opcionales para producción** aunque estén fuera de US1: sin T029 el vuelto
no figura como egreso en el flujo de caja, y sin T033 el recibo que se le entrega al cliente muestra
un importe que no es el que pagó.

## Resumen

| Fase | Tareas | Foco |
|---|---|---|
| 1. Setup | T001 | Línea de base |
| 2. Foundational | T002–T008 | Migraciones + **la barrera del morph** |
| 3. US1 (P1) | T009–T019 | MVP: cobrar con vuelto |
| 4. US2 (P2) | T020–T022 | Default configurable |
| 5. US3 (P3) | T023–T028 | Editar / anular |
| 6. Cross-cutting | T029–T033 | **Que el tipo nuevo no oculte plata** + recibo |
| 7. Validación | T034–T037 | Suite + navegador contra MySQL |

**Total: 37 tareas.**

---

## Resultado de la validación (24/09/2026)

**Tests**: 20 nuevos de la spec, todos verdes, más los 10 preexistentes de
`ActualizarCobroTest` que siguen pasando sin tocarlos.

**Navegador, contra MySQL real** (base `contagram_vps_clon`, venta 24672):

| Verificación | Resultado |
|---|---|
| ENUM `tipo` con `'vuelto'` en MySQL | ✅ |
| Cuenta por defecto preseleccionada en el modal | ✅ "Caja del Local" |
| Desglose en vivo recibido/vuelto/neto | ✅ 30.000 − 1.141,29 = 28.858,71 |
| Dos movimientos: `cobro` +30.000 / `vuelto` −1.141,29 | ✅ |
| Venta saldada | ✅ $0 |
| Vuelto visible en la ficha (FR-015) | ✅ |
| Recibo con recibido/vuelto/imputado (FR-016) | ✅ |
| Cobranza **sin** vuelto: un solo movimiento (FR-014) | ✅ |
| Anular deja 0 movimientos vivos (FR-013) | ✅ **sin saldo fantasma** |
| Saldos totales vs línea de base (SC-006) | ✅ $36.964.804,48 / 49.080, idéntico |

**Dos bugs encontrados validando en el navegador** (no los detectaban los tests):

1. El payload AJAX de Configuración → Ventas se arma campo por campo, así que
   `cuenta_vuelto_id` no viajaba: el default **nunca se guardaba**, y el toast decía
   "guardado" igual. Arreglado en `resources/js/configuracion-ventas.js`.
2. Al select nuevo le faltaba Select2, a diferencia del resto de esa pantalla.

**Fallas preexistentes de la suite** (NO introducidas por esta spec): `AbonosServiceTest`
falla por `App\Models\Abono` que no existe en el repo desde el commit inicial, y
3 tests de configuración fallan por fechas hardcodeadas. Verificado corriendo los
mismos tests en `main`: fallan idénticamente sin estos cambios.
