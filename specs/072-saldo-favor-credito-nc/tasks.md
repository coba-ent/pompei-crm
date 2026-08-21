# Tasks: Saldo a favor aplicable a nuevas Ventas y Compras

**Feature**: `specs/072-saldo-favor-credito-nc/` · **Fecha**: 2026-08-21

**Input**: [spec.md](./spec.md) · [plan.md](./plan.md) · [research.md](./research.md) ·
[data-model.md](./data-model.md) · [contracts/](./contracts/aplicaciones-credito-api.md) ·
[quickstart.md](./quickstart.md)

**Tests**: obligatorios. Principio IV de la constitución (testing donde hay dinero) — toda esta
feature es dinero.

---

## Phase 1: Setup

- [x] T001 Crear la migración `database/migrations/2026_08_21_000000_create_aplicaciones_credito_table.php` con la estructura de data-model.md (morphs origen/destino, `nota_credito_debito_id` nullable, `monto`, `fecha`, `nota`, `usuario_id`, timestamps, `softDeletes`) y sus tres índices
- [x] T002 Crear el modelo `app/Models/AplicacionCredito.php` con `SoftDeletes`, casts (`monto` decimal:2, `fecha` date), relaciones `origen()`/`destino()` (morphTo), `notaCreditoDebito()` y `usuario()`
- [x] T003 [P] Crear el directorio de tests `tests/Feature/Creditos/` y una factory `database/factories/AplicacionCreditoFactory.php`

---

## Phase 2: Foundational (bloquea todas las user stories)

**⚠️ La fórmula de saldo se toca en varios archivos y DEBE quedar consistente en todos. Tratar
T005–T010 como un bloque atómico: dejar uno afuera reproduce exactamente la divergencia entre badge y
filtro que se encontró el 20/08/2026.**

- [x] T004 Crear `app/Services/Ingresos/CreditoCliente.php` con `disponible(Model $comprobante): float`, `disponiblePara(Model $destino): array` (orígenes ordenados del más antiguo al más nuevo) y `aplicar(Model $destino, float $monto, Carbon $fecha, ?string $nota, ?int $origenId): Collection`, con `DB::transaction` + `lockForUpdate()` sobre el/los orígenes (research Decisión 4)
- [x] T005 Agregar a `app/Models/Venta.php` los métodos `creditoRecibido()`, `creditoCedido()`, `creditoDisponible()` y las relaciones morph, y modificar `aCobrar()` a `total + ND − NC − cobrado − creditoRecibido + creditoCedido`
- [x] T006 Agregar los mismos métodos a `app/Models/Compra.php` y modificar `aPagar()` de forma simétrica
- [x] T007 Actualizar `VentaController::sqlACobrar()` y los JOINs de `VentaController::kpis()` para incluir los dos términos nuevos
- [x] T008 Actualizar el filtro `estado_pago` y los KPIs de `app/Http/Controllers/CompraController.php` con los dos términos nuevos
- [x] T009 Actualizar `app/Services/Tesoreria/CuentaCorriente.php` (`porCliente()`, `aging()`, `queryMovimientos()` y `documentosParaAging()`) con los dos términos nuevos
- [x] T010 Actualizar `app/Services/Informes/VentasInformeQuery.php` y `ComprasInformeQuery.php` con los dos términos nuevos, e incorporar NC/ND al filtro de estado de cobro/pago que hoy usa `total − cobrado` (FR-023; deuda detectada el 20/08/2026, ver checklist `dinero.md` CHK021). **Ojo**: estos informes son réplicas validadas contra Contagram — correr sus tests de réplica (`tests/Feature/Informes/`) antes y después, y si algún total cambia, decidir explícitamente si la réplica estaba mal o si el informe debe quedar con el criterio viejo
- [x] T011 Test `tests/Feature/Creditos/CalculoCreditoDisponibleTest.php`: crédito disponible sobre comprobante pagado con NC, **cero** sobre comprobante impago con NC (research Decisión 2), cero sin NC, y descuento de lo ya cedido
- [x] T012 Test `tests/Feature/Creditos/TransferenciaDeSaldoTest.php`: aplicar crédito no cambia el saldo de cuenta corriente del cliente (FR-003a, SC-001a) — reproduce el caso Florencia con los importes reales
- [x] T013a Test `tests/Feature/Creditos/CreditoDeNotaAnuladaTest.php`: una Nota de Crédito anulada (soft-deleted) no aporta crédito disponible (FR-002)
- [x] T013b Test de regresión `tests/Feature/Creditos/CircuitoDineroIntactoTest.php`: una cobranza normal con dinero **sigue** generando su `MovimientoTesoreria` como hasta hoy, y sigue siendo posible emitir una NC por un monto mayor al del comprobante (FR-020, FR-021)
- [x] T013 **Test de invariante de Tesorería** `tests/Feature/Creditos/TesoreriaIntactaTest.php`: mide A Cobrar, A Pagar, Disponible, aging de clientes, aging de proveedores, cantidad y suma de `movimientos_tesoreria` antes y después de aplicar y de anular; falla ante cualquier diferencia (FR-017/018/019, SC-003)

