# Research: Envío Manual a ARCA para NC/ND, con IVA real por línea

## R1 — Patrón de envío manual a reutilizar (spec 040)

**Decision**: replicar la estructura de spec 040 (Venta) para NC/ND, con modales **propios** (Clarifications,
Q1): acción por fila/detalle → `POST` de confirmación → `EmisorComprobante::emitir()` → modal de resultado
persistente o toast según sea respuesta real de ARCA o rechazo de precondición.

**Rationale**: spec 040 ya es el patrón validado por el dueño del negocio contra Contagram real y evitó un
incidente productivo real (envío automático no controlado). `FR-010` de spec 040 dejó expresamente NC/ND
fuera de su alcance — esta spec cierra ese pendiente con el mismo patrón, no uno nuevo.

**Alternatives considered**: reutilizar literalmente `#modal-confirmar-arca`/`#modal-resultado-arca` de
Venta parametrizando el texto — descartada en Clarifications por acoplar dos flujos con distinta condición
de elegibilidad (Venta valida su propio estado; NC/ND además depende del estado del comprobante original).

## R2 — Dónde vive hoy el trigger automático a eliminar

**Decision**: el trigger vive en `NotaCreditoDebitoController::store()` (líneas ~189-193) y
`storeCompra()` (equivalente, más abajo en el mismo controlador), que llaman a
`emitirComprobanteFiscalNota()` inmediatamente después de crear la nota, sólo si
`$venta->comprobanteFiscal` (o `$compra->comprobanteFiscal`) está `aprobado()`.

**Rationale**: confirmado leyendo el controlador — `emitirComprobanteFiscalNota()` privado, ya
parametrizado por `$nota`/`$venta`/`$comprobanteVenta`, es el punto único de armado del payload y llamada a
`EmisorComprobante::emitir()`. Se puede extraer tal cual a un método público/acción HTTP nueva sin
reescribir su lógica interna (cumple FR-005 / Constitution Principio III: no se toca la lógica de
`EmisorComprobante`).

**Alternatives considered**: ninguna — es un hallazgo de código, no una decisión de diseño.

## R3 — Fuente de datos para el IVA real por línea

**Decision**: usar directamente los campos ya persistidos en cada `NotaCreditoDebitoItem`
(`cantidad`, `precio`, `descuento_pct`, `iva_pct`) — **no** es necesario ir a buscar el ítem de origen
(`ventaItem`/`compraItem`) para obtener neto/IVA, porque spec 096 ya los persiste propios en cada línea de
la nota al crearla (`atributosItemNota()`). `venta_item_id`/`compra_item_id` sirven para saber *si* la
línea tiene origen identificado (para el criterio de fallback, R4) — no para recalcular montos.

**Rationale**: leyendo `NotaCreditoDebitoItem` (`app/Models/NotaCreditoDebitoItem.php`) y el
`$fillable`/`$casts` — `iva_pct` y `precio` ya están en decimal por línea, listos para agrupar por
alícuota con el mismo criterio que `MapeadorComprobante::armarBloquesAlicIva()` ya usa para Venta/Compra
(agrupa por `iva_pct`, suma neto e iva por alícuota).

**Alternatives considered**: derivar el IVA desde `ventaItem`/`compraItem` en tiempo de envío —
descartado: más costoso (join adicional), y el dato de la nota puede diferir legítimamente del de la línea
original si la NC/ND ajustó una cantidad o precio distintos a los de la línea de venta.

## R4 — Criterio de fallback para notas con ítems mixtos o sin línea de origen

**Decision**: confirmado en Clarifications (Q2) — si **algún** ítem de la nota no tiene
`venta_item_id`/`compra_item_id`, toda la nota usa el bloque único de IVA (comportamiento actual,
`monto / 1.21` o el que resulte del ajuste de esta spec para el caso agregado — ver R5). Sólo cuando
**todos** los ítems de la nota tienen línea de origen se arma el desglose real por alícuota.

