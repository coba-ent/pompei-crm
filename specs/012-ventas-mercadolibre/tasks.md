# Tasks: Ventas de Mercado Libre

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md) · **Contratos**: [contracts/rutas-internas.md](./contracts/rutas-internas.md) · **Validación**: [quickstart.md](./quickstart.md)

**Branch**: `012-ventas-mercadolibre` · **Fecha**: 2026-07-27

**Tests**: incluidos y **obligatorios** — el principio IV de la constitución los exige para toda lógica
de importes, comprobantes, stock y saldos de tesorería, que es exactamente el centro de esta spec.

**Convención**: `[P]` = paralelizable (archivo distinto, sin dependencias pendientes). `[USn]` = historia
de usuario a la que pertenece.

---

## Phase 1 — Setup

- [x] T001 Crear el directorio `app/Http/Controllers/Ingresos/` y `resources/views/ingresos/mercadolibre/` siguiendo la estructura definida en plan.md
- [x] T002 [P] Registrar el permiso y el guard de función avanzada activa reutilizables para las pantallas nuevas en `app/Http/Middleware/` (o confirmar que `VerificarPermiso` alcanza y documentarlo)

---

## Phase 2 — Foundational (bloquea todas las historias)

### 2.a Cierre de la brecha de stock de Ventas (research §R1) — ⚠️ toca código existente

> Sin esto, FR-046 referencia un comportamiento inexistente y la spec 013 no tiene movimiento local que
> propagar. Se hace primero y con regresión, por ser el cambio de mayor riesgo sobre el módulo Ventas.

- [x] T003 Crear `app/Services/Ingresos/StockDeVenta.php` con los métodos de aplicar salida, reintegrar y re-aplicar por edición, delegando en `StockService::registrarSalida()`/`registrarEntrada()` con `origen` polimórfico a la Venta (FR-046, FR-046c)
- [x] T004 Implementar en `StockDeVenta` el filtro de líneas que mueven stock: sólo ítems con `producto_id` y de tipo Producto; excluir Servicios e ítems libres (FR-046a)
- [x] T005 Implementar en `StockDeVenta` la resolución del depósito: el configurado para Mercado Libre en Ventas de ese origen, el depósito por defecto en las manuales (FR-047)
- [x] T006 Cablear `StockDeVenta` en el alta de Venta dentro de la transacción existente de `app/Http/Controllers/VentaController.php::store()` (FR-046, FR-048)
- [x] T007 Cablear el reintegro y la re-aplicación de stock en `VentaController::update()` (FR-046b)
- [x] T008 Extender `app/Observers/VentaObserver.php` para reintegrar stock al borrado lógico, conservando la reversión de cobros ya existente (FR-046b)
- [x] T009 [P] Test de regresión del módulo Ventas existente en `tests/Feature/Ingresos/VentaStockTest.php`: alta descuenta, edición reajusta, borrado reintegra, Servicios e ítems libres no mueven stock (quickstart §Escenario 7)
- [x] T010 [P] Ampliar el filtro "Operación" del Informe de Stock para exponer `salida`/`entrada` en `app/Http/Controllers/Informes/` (quickstart §Escenario 7)

### 2.b Esquema de datos

- [x] T011 [P] Migración `create_ml_ordenes_table` con todos los campos e índices de data-model §2, incluidos los **únicos** en `ml_order_id` y `venta_id` (FR-032, FR-032b)
- [x] T012 [P] Migración `create_ml_orden_items_table` per data-model §3
- [x] T013 [P] Migración `create_ml_publicacion_producto_table` con los **dos índices únicos** que garantizan la cardinalidad 1:1 (FR-022)
- [x] T014 [P] Migración `add_ventas_fields_to_ml_configuracion_table` con las columnas de data-model §5
- [x] T015 [P] Migración `add_origen_to_ventas_table` con el enum `manual`/`presupuesto`/`mercadolibre` (FR-035)

### 2.c Enums y modelos