**Checkpoint**: con esta fase en verde, los saldos son correctos y Tesorería está protegida por un
test que falla el build. Recién ahí se puede exponer la funcionalidad.

---

## Phase 3: User Story 1 — Aplicar el crédito a la venta nueva (P1)

**Objetivo**: que el operador pueda imputar el saldo a favor desde el modal de cobranza.

**Test independiente**: escenario 1 de [quickstart.md](./quickstart.md) — la venta nueva queda en
$0, el origen baja a −$3.465,29 y el saldo del cliente no cambia.

- [x] T014 [US1] Crear `app/Http/Requests/StoreAplicacionCreditoRequest.php` con las reglas del contrato (monto > 0 y ≤ aplicable, fecha requerida, `origen_id` opcional validado contra mismo tipo/cliente/distinto del destino)
- [x] T015 [US1] Crear `app/Http/Controllers/AplicacionCreditoController.php` con `disponibleVenta()`, `storeVenta()` y `destroyVenta()` según [contracts/aplicaciones-credito-api.md](./contracts/aplicaciones-credito-api.md), devolviendo `a_cobrar`, `estado_cobro` y `credito_disponible_restante`
- [x] T016 [US1] Registrar en `routes/web.php` las rutas `ventas.credito.disponible` (GET), `ventas.aplicaciones-credito.store` (POST) y `.destroy` (DELETE), con el mismo permiso que ya exige cargar una cobranza
- [x] T017 [US1] En `resources/views/ventas/detalle.blade.php`, agregar al select de medio de cobro del modal de Cobranza la opción "Saldo a favor" en un `<optgroup>` separado de las cuentas de tesorería
- [x] T018 [US1] En `resources/js/ventas.js`, al abrir el modal de cobranza consultar el endpoint de disponible; mostrar la opción sólo si hay crédito (FR-006), pre-cargar el monto con `aplicable`, mostrar de qué comprobante sale, y rutear el submit al endpoint de aplicación cuando el medio elegido sea "Saldo a favor"
- [x] T019 [US1] Test `tests/Feature/Creditos/AplicarCreditoVentaTest.php`: aplicación exitosa, tope por saldo del comprobante, tope por crédito disponible, origen = destino rechazado, cliente distinto rechazado
- [x] T020 [US1] Test `tests/Feature/Creditos/ConcurrenciaCreditoTest.php`: dos aplicaciones simultáneas no dejan el disponible negativo (FR-013)
- [x] T020a [US1] Test `tests/Feature/Creditos/OrdenDeConsumoTest.php`: con varios comprobantes con crédito, el consumo va del más antiguo al más nuevo y puede cubrirse con más de un origen en una sola operación (FR-008, contrato §2)
- [x] T020b [US1] Test `tests/Feature/Creditos/PermisosCreditoTest.php`: aplicar y anular exigen el mismo permiso que cargar una cobranza; un usuario sin ese permiso recibe 403 (FR-022)

**Checkpoint**: US1 entregable por sí sola. Resuelve el caso que motivó la feature.

---

## Phase 4: User Story 2 — Saldo del cliente visible al cargar la venta (P1)

**Objetivo**: que el vendedor vea el saldo en el momento de cargar la venta.

**Test independiente**: abrir Nueva Venta, buscar un cliente con saldo ≠ 0 y ver el importe junto al
nombre con el signo correcto.

