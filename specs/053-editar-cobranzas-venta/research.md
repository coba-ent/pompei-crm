# Research: Editar cobranzas de una venta

## 1. Actualización in-place del MovimientoTesoreria

**Decision**: `actualizarCobro()` actualiza el `Cobro` y, si existe, hace `update()` directo
sobre su `movimientoTesoreria` (monto, cuenta, fecha), dentro de la misma transacción.

**Rationale**: `CuentaTesoreria::saldoA()` (`app/Models/CuentaTesoreria.php:64-69`) calcula el
saldo con `SUM(monto)` on-the-fly sobre `movimientos()`, sin columna de saldo cacheada. No hay
ningún otro lugar del sistema que dependa de que un movimiento nunca cambie sus valores después
de creado, así que actualizarlo in-place es seguro y no requiere recalcular nada aparte. Ya se
decidió con el usuario (no anular+recrear) para no dejar rastro de un movimiento "fantasma" por
cada corrección.

**Alternatives considered**: Anular + recrear (patrón ya usado en `anularCobro`) — descartado
por decisión explícita del usuario: generaría dos filas de movimiento por cada corrección y
complica la lectura del extracto de cuenta para algo que es simplemente un typo corregido.

## 2. Validación del monto máximo en edición

**Decision**: Nuevo `UpdateCobroRequest` con regla `lte: max($aCobrar + $cobroActual->monto, 0)`,
donde `$aCobrar` es `$venta->aCobrar()` (saldo pendiente actual, que ya excluye el cobro que se
está editando porque `aCobrar()` se calcula sobre cobros activos incluyendo el actual con su
valor viejo — hay que sumarle el monto actual del cobro para obtener el "techo" real disponible).

**Rationale**: Es la misma regla de negocio que en el alta (`StoreCobroRequest`), extendida
para permitir que el propio cobro editado "libere" su monto actual antes de aplicar el nuevo.
Sin este ajuste, cualquier intento de mantener el mismo monto (o subirlo levemente) sería
rechazado porque el sistema ya lo cuenta como "cobrado".

**Alternatives considered**: Restar el cobro de la venta antes de validar y volver a sumarlo —
descartado por más complejo y con riesgo de estados intermedios inconsistentes dentro de la
misma request; el cálculo aritmético directo (`aCobrar + montoActual`) es más simple y no toca
la base de datos hasta el `update()` final.

## 3. Patrón de desplegable de acciones de fila

**Decision**: Nuevo partial `resources/views/ventas/_row_actions_cobranza.blade.php`, calcado
del marcado de `resources/views/ventas/_row_actions.blade.php` (`div.dropdown` +
`button.dropdown-toggle` + `ul.dropdown-menu.dropdown-menu-end` + `li > a.dropdown-item`), con
tres ítems: "Ver recibo" (`js-ver-recibo-cobranza`, ya existe), "Editar" (nuevo `js-editar-cobro`)
y "Eliminar" (`js-eliminar-cobro`, ya existe — mismo texto, sólo cambia de ícono suelto a ítem
de menú).

**Rationale**: Consistencia visual pedida explícitamente por el usuario ("como tienen todas las
tablas"); ya existe el patrón en 10+ tablas del CRM (`grep dropdown-menu` en
`resources/views/**/_row_actions*.blade.php`), así que no se inventa un componente nuevo.

**Alternatives considered**: Mantener los íconos sueltos y sólo agregar un tercer ícono de
"editar" — descartado porque el usuario pidió explícitamente el desplegable.

## 4. Reutilización del modal de alta en modo edición

**Decision**: `_modal_cobranza.blade.php` gana un campo oculto `#cobranza-id` (vacío en modo
alta) y un campo de nota visible (hoy no existe en el modal aunque el modelo lo soporta); en
`abrirCobranza(cobro = null)` de `ventas.js`, si se pasa un `cobro` existente se precargan sus
valores y el submit apunta a `PUT {venta}/cobranzas/{cobro}` en lugar de `POST {venta}/cobranzas`.

**Rationale**: Evita duplicar el modal completo (misma estructura de campos: monto, fecha,
selector de cuenta); es el patrón que ya usa el resto del CRM para modales de alta/edición
compartidos vía Select2 + Bootstrap modal + AJAX (ver `resources/js/productos.js` como
referencia citada en CLAUDE.md).

**Alternatives considered**: Modal separado `_modal_cobranza_editar.blade.php` — descartado por
duplicar markup y lógica de Select2/validación que ya vive en el modal de alta.