**Rationale**: mismo criterio dual ya usado por `AjustesPendientesNotaCreditoDebito::pendiente()` (spec
096) para no mezclar cálculo por línea con cálculo agregado dentro del mismo comprobante — consistencia
interna entre "cuánto queda pendiente de ajustar" y "cómo se calcula el IVA a enviar".

**Alternatives considered**: desglosar por alícuota sólo los ítems con línea de origen y agrupar aparte los
que no la tienen (bloque mixto) — descartado en Clarifications por mayor complejidad sin beneficio claro:
una nota con ítems mixtos es sólo posible en un escenario transicional (notas creadas antes de spec 096
editadas después), no un caso de uso primario.

## R5 — Qué hacer cuando el fallback aplica (nota sin ítems, o con ítems pero sin `iva_pct` utilizable)

**Decision**: mantener el criterio exacto que ya implementa
`MapeadorComprobante::armarBloquesAlicIva()` cuando `items` viene vacío: un único bloque con
`alicuota_iva_id` explícito (si se pasa) o `5` (21%) por defecto, calculado sobre `neto`/`iva` agregados
de la nota. No se reemplaza el cálculo `monto / 1.21` que hoy arma `emitirComprobanteFiscalNota()` para el
caso agregado — se lo sigue usando, pero **sólo** quedará activo cuando aplique el fallback de R4, no
siempre.

**Rationale**: FR-010 exige explícitamente no romper el caso sin datos de línea; reutilizar el mismo
cálculo ya en producción minimiza riesgo. El defecto que corrige esta spec no es "el 21% fijo está mal en
absoluto" — es "se usa 21% fijo **siempre**, incluso cuando hay datos reales para calcular mejor".

**Alternatives considered**: intentar inferir la alícuota real desde el comprobante original
(`Venta`/`Compra`) cuando la nota no tiene ítems — descartado: una NC/ND "global" (sin ítems, sólo un
monto y una descripción) no tiene por diseño una alícuota asociada a un producto puntual; forzar una
inferencia agregaría complejidad no pedida por la spec.

## R6 — UI: dónde vive la acción "Enviar a ARCA" para NC/ND

**Decision**: la acción vive en el **Detalle de Venta/Compra**, en la tabla de NC/ND de esa pantalla (no
existe un listado global de NC/ND separado — se listan dentro del Detalle de su comprobante original,
confirmado en `resources/views/ventas/detalle.blade.php`). Se agrega como una acción de fila junto a "Ver
Detalle"/"Imprimir" ya existentes para cada nota, protegida por el mismo permiso que ya protege esa sección
(`ventas.ver` / `compras.ver`, confirmado en `routes/web.php`).

**Rationale**: no existe un módulo de NC/ND aparte en el sidebar (confirmado por `CLAUDE.md`, 8 módulos
reales) — las notas ya viven exclusively dentro del Detalle de Venta/Compra, así que ahí es donde
estructuralmente corresponde también la acción de envío, siguiendo el principio rector de fidelidad
estructural del proyecto.

**Alternatives considered**: ninguna — no hay una pantalla alternativa de NC/ND en el CRM actual.

## R7 — Indicador de estado ARCA (US4)

**Decision**: para Venta, agregar al Detalle un badge/indicador que lea `Venta::comprobanteFiscal` (ya
existe, `morphOne` ordenado) — mismos 4 valores que ya usa el filtro del listado
(`sin_emitir`/`pendiente`/`aprobado`/`rechazado`). Para NC/ND, usar el mismo patrón con
`NotaCreditoDebito::comprobanteFiscal()`/`tieneCaeAprobado()` (ya existen, mismo `morphOne` ordenado) en la
fila de cada nota dentro del Detalle.

**Rationale**: no hace falta ninguna consulta ni campo nuevo — ambas relaciones ya están resueltas y
ordenadas correctamente (bug de spec conocido: un `morphOne` sin orden explícito devolvería el rechazo más
viejo en vez del aprobado — ver incidente documentado del 14/08/2026 en `documentacion_principal_crm.md`).
Sólo falta exponerlo en la vista.

**Alternatives considered**: ninguna — es la reutilización directa de una relación ya construida y
corregida.