- [x] T021 [P] [US2] Agregar `saldo` a la respuesta de `ClienteController::opciones()`, calculado sólo sobre los ids de la página devuelta (nunca sobre el catálogo completo, plan §Performance Goals)
- [x] T022 [P] [US2] Agregar `saldo` al endpoint de opciones de proveedores de la misma forma
- [x] T023 [US2] En `resources/js/ventas.js` y `resources/js/compras.js`, formatear el saldo en el `templateResult`/`templateSelection` del Select2 de cliente/proveedor, distinguiendo deuda de saldo a favor y omitiéndolo cuando es cero (FR-014)
- [x] T024 [US2] Test `tests/Feature/Creditos/SaldoEnSelectorTest.php`: el endpoint devuelve el saldo con el signo correcto (negativo = a favor) y no rompe el contrato actual del buscador
- [x] T025 [US2] Medir el tiempo de respuesta del buscador con el saldo incluido sobre el volumen real (~20.000 clientes); si se degrada, reemplazar el cálculo por una consulta agregada acotada a los ids de la página

---

## Phase 5: User Story 3 — Trazabilidad NC ↔ comprobante (P2)

**Objetivo**: poder auditar de qué NC salió cada crédito aplicado.

**Test independiente**: aplicar un crédito parcial y navegar origen ↔ destino desde la UI.

- [x] T026 [US3] En `resources/views/ventas/detalle.blade.php`, listar las aplicaciones de crédito en la sección de Cobranzas como líneas propias, marcadas como saldo a favor y con link al comprobante de origen — **sin** sumarlas al total "Cobrado" (contrato §4)
- [x] T027 [P] [US3] Hacer lo mismo en `resources/views/compras/detalle.blade.php` para los pagos
- [x] T028 [US3] En la sección de NC/ND del detalle, mostrar por cada Nota de Crédito su monto, cuánto se consumió, cuánto queda disponible y en qué comprobantes se aplicó (FR-016)
- [x] T029 [US3] Agregar la relación `aplicaciones()` a `app/Models/NotaCreditoDebito.php` y bloquear su eliminación con 422 si tiene aplicaciones vivas (FR-012)
- [x] T030 [US3] Test `tests/Feature/Creditos/AnulacionCreditoTest.php`: anular una aplicación devuelve el crédito al origen; eliminar una NC con aplicaciones vivas da 422

---

## Phase 6: User Story 4 — Crédito de proveedor en Compras (P3)

**Objetivo**: mismo circuito del lado de Egresos.

**Test independiente**: escenario 6 de [quickstart.md](./quickstart.md).

- [x] T031 [US4] Agregar `disponibleCompra()`, `storeCompra()` y `destroyCompra()` a `AplicacionCreditoController` reusando `CreditoCliente` (el servicio es agnóstico del tipo de comprobante)
- [x] T032 [US4] Registrar las rutas equivalentes de Compras en `routes/web.php` con el permiso de pagos
- [x] T033 [US4] En `resources/views/compras/detalle.blade.php` y `resources/js/compras.js`, agregar la opción "Saldo a favor" al modal de Pago, con el mismo comportamiento que en Ventas
- [x] T034 [US4] Test `tests/Feature/Creditos/AplicarCreditoCompraTest.php`: aplicación exitosa y topes del lado de proveedores, más el chequeo de que un crédito de cliente no aparece disponible en Compras

---

## Phase 7: Polish & Cross-Cutting

- [x] T035 Ejecutar la suite completa (`php artisan test`) y comparar contra el baseline de fallos preexistentes: los 15 fallos de Ventas y 20 de Compras son previos a esta feature y NO deben aumentar
- [x] T036 Correr el escenario 2 de [quickstart.md](./quickstart.md) contra la base local con datos reales y verificar que las siete líneas de Tesorería dan idénticas
- [x] T037 [P] Verificar en el navegador que con un cliente sin crédito el modal de cobranza se ve exactamente igual que antes (FR-006, quickstart escenario 3)
- [x] T038 [P] Revisar que el `<input>` de fecha del modal use `data-fecha-ar` y `AppFecha` (regla 6 de CLAUDE.md), y que el select nuevo respete las reglas de Select2 dentro de modal (`dropdownParent`)
- [x] T039 Verificar el checklist [checklists/dinero.md](./checklists/dinero.md) ítem por ítem contra la implementación final
- [x] T040 Instruir al negocio (nota en el handoff) que deje de eliminar la cobranza vieja al hacer una devolución: con esta feature ya no hace falta y es lo que borra el crédito