- [x] T016 [P] Crear `app/Enums/MercadoLibre/EstadoConversion.php` con los cinco estados y el método de transiciones válidas (FR-007a)
- [x] T017 [P] Crear `app/Enums/MercadoLibre/MotivoRequiereAtencion.php` con los cinco motivos de data-model §1 (FR-007b)
- [x] T018 [P] Crear `app/Enums/MercadoLibre/EstadoOrden.php` con la normalización del estado crudo del proveedor
- [x] T019 [P] Crear `app/Models/Integraciones/MercadoLibreOrden.php` con relaciones, casts y scopes por estado de conversión
- [x] T020 [P] Crear `app/Models/Integraciones/MercadoLibreOrdenItem.php`
- [x] T021 [P] Crear `app/Models/Integraciones/MercadoLibrePublicacionProducto.php` con las relaciones a Producto
- [x] T022 Extender `app/Models/Integraciones/MercadoLibreConfiguracion.php` con los campos nuevos, sus casts y el accesor del depósito efectivo (FR-047)
- [x] T023 [P] Extender `app/Models/Venta.php` con el campo `origen` y un scope por origen (FR-035)

### 2.d Cliente de API

- [x] T024 ~~Verificar la forma real de la respuesta de órdenes y de datos de facturación~~ — **COMPLETADA el 27/07/2026** contra la documentación oficial (MCP autorizado). Resultados en research §R2 y §R8; contrato externo en contracts §5. Surgieron 4 hallazgos que modificaron el diseño: canceladas excluidas, retención de 12 meses, respuestas parciales 206, y tag de fraude
- [x] T025 Crear `app/Services/MercadoLibre/TraductorOrdenes.php` como **único** punto que interpreta el formato externo, mapeando los campos verificados en contracts §5 a los modelos del CRM (research §R3)
- [x] T025a Implementar en `TraductorOrdenes` la detección de los tres marcadores de bloqueo: `variation_id` no nulo, tag `fraud_risk_detected` y moneda distinta (FR-027, FR-052a, FR-030d)
- [x] T025b Implementar el manejo de respuestas parciales (HTTP 206 con `X-Content-Missing`): procesarlas sin tratarlas como error y registrar qué bloques faltaron (FR-012b)
- [x] T026 [P] Test de `TraductorOrdenes` en `tests/Feature/Integraciones/TraductorOrdenesTest.php` con las respuestas de ejemplo de la documentación oficial: orden simple, con variantes, con descuentos, sin datos fiscales, con alerta de fraude y respuesta parcial 206
- [x] T026a [P] Migración `add_ml_user_id_to_clientes_table` con índice **único**, para el emparejamiento estable del comprador (FR-036, FR-036a, research §R12)

---

## Phase 3 — US1: Ver las ventas de Mercado Libre (P1) 🎯 MVP

**Objetivo**: traer las órdenes y mostrarlas. **Test independiente**: presionar "Sincronizar ahora" con
la cuenta conectada y ver las órdenes reales, sin convertir ninguna.

- [x] T027 [US1] Crear `app/Services/MercadoLibre/SincronizadorOrdenes.php` con paginación por `offset`/`limit`, sincronización incremental por `order.date_last_updated.from` con solapamiento, y *upsert* por `ml_order_id` (FR-012, FR-013, FR-016, research §R4)
- [x] T027a [US1] Implementar la **segunda pasada de órdenes canceladas** con `order.status=cancelled`, ya que la búsqueda estándar del vendedor las excluye (FR-012a, contracts §5). Sin esto, US6 no funciona
- [x] T027b [US1] Topear `dias_primera_sync` en 12 meses, máximo que Mercado Libre conserva (FR-016)
- [x] T028 [US1] Implementar en `SincronizadorOrdenes` el candado global de corrida única con `Cache::lock` (FR-014, research §R6)
- [x] T029 [US1] Implementar los cortes previos al bucle de paginación: función desactivada, modo sólo lectura y conexión caída o no configurada, con un único registro en el historial (FR-017, FR-018, research §R10)
- [x] T030 [US1] Implementar la persistencia de avance por página para que una corrida interrumpida se retome sin reprocesar (FR-015)
- [x] T031 [US1] Implementar el cálculo del estado de conversión derivado y su persistencia (FR-007a, plan §3)
- [x] T032 [US1] Crear `app/Http/Controllers/Ingresos/MercadoLibreVentaController.php` con `index`, `datatable` y `sincronizar` según contracts §1
- [x] T033 [US1] Registrar las rutas del grupo Ingresos → Mercado Libre en `routes/web.php` con el permiso `ventas.ver` y el guard de función activa (FR-002, FR-003)
- [x] T034 [US1] Crear la vista del listado en `resources/views/ingresos/mercadolibre/index.blade.php` extendiendo `layouts.default`
- [x] T035 [US1] Crear `resources/js/mercadolibre-ventas.js` con la DataTable server-side, el panel de filtros y el botón "Sincronizar ahora" con Toastr, sin recarga de página (FR-004, FR-006, FR-009)
- [x] T036 [US1] Registrar el nuevo bundle en `vite.config.js` y en el pagelevel de `config/dz.php`
- [x] T037 [US1] Agregar la entrada condicional "Mercado Libre" al menú Ingresos en `resources/views/elements/sidebar.blade.php`, visible sólo con la función activa (FR-002)
- [x] T037a [US1] Agregar el botón **"Ver mis Órdenes"** en la tarjeta de Mercado Libre de Funciones Avanzadas, con la cuenta conectada, replicando a Contagram y el patrón de "Ir al listado de Abonos" (FR-002a)
- [x] T038 [US1] Mostrar en el listado el distintivo de orden de prueba y el motivo de las órdenes que requieren atención (FR-007, FR-008)
- [x] T039 [P] [US1] Test en `tests/Feature/Integraciones/MercadoLibreSincronizacionTest.php`: sincronización trae órdenes, re-sincronizar no duplica, corrida interrumpida se retoma (FR-013, FR-015, SC-004, SC-014)
- [x] T040 [P] [US1] Test de los tres cortes de bloqueo y del acceso denegado sin permiso (FR-017, FR-018, FR-003)

