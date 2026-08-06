# Research: Selector de Depósito en Ventas y Compras

## R1 — ¿Dónde vive hoy la resolución del depósito en cada movimiento de stock?

**Decision**: `Deposito::porDefecto()` (`app/Models/Deposito.php:35`, primer activo por `id`) es la
única fuente hoy. `StockDeVenta::resolverDeposito()` ya distingue origen (`mercadolibre`,
`tiendanube`, resto = manual) — para manual cae siempre en `depositoPorDefecto()`.
`StockDeCompra::depositoPorDefecto()` no distingue origen porque Compra no tiene orígenes de
integración. Este feature introduce una tercera fuente por encima del fallback: el `deposito_id`
persistido en la propia Venta/Compra.

**Rationale**: Reutilizar el punto de entrada único ya existente (`resolverDeposito`/
`depositoPorDefecto`) minimiza el blast radius — ML/Tiendanube no se tocan porque su rama de
`resolverDeposito()` no cambia; sólo cambia la rama "manual".

**Alternatives considered**: Resolver el depósito directamente en el Controller y pasarlo como
parámetro a los métodos de `StockDeVenta`/`StockDeCompra` — rechazado porque duplicaría la lógica de
fallback (configuración → `Deposito::porDefecto()`) en cada punto de llamada (alta, edición, baja) en
vez de centralizarla una sola vez leyendo `$venta->deposito_id`/`$compra->deposito_id` directamente
del modelo ya persistido.

## R2 — ¿Dónde persiste hoy la configuración de valores por defecto de Compras?

**Decision**: NO existe una tabla `configuracion_compras` separada. La spec 043 creó
`configuracion_ventas` (fila única) para Ventas/Presupuestos, y la migración
`2026_08_04_070001_add_presupuesto_compra_defaults_to_configuracion_ventas_table.php` ya la extendió
con `categoria_compra_id`, `tipo_comprobante_compra`, `dias_vto_pago_compra` para Compras — todo en la
misma tabla y la misma pantalla (tab "Ventas" de Configuración & Ajustes, sección "Compras" dentro de
esa pantalla). `CompraController@create` ya lee `ConfiguracionVentas::first()` para precargar sus
defaults (líneas 105-119).

**Rationale**: Corrección aplicada al spec tras descubrir esto durante planning (regla de
retroalimentación docs↔specs de CLAUDE.md) — el input original del usuario asumía una tabla
`configuracion_compras` nueva, pero el proyecto ya resolvió ese mismo problema para otros campos de
Compra reutilizando `configuracion_ventas`. Seguir ese patrón evita una tabla y un tab redundantes.

**Alternatives considered**: Crear `configuracion_compras` como tabla/tab separados tal como pedía el
enunciado original — rechazado por inconsistencia con el patrón ya establecido en el propio código
(spec 043/044) y porque no aporta valor: ambas configuraciones ya conviven en una sola pantalla.

## R3 — Patrón de alta/edición/baja de stock a extender

**Decision**: `StockDeVenta`/`StockDeCompra` (altas: `aplicarAlta`; ediciones: `reaplicarPorEdicion`;
bajas: `reintegrarPorEliminacion`, disparadas por `VentaObserver`/`CompraObserver` en el evento
`deleting`) ya soportan el ciclo completo "reintegrar lo anterior + aplicar lo nuevo" para cambios de
cantidad. Cambiar el depósito de una operación es estructuralmente el mismo problema (reintegrar
contra el depósito viejo, aplicar contra el nuevo) — no requiere un mecanismo nuevo, sólo que
`resolverDeposito`/`depositoPorDefecto` lean el `deposito_id` del registro en vez de recalcular
siempre el mismo depósito global.

**Rationale**: Consistencia con FR-005 del spec (mismo patrón que ya aplica hoy para cambios de
cantidad) y con el principio de no introducir abstracciones nuevas cuando la existente ya cubre el
caso.

**Alternatives considered**: Ninguna — el mecanismo de reintegro ya es correcto para este caso, sólo
cambia qué depósito se usa en cada paso.

