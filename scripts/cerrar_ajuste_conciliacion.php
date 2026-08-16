<?php
/**
 * Crea la contrapartida que cancela el ajuste de conciliación desde el 16/08/2026.
 *
 *     php scripts/cerrar_ajuste_conciliacion.php            # simulacion
 *     php scripts/cerrar_ajuste_conciliacion.php --aplicar
 *
 * ## Por que
 *
 * El 15/08/2026 se creo el proveedor ficticio "AJUSTE CONCILIACION CONTAGRAM" con
 * `saldo_inicial = -4650.00` al 13/09/2021, para tapar un desfasaje contra el panel de
 * Contagram cuya causa nunca se encontro. Se asumio que el desfasaje era constante hasta hoy.
 *
 * **Esa premisa era falsa.** Verificado el 16/08/2026 contra el panel de Contagram:
 *
 * - 2021, 2022, 2023, 2024, 2025 y cada mes y cada dia de 2026 hasta el 15/08: el ajuste hace
 *   falta, las cifras coinciden con el.
 * - El panel **sin** fecha de corte —el saldo de hoy— da 4.650 mas, y ahi coincide con el CRM
 *   **sin** ajuste (25.726.465,26 contra 25.726.465,10).
 * - La lista de Movimientos de Contagram tambien da 4.650 mas: al 12, 13 y 14/08 coincide con
 *   el CRM sin ajuste a 5 centavos.
 *
 * O sea que el desfasaje aparece solo cuando se filtra por fecha, y desde el 16/08/2026 el saldo
 * corriente ya no lo tiene.
 *
 * ## Por que un segundo proveedor y no un cambio en el primero
 *
 * Un `saldo_inicial` arranca en una fecha y sigue para siempre: no tiene forma de terminar. La
 * unica manera de cortarlo sin perder los cortes historicos es una segunda entidad que lo cancele
 * desde la fecha de cierre. `CuentaCorriente::entidadesConSaldoInicial()` ya filtra por
 * `saldo_inicial_fecha <= corte`, asi que antes del 16/08 esta entidad no existe para el calculo.
 *
 * ## Que NO toca
 *
 * Inserta una fila en `proveedores` y nada mas. No crea compras, ni pagos, ni movimientos de
 * tesoreria, ni toca stock. Queda oculta de las vistas por `Proveedor::scopeVisibles()`.
 */
$soloVer = !in_array('--aplicar', $argv, true);

$env = [];
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
    if ($linea[0] === '#' || !str_contains($linea, '=')) { continue; }
    [$clave, $valor] = explode('=', $linea, 2);
    $env[trim($clave)] = trim(trim($valor), "\"'");
}

$db = $env['DB_DATABASE'] ?? 'contagram';
$pdo = new PDO(
    'mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').";dbname={$db};charset=utf8mb4",
    $env['DB_USERNAME'] ?? 'root',
    $env['DB_PASSWORD'] ?? ''
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$NOMBRE = 'AJUSTE CONCILIACION CONTAGRAM - CIERRE';
$FECHA = '2026-08-16';
$MONTO = 4650.00;

$nota = <<<TXT
AJUSTE DE CONCILIACION (CIERRE) - NO ES UN PROVEEDOR REAL

Que es: la contrapartida de "AJUSTE CONCILIACION CONTAGRAM". Aquel lleva
-4.650,00 desde el 13/09/2021; este lleva +4.650,00 desde el 16/08/2026, asi
que de esa fecha en adelante los dos suman cero.

Por que: el desfasaje de 4.650 contra Contagram existe SOLO en su panel con
filtro de fecha. Verificado el 16/08/2026: 2021, 2022, 2023, 2024, 2025 y cada
mes y dia de 2026 hasta el 15/08 piden el ajuste; pero el panel sin fecha (el
saldo de hoy) y la lista de Movimientos de Contagram dan 4.650 mas, y ahi
coinciden con el CRM sin ningun ajuste (12, 13 y 14/08 contrastados: 5 centavos
de diferencia).

Un saldo inicial no se puede "terminar" en una fecha, por eso hace falta esta
segunda entidad en vez de modificar la primera.

Si algun dia aparece la causa real del desfasaje, BORRAR LAS DOS, no una sola.
Las dos estan ocultas de listados, buscadores e informes por
Proveedor::scopeVisibles(), pero suman en el aging a proposito.
TXT;

$existe = $pdo->prepare('SELECT id, saldo_inicial, saldo_inicial_fecha FROM proveedores WHERE nombre = ?');
$existe->execute([$NOMBRE]);
$ya = $existe->fetch(PDO::FETCH_ASSOC);

echo "base   : {$db}\n";
echo "entidad: {$NOMBRE}\n";
echo 'estado : '.($ya ? "YA EXISTE (id {$ya['id']}, {$ya['saldo_inicial']} al {$ya['saldo_inicial_fecha']})" : 'no existe, se crea')."\n";
echo "saldo  : +{$MONTO} al {$FECHA}\n";

if ($ya) {
    echo "\nNada que hacer.\n";
    exit;
}

if ($soloVer) {
    echo "\n[SIMULACION] no se escribio nada. Agregar --aplicar para ejecutar.\n";
    exit;
}

$sql = $pdo->prepare('
    INSERT INTO proveedores (nombre, razon_social, nota, nota_interna, tipo_documento,
                             saldo_inicial, saldo_inicial_fecha, activo, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
');
$sql->execute([$NOMBRE, 'AJUSTE DE CONCILIACION (CIERRE) - NO ES UN PROVEEDOR REAL',
    $nota, $nota, 'CUIT', $MONTO, $FECHA]);

$id = $pdo->lastInsertId();
echo "\nOK. creado con id {$id}\n";
echo "ROLLBACK: DELETE FROM proveedores WHERE id = {$id};\n";