---

## Nota para el negocio (T040)

**Dejen de borrar la cobranza vieja cuando un cliente devuelve mercadería.** Ese paso era el único
camino que había para imputar la plata a la venta nueva, y es exactamente lo que hace desaparecer el
saldo a favor del cliente (caso FLORENCIA, 20/08/2026: se perdieron $3.465,29).

El procedimiento nuevo es:

1. Se emite la Nota de Crédito sobre la venta original. **La cobranza original queda como está.**
2. Se carga la venta nueva.
3. En "Agregar Cobranza" aparece el bloque **Saldo a favor del cliente** con el crédito disponible.
   Se aprieta "Aplicar saldo a favor" y listo.
4. Lo que sobra queda vivo como saldo a favor y se ve en el selector de cliente la próxima vez que se
   le cargue una venta.

Del lado de Compras funciona igual con las Notas de Crédito de proveedor, desde el modal de Pago.

---

## Verificación en navegador (21/08/2026)

Probado contra la base local con datos reales (~23.800 ventas), Chrome DevTools:

- Venta 22984 (destino) con crédito de la venta 17589: quedó en A Cobrar $0, "Cobrado" **no** se
  contaminó, y la línea de saldo a favor linkea al origen y a su NC.
- Venta 17589 (origen): pasó de −$10.527,00 a −$10.159,26 y la NC 529 muestra "Consumido $367,74 en
  Venta 22984 / Disponible $10.159,26".
- **Totales de Tesorería idénticos con y sin la aplicación** (A Cobrar $14.425.645,83, A Pagar
  $26.143.215,87, Disponible $28.842.376,33, aging clientes $8.050.077,39) y cero movimientos de
  tesorería nuevos. Es el escenario 2 de quickstart.md contra MySQL real, no SQLite.
- Venta 22983 (cliente sin crédito): el modal se ve exactamente igual que antes.
- Selector de Nueva Venta: "NO USAR MAS · a favor $ 22.360,13" y, con saldo cero, sólo el nombre.
- Compra 2403: el modal de Pago ofrece "Saldo a favor con el proveedor $ 255.570,75".
- Consola sin errores; listado y KPIs de Ventas/Compras, Informe de Ventas (los tres filtros de
  estado de cobro) y las dos Cuentas Corrientes responden 200.

**Bug encontrado y corregido en esta verificación**: `CuentaCorriente::saldosPorEntidad()` agrupaba
con subselects correlacionados y MySQL lo rechazaba con `ONLY_FULL_GROUP_BY` (error 1055) — el
buscador de clientes devolvía 500. SQLite lo acepta, así que la suite no lo detectaba. Se reescribió
agrupando sobre una subconsulta.

---

## Dependencias

```
Phase 1 (T001-T003)
        ↓
Phase 2 (T004-T013)  ← BLOQUEANTE: fórmula + tests de invariante
        ↓
   ┌────┴────┬──────────┬──────────┐
   ↓         ↓          ↓          ↓
 US1       US2        US3        US4
(T014-20) (T021-25)  (T026-30)  (T031-34)
   └────┬────┴──────────┴──────────┘
        ↓
Phase 7 (T035-T040)
```

- **US1** es el MVP y no depende de las demás.
- **US2** es independiente de US1 (se puede entregar sola: el vendedor ve el saldo aunque todavía
  aplique el crédito a mano).
- **US3** depende de que existan aplicaciones, o sea de US1.
- **US4** depende de la Phase 2 (fórmula de `Compra`), no de US1.

## Paralelización

- T021 y T022 (clientes / proveedores) son archivos distintos → `[P]`.
- T026 y T027 (detalle de Venta / de Compra) → `[P]`.
- T037 y T038 son verificaciones independientes → `[P]`.
- Dentro de la Phase 2 **no hay paralelización**: es un bloque atómico a propósito.

## Estrategia de implementación

1. **MVP = Phase 1 + Phase 2 + US1**. Con eso el caso Florencia queda resuelto y auditado.
2. **US2 inmediatamente después**: es barata y es la que hace que el crédito se use en la práctica.
3. US3 y US4 pueden ir en una segunda entrega sin bloquear el uso diario.
4. **No pasar a ninguna user story con la Phase 2 en rojo**: es la que protege las cajas.