## R4 — Registros históricos (Ventas/Compras previas al feature)

**Decision**: Las migraciones agregan `deposito_id` como columna **nullable** en `ventas` y `compras`
(sin backfill). Los registros existentes quedan con `deposito_id = null`. El código de la aplicación
(formularios de alta/edición, `resolverDeposito`) sólo trata con Ventas/Compras nuevas o editadas
desde este feature en adelante — no hay lectura de `deposito_id` histórico para movimientos ya
persistidos en `movimientos_stock` (esos ya tienen su propio `deposito_id` fijado en el momento en
que se generaron, y no se tocan).

**Rationale**: Cumple FR-013 ("cambio sólo hacia adelante") de la forma más simple: no inventa un
valor que el usuario nunca eligió. Si se completara con `Deposito::porDefecto()` vigente al migrar,
se estaría fabricando un dato que parece haber sido una elección del usuario cuando no lo fue.

**Alternatives considered**: Backfillear con `Deposito::porDefecto()` — rechazado porque el spec deja
la decisión abierta ("el plan técnico decidirá") y un `null` explícito es más honesto que inventar una
elección retroactiva; además es reversible (se puede backfillear después si hiciera falta) mientras
que lo inverso no.

## R5 — Reuso de UI (Select2 + catálogo de depósitos activos)

**Decision**: Ya existe el patrón `Deposito::activos()->orderBy('nombre')->get()` pasado a la vista en
`VentaController@index` (filtros) y `VentaController@show` (detalle, para NC/ND). Se reutiliza el
mismo query en `create()`/`edit()` de Venta y Compra. El markup de Select2 sigue el patrón ya usado en
`resources/js/productos.js` (referencia obligatoria del CLAUDE.md): `width:'100%'`, sin `ajax` (el
catálogo de depósitos es chico, no hace falta paginar server-side).

**Rationale**: Consistencia con la regla de diseño obligatoria #5 del CLAUDE.md y con la
implementación ya usada en el propio proyecto para selects de catálogos chicos.

**Alternatives considered**: `ajax` remoto tipo el de Productos — rechazado por sobre-ingeniería;
Depósitos es un catálogo acotado (spec 005 lo trata como tal, ABM simple sin paginación).

## R6 — N° de comprobante de Compra: de autogenerado a editable (User Story 3)

**Decision**: `Compra::siguienteNroComprobante()` (`app/Models/Compra.php:113-118`) deja de ser la
fuente final de `compras.nro_comprobante` y pasa a ser sólo el valor de precarga sugerido que
`CompraController@create` calcula y pasa a la vista (mismo patrón que `$defaults` ya usa para
Categoría/Tipo de Comprobante/Depósito de Compra). El formulario expone ese valor en un input de texto
editable; `StoreCompraRequest`/`UpdateCompraRequest` agregan `nro_comprobante =>
'required|string|max:20'` y `CompraController@store/update` usan `$datos['nro_comprobante']`
directamente en vez de volver a llamar a `siguienteNroComprobante()`.

**Rationale**: Reutiliza el mismo mecanismo de "cálculo de sugerido + campo editable" que ya se usa
para los defaults de Configuración & Ajustes (spec 043/044) — no es un patrón nuevo, es aplicarlo a un
campo que hoy se calcula pero nunca se expone. Cambiar el punto donde se decide el valor final (de
"siempre server-side, ciego" a "servidor sugiere, usuario confirma o edita, servidor persiste lo que
llega") es el cambio mínimo que resuelve el problema sin tocar el resto del flujo de Compra.

**Alternatives considered**: Mantener `nro_comprobante` autogenerado y agregar sólo
`punto_venta_proveedor`/`numero_comprobante_proveedor` (ya existentes) como los campos visibles del
"número real" — rechazado porque esos campos están gateados por la función avanzada "Facturación
Electrónica" y sólo alimentan `ComprobanteFiscal` cuando hay CAE; el usuario pidió explícitamente que
el número sea editable en el flujo normal de "Nueva Compra"/"Editar Compra", sin depender de esa
función avanzada.