---

## Phase 4 — US2: Vincular publicaciones con productos (P1)

**Objetivo**: relación 1:1 persistente. **Test independiente**: vincular una publicación y comprobar que
la siguiente orden de esa publicación ya viene resuelta.

- [x] T041 [US2] Crear `app/Http/Controllers/Ingresos/MercadoLibreVinculacionController.php` con `index`, `datatable`, `store`, `update` y `destroy` según contracts §2
- [x] T042 [US2] Crear el FormRequest de vinculación en `app/Http/Requests/Integraciones/` validando la cardinalidad 1:1 con mensajes por campo y el rechazo de publicaciones con variantes (FR-022, FR-027)
- [x] T043 [US2] Implementar en `destroy` la advertencia por órdenes ya convertidas, sin modificar las Ventas existentes (FR-026, FR-062)
- [x] T044 [US2] Registrar las rutas de vinculaciones en `routes/web.php`
- [x] T045 [US2] Crear la vista `resources/views/ingresos/mercadolibre/vinculaciones.blade.php` con DataTable server-side y modales de alta/edición/baja (FR-024, FR-025)
- [x] T046 [US2] Implementar el selector de producto con **Select2** usando el endpoint `productos.opciones` existente, con `dropdownParent` en los modales (regla obligatoria de diseño #5)
- [x] T047 [P] [US2] Test en `tests/Feature/Integraciones/MercadoLibreVinculacionTest.php`: la cardinalidad 1:1 se rechaza en ambos sentidos **y a nivel de base de datos**, no sólo por validación (FR-022, SC-007)
- [x] T048 [P] [US2] Test de rechazo de publicaciones con variantes (FR-027)

---

## Phase 5 — US3: Convertir manualmente una orden en Venta (P1)

**Objetivo**: el corazón de la spec. **Test independiente**: convertir una orden pagada y verificar
Venta, total exacto, cobranza y stock.

- [x] T049 [US3] Crear `app/Services/MercadoLibre/DerivadorComprobante.php` con el mapeo **por condición de IVA** (`IVA Responsable Inscripto` → A; `Monotributo`, `IVA Exento`, `Consumidor Final` → B) y B por defecto (FR-039, FR-040, research §R8)
- [x] T049a [US3] Implementar los **dos llamados** que requiere el dato fiscal: `GET /orders/{id}` para obtener `buyer.billing_info.id`, y luego `GET /orders/billing-info/{SITE_ID}/{ID}` (contracts §5)
- [x] T049b [US3] Implementar el fallback por tipo de documento (CUIT → A, DNI/CUIL → B) **sólo** cuando falte la condición de IVA, marcándolo como derivación aproximada (FR-040c)
- [x] T050 [US3] Crear `app/Services/MercadoLibre/ResolutorCliente.php` con emparejamiento **primero por identificador de Mercado Libre** y luego por `apodo_ml`, alta automática, y el caso ambiguo como bloqueo (FR-036, FR-036a, FR-037, FR-038, research §R12)
- [x] T051 [US3] Implementar en `ResolutorCliente` la regla de no sobrescribir datos fiscales ya cargados en el Cliente, y la persistencia explícita de "Consumidor Final" cuando se asume por falta de datos (FR-041, FR-041a, FR-040a)
- [x] T051a [US3] Verificar que el tipo de comprobante es editable en la Venta creada, tanto tras conversión manual como automática, y agregar el test correspondiente (FR-043)
- [x] T052 [US3] Crear `app/Services/MercadoLibre/ConversorOrdenAVenta.php` con la resolución de precondiciones previa a la transacción: producto vinculado, cliente inequívoco, ausencia de variantes, moneda válida (FR-052, FR-030d)
- [x] T053 [US3] Implementar en `ConversorOrdenAVenta` la **desagregación de IVA desde el precio final**, con tasa cero para Exento/No Gravado y absorción del redondeo en la última línea (FR-030a, FR-030b, research §R7)
- [x] T054 [US3] Implementar la creación atómica de Venta, ítems, cobranza y stock en una única transacción, reutilizando `CalculoComprobante`, `Cobranzas::registrarCobro()` y `StockDeVenta` (FR-044, FR-045, FR-046, FR-048)
- [x] T055 [US3] Implementar el **candado por orden** y la revalidación bajo candado, respaldados por el índice único de `venta_id` (FR-032, FR-032a, FR-032b, research §R6)
- [x] T056 [US3] Implementar el corte por cuenta de Tesorería de Mercado Pago inexistente o inactiva (FR-045a)
- [x] T057 [US3] Implementar la creación de la Venta sin descuento general ni conceptos extra, y con `origen = mercadolibre` (FR-030c, FR-035)
- [x] T057a [US3] Exponer el origen en la columna y el filtro **"Creada Desde"** ya existentes del listado de Ventas, agregando "MercadoLibre" como tercer valor — **sin** crear columna ni filtro nuevos (FR-035a, fidelidad estructural a Contagram)
- [ ] T057b [US3] **PENDIENTE — requiere la cuenta real conectada**: verificar empíricamente que `unit_price` de una orden real coincide con el precio publicado en la web de Mercado Libre, confirmando el supuesto de IVA incluido antes de dar por buena la desagregación (FR-030a, Assumptions)
- [x] T058 [US3] Agregar al controlador las acciones `convertir` (formulario precargado) y `convertirGuardar` según contracts §1
- [x] T059 [US3] Crear la vista del formulario de conversión en `resources/views/ingresos/mercadolibre/convertir.blade.php`, reutilizando el formulario de página completa de Nueva Venta con los datos precargados (FR-028, FR-029)
- [x] T060 [US3] Implementar la vinculación inline desde el formulario cuando una línea no tiene producto (FR-023)
- [x] T061 [US3] Implementar el menú de fila con "Crear Venta" habilitado sólo en estado "Lista para convertir", con motivo visible en el resto (FR-031, FR-059)
- [x] T062 [US3] Implementar el acceso directo bidireccional entre la orden y la Venta creada (FR-033)
- [x] T063 [P] [US3] Test de importes en `tests/Feature/Integraciones/MercadoLibreImportesTest.php`: **el total de la Venta coincide exactamente con el monto de la orden**, incluidos IVA 21%, 10,5%, Exento y casos con redondeo (FR-030, FR-030a, FR-030b, SC-003)
- [x] T064 [P] [US3] Test de derivación del comprobante con los valores reales de Mercado Libre: `IVA Responsable Inscripto` → A · **`Monotributo` → B (con CUIT, el caso que la regla de Mercado Libre resolvería mal)** · `IVA Exento` → B · `Consumidor Final` → B · sin dato → B + condición persistida (FR-040, FR-040a, FR-040b, SC-010)
- [x] T064a [P] [US3] Test del bloqueo por alerta de fraude: una orden con ese marcador no se convierte ni manual ni automáticamente, y el aviso de no despachar es visible (FR-052a)
- [x] T065 [P] [US3] Test de idempotencia: reintentar la conversión de una orden ya convertida no duplica nada (FR-032, SC-004)
- [x] T066 [US3] **Test de concurrencia** en `tests/Feature/Integraciones/MercadoLibreConcurrenciaTest.php`: 10 conversiones simultáneas sobre la misma orden producen exactamente una Venta, una cobranza y un movimiento de stock (FR-032a, SC-004a)
- [x] T067 [P] [US3] Test de cobranza y stock: la Venta queda cobrada contra Mercado Pago y el stock baja en el depósito configurado (FR-044, FR-045, FR-046, SC-008, SC-009)
- [x] T068 [P] [US3] Test de resolución de Cliente: alta automática, reutilización por apodo y bloqueo por ambigüedad (FR-036, FR-037, FR-038)

---

## Phase 6 — US4: Configurar sincronización y comportamiento (P2)

**Objetivo**: dejar el módulo desatendido y configurable. **Test independiente**: cambiar frecuencia y
depósito y comprobar que persisten y se respetan.

- [x] T069 [US4] Agregar la acción de configuración de ventas al controlador de configuración de Mercado Libre existente, según contracts §3
- [x] T070 [US4] Crear el FormRequest de configuración validando frecuencia, depósito y categoría en `app/Http/Requests/Integraciones/`
- [x] T071 [US4] Extender la vista `resources/views/configuracion/mercadolibre/` con la sección de ventas: interruptor de creación automática, frecuencia, depósito y categoría, por AJAX con Toastr (FR-010, FR-047, FR-050)
- [x] T072 [US4] Mostrar de forma permanente la **advertencia de sobreventa** y la de conciliación bruta de Mercado Pago (FR-060, FR-049a)
- [x] T073 [US4] Crear `app/Console/Commands/SincronizarOrdenesMercadoLibre.php` con la opción `--forzar` y los códigos de salida de contracts §4
- [x] T074 [US4] Registrar la tarea con evaluación por minuto y decisión por frecuencia configurada en `routes/console.php` o `bootstrap/app.php`, sin depender de procesos permanentes (FR-010, FR-011, research §R5)
- [x] T075 [P] [US4] Test en `tests/Feature/Integraciones/MercadoLibreProgramacionTest.php`: la frecuencia configurada se respeta, `--forzar` la ignora pero no los bloqueos, y dos corridas simultáneas no se solapan (FR-010, FR-014)

---

## Phase 7 — US5: Creación automática de ventas (P2)

**Objetivo**: convertir sin intervención. **Test independiente**: activar el interruptor, sincronizar y
ver la Venta creada sola.

- [x] T076 [US5] Integrar `ConversorOrdenAVenta` en `SincronizadorOrdenes` para las órdenes aptas cuando la creación automática está activa (FR-051)
- [x] T077 [US5] Implementar el marcado como "Requiere atención" con el motivo concreto y **sin ningún efecto colateral** cuando la orden no es resoluble (FR-052)
- [x] T078 [US5] Implementar la re-elegibilidad: una orden bloqueada vuelve a "Lista para convertir" en cuanto se resuelve el motivo (FR-053)
- [x] T079 [US5] Registrar el origen automático y la marca temporal en la Venta creada (FR-054)
- [x] T080 [US5] Implementar el manejo de fallo durante la creación automática: motivo persistido, error registrado, **sin Venta parcial** (FR-055)
- [x] T081 [P] [US5] Test en `tests/Feature/Integraciones/MercadoLibreCreacionAutomaticaTest.php`: orden resoluble se convierte sola; orden sin vincular NO crea Venta ni mueve stock; resolver el motivo la vuelve convertible; interruptor apagado no crea nada (FR-051, FR-052, FR-053, FR-056, SC-005)
- [x] T082 [P] [US5] Test de fallo parcial: un error a mitad de la conversión no deja Venta, cobranza ni movimiento de stock huérfanos (FR-055, FR-048)

---

## Phase 8 — US6: Cancelaciones y reembolsos posteriores (P3)

**Objetivo**: reflejar cambios de estado sin tocar la Venta. **Test independiente**: cancelar una orden
ya convertida y ver que el listado lo refleja y la Venta sigue intacta.

- [x] T083 [US6] Implementar en `SincronizadorOrdenes` la actualización de estado de órdenes ya conocidas, incluidas cancelaciones y reembolsos (FR-057)
- [x] T084 [US6] Garantizar que una orden convertida que se cancela **no** modifica la Venta ni revierte el stock, y se señala de forma destacada (FR-058, FR-046e)
- [x] T085 [US6] Deshabilitar la conversión de órdenes canceladas antes de convertirse (FR-059)
- [x] T086 [P] [US6] Test en `tests/Feature/Integraciones/MercadoLibreCancelacionesTest.php` cubriendo los tres escenarios de aceptación de US6, **incluida la verificación de que la segunda pasada de canceladas efectivamente las trae** (FR-012a)

---

## Phase 9 — Polish y transversales

- [x] T087 [P] Verificar que ningún dato sensible llega al historial de operaciones ni a `ml_ordenes.payload` (FR-034 de la spec 011, SC-007)
- [x] T088 [P] Revisar que todas las operaciones de las pantallas nuevas ocurren sin recarga de página (SC-012)
- [x] T089 [P] Verificar la portabilidad ejecutando la suite con el almacén de caché de archivos y con el de base de datos (FR-011, SC-013)
- [x] T090 [P] Actualizar `CREDENCIALES_ACCESO.txt` si alguna prueba manual cambió un acceso (regla de `CLAUDE.md`) — no aplica: ninguna prueba de esta implementación cambió accesos (todo se probó con factories/fakes automatizados, sin credenciales reales)
- [ ] T091 **PENDIENTE — requiere la cuenta real conectada y un navegador**: recorrer `quickstart.md` de punta a punta, incluido el escenario 7 de regresión de stock
- [x] T092 [P] Actualizar `MERCADOLIBRE_NOTAS_TECNICAS.md` con los hallazgos reales de la API obtenidos en T024

---

## Cobertura heredada (requisitos sin tarea propia, por diseño)

Estos requisitos **no** tienen tarea porque ya los satisface infraestructura existente y verificada de
la spec 011. Duplicarlos sería reimplementar lógica crítica ya probada (research §R10).

| Requisito | Quién lo cubre | Verificación |
|---|---|---|
| FR-019 — registrar toda operación en el historial | `ClienteMercadoLibre::registrarLog()` — se dispara en cada petición | T087 |
| FR-020 — espera creciente ante exceso de solicitudes y reintentos acotados | `ClienteMercadoLibre::ejecutarConReintentos()` — ya maneja 429, 5xx y `Retry-After` | T039 |
| FR-042 — no consultar servicios fiscales externos | Requisito negativo: se cumple no construyendo esa integración | Revisión en T049 |
| FR-061 — sin purga automática de órdenes | Requisito negativo: no se construye ningún proceso de purga | Revisión en T011 |

## Dependencias entre historias

```
Setup (T001-T002)
   ↓
Foundational (T003-T026)  ← 2.a cierre de stock BLOQUEA a US3
   ↓
US1 (T027-T040)  ─── MVP: ya entrega valor solo
   ↓
US2 (T041-T048)  ← requiere US1 (necesita órdenes para vincular)
   ↓
US3 (T049-T068)  ← requiere US2 (necesita vinculaciones) + 2.a (stock)
   ↓
US4 (T069-T075)  ← requiere US1
   ↓
US5 (T076-T082)  ← requiere US3 + US4 (reutiliza el conversor y el interruptor)
   ↓
US6 (T083-T086)  ← requiere US1
   ↓
Polish (T087-T092)
```

**US4 y US6 pueden desarrollarse en paralelo con US2/US3** — sólo dependen de US1.

## Oportunidades de paralelización

- **Fase 2**: T011-T015 (migraciones), T016-T021 (enums y modelos) y T009-T010 son todos `[P]`.
- **Tests**: casi todos los tests son `[P]` entre sí — distinto archivo, sin estado compartido.
- **Fases 6 y 8** pueden avanzar en paralelo a las fases 4 y 5.

## Estrategia de implementación

**MVP sugerido**: Fases 1-3 (hasta T040). Entrega una pantalla que muestra las ventas reales de Mercado
Libre dentro del CRM — valor inmediato, sin tocar Ventas ni stock.

**Primer incremento utilizable en producción**: Fases 1-5 (hasta T068). Permite convertir órdenes a
Ventas a mano, con importes, cobranza y stock correctos.

**Orden recomendado**: no saltear la fase 2.a. Es el cambio de mayor riesgo (toca el módulo Ventas ya
en uso) y todo lo demás se apoya en él; hacerlo al final obligaría a revalidar las fases posteriores.

⚠️ **Al terminar**: encadenar la **spec 013** (sincronización de stock hacia Mercado Libre). Hasta que
exista, el riesgo de sobreventa descrito en FR-060 sigue abierto.
