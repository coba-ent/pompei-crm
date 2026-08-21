# Quickstart: validar el saldo a favor aplicable

**Spec**: [spec.md](./spec.md) · **Contrato**: [contracts/aplicaciones-credito-api.md](./contracts/aplicaciones-credito-api.md)

Guía de validación end-to-end. Reproduce el caso real que motivó la feature.

## Prerrequisitos

- Base local (`contagram_migracion` en XAMPP), **nunca** producción.
- Servidor local: `php artisan serve --port=8010` (el 8000 lo ocupa otro proyecto).
- Login: `admin@contagram.local` / `password` (ver `CREDENCIALES_ACCESO.txt`).
- Assets compilados: `npm run build`.

## Escenario 1 — El caso Florencia (P1)

Es el escenario que hoy pierde $3.465,29.

1. Crear una venta para un cliente de prueba por **$30.771,29** y cobrarla completa (Visa a Cobrar).
2. Emitir sobre ella una Nota de Crédito por **$30.771,29** (la devolución). **No borrar la cobranza.**
3. Verificar que la venta queda con **A Cobrar = −$30.771,29** y que el cliente figura con ese saldo
   a favor en el selector de Nueva Venta.
4. Crear una venta nueva del mismo cliente por **$27.306**.
5. En "Agregar Cobranza", elegir el medio **"Saldo a favor"**. Debe mostrar $30.771,29 disponibles y
   pre-cargar $27.306.
6. Guardar.

**Resultado esperado**:

| | Valor |
|---|---|
| Venta nueva → A Cobrar | $0,00 (estado "Cobrada") |
| Venta original → A Cobrar | −$3.465,29 |
| Saldo del cliente en Cta Cte | −$3.465,29 (igual que antes de aplicar) |
| Crédito disponible restante | $3.465,29 |

**El chequeo que importa**: el saldo del cliente debe ser **el mismo antes y después** de aplicar.
Si después de aplicar da −$30.771,29, hay doble conteo (falta el término `creditoCedido`).

## Escenario 2 — Tesorería intacta (SC-003, FR-017/018/019)

Antes y después del paso 6 del escenario anterior, comparar:

```bash
php artisan tinker --execute="
\$t=app(App\Services\Tesoreria\Tesoreria::class); \$s=\$t->saldos();
foreach(\$s as \$k=>\$v){ if(is_array(\$v)&&isset(\$v['total'])) echo \$k.' = '.number_format(\$v['total'],2).PHP_EOL; elseif(is_numeric(\$v)) echo \$k.' = '.number_format(\$v,2).PHP_EOL; }
\$cc=app(App\Services\Tesoreria\CuentaCorriente::class);
echo 'aging_clientes = '.number_format(\$cc->aging('cliente')['total'],2).PHP_EOL;
echo 'aging_proveedores = '.number_format(\$cc->aging('proveedor')['total'],2).PHP_EOL;
echo 'movimientos_suma = '.number_format((float)DB::table('movimientos_tesoreria')->whereNull('deleted_at')->sum('monto'),2).PHP_EOL;
echo 'movimientos_filas = '.DB::table('movimientos_tesoreria')->whereNull('deleted_at')->count().PHP_EOL;
"
```

**Las siete líneas deben dar idénticas.** Cualquier diferencia es un fallo bloqueante: significa que
la aplicación de crédito tocó el dinero.

## Escenario 3 — Sin crédito, la pantalla no cambia (FR-006)

Con un cliente sin saldo a favor, abrir "Agregar Cobranza": el medio "Saldo a favor" **no** debe
aparecer. La pantalla tiene que verse exactamente como hoy.

## Escenario 4 — Topes (FR-007)

- Intentar aplicar más que el saldo del comprobante → 422 "El monto supera el saldo a cobrar."
- Intentar aplicar más que el crédito disponible → 422 "El monto supera el saldo a favor disponible…"

## Escenario 5 — Anulación (FR-011, FR-012)

1. Anular la aplicación del escenario 1 → la venta nueva vuelve a $27.306 pendientes y el crédito
   disponible vuelve a $30.771,29.
2. Intentar eliminar la Nota de Crédito con una aplicación viva → 422 con el mensaje del contrato.

## Escenario 6 — Compras (P3)

Repetir el escenario 1 del lado de Compras: NC de compra sobre una compra pagada, y aplicar el
crédito a una compra posterior del mismo proveedor.

## Escenario 7 — Crédito fantasma (Decisión 2 de research)

Sobre una venta **impaga** emitir una NC que la cancele entera. El crédito disponible debe ser
**$0**, no el monto de la NC: la nota sólo canceló deuda, el cliente nunca puso plata.

Este es el estado real de la venta 24582 en producción hoy (le borraron la cobranza), así que sirve
de control contra el error de medir el crédito por el monto nominal de la nota.

## Tests automatizados esperados

Por el principio IV de la constitución (testing donde hay dinero), como mínimo:

- Cálculo de crédito disponible, incluido el caso "NC sobre comprobante impago = 0".
- Transferencia de saldo: el neto del cliente no cambia al aplicar.
- Topes de monto (comprobante y crédito).
- Anulación devuelve el crédito.
- Bloqueo de borrado de NC con aplicaciones.
- **Tesorería intacta**: sin movimientos nuevos ni cambios de saldo tras aplicar.
- Concurrencia: dos aplicaciones simultáneas no dejan el disponible negativo.
