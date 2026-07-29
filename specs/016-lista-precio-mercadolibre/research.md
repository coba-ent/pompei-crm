# Research: Lista de Precios en la configuración de Mercado Libre

## R1 — Comportamiento del FK ante borrado: `nullOnDelete()`, igual que `deposito_id`/`categoria_venta_id`

**Pregunta**: ¿qué debe pasar con `ml_configuracion.lista_precio_id` si el usuario borra la Lista de
Precios que tenía configurada para Mercado Libre?

**Decisión**: `$table->foreignId('lista_precio_id')->nullable()->constrained('listas_precio')->nullOnDelete();`
— idéntico al tratamiento que ya reciben `deposito_id` y `categoria_venta_id` en la misma tabla
(`database/migrations/2026_08_02_060004_add_ventas_fields_to_ml_configuracion_table.php`).

**Rationale**: es el mismo tipo de campo (FK opcional de clasificación) que los dos ya existentes en
`ml_configuracion`, con el mismo requisito: si la fila referenciada desaparece, la configuración no debe
romperse ni bloquear ninguna pantalla — simplemente vuelve a quedar "sin Lista de Precios configurada",
el mismo estado que ya maneja FR-004 del spec. Usar otro comportamiento (`restrict`, por ejemplo)
introduciría una inconsistencia arbitraria entre los tres campos de configuración de Mercado Libre sin
ningún motivo de negocio que la justifique.

**Alternativas consideradas**:
- *`restrictOnDelete()`*: rechazado — impediría borrar una Lista de Precios sólo porque quedó
  configurada (posiblemente hace tiempo) en Mercado Libre, una dependencia no evidente para quien intenta
  borrarla desde la pantalla de Listas de Precios.
- *Validar "activa" en cada conversión y bloquear si no lo está*: rechazado, ya descartado explícitamente
  en el spec (Edge Cases) por el mismo motivo que `categoria_venta_id` no lo hace hoy — agregaría una
  validación que el resto del módulo no tiene, sin que el usuario la haya pedido.

## R2 — Sin necesidad de `ListaPrecio::porDefecto()`

**Pregunta**: ¿hace falta un método de fallback análogo a `Deposito::porDefecto()` para cuando
`lista_precio_id` no está configurado?

**Decisión**: no. Se usa el valor tal cual (`null` si no hay configuración), sin ningún método de
fallback nuevo.

**Rationale**: `Deposito::porDefecto()` existe porque el depósito es **indispensable** para que
`StockDeVenta` sepa de dónde descontar (spec 013, `Deposito.php` línea 24-38: "es el depósito del que
descuentan las Ventas"). La Lista de Precios no tiene ese rol — es metadata de clasificación que ya es
opcional en Ventas/Presupuestos manuales (spec 008) sin que exista o se necesite un concepto de "lista
por defecto del CRM". Inventar uno acá sería alcance no pedido y, peor, crearía dos conceptos de
"default" con semántica distinta bajo el mismo nombre (uno indispensable, otro no) — confuso para
cualquier lectura futura del código. Ya resuelto explícitamente en el spec (Clarifications, Q2).

## R3 — Punto de wiring: una clave más en el array ya existente de `Venta::create()`

**Pregunta**: ¿dónde se agrega la asignación de `lista_precio_id` a la Venta convertida?

**Decisión**: una clave más en el array de `Venta::create()` dentro de `ConversorOrdenAVenta::convertir()`
(`app/Services/MercadoLibre/ConversorOrdenAVenta.php`, línea ~149-161), en el mismo lugar donde ya se
asigna `'categoria_id' => MercadoLibreConfiguracion::actual()->categoria_venta_id`.

**Rationale**: es exactamente el mismo tipo de dato (una FK de clasificación leída de la configuración
vigente al momento de crear la Venta), así que va en el mismo punto, con el mismo patrón de lectura
(`MercadoLibreConfiguracion::actual()`). No hay razón para un método o servicio separado — sería una
capa extra para una sola línea de código.

**Alternativas consideradas**:
- *Un método `MercadoLibreConfiguracion::listaPrecioEfectiva()` análogo a `depositoEfectivo()`*:
  rechazado — ese método existe en `depositoEfectivo()` porque resuelve un fallback (R2) y devuelve un
  objeto `Deposito` para mostrarlo en pantalla ("Mercado Libre publica el stock de X"). Acá no hay
  fallback que resolver ni necesidad de mostrar "la lista efectiva" en ningún lado: el propio spec
  (Edge Cases) aclara que no hace falta mostrar nada adicional más allá del selector de configuración
  ya existente.
