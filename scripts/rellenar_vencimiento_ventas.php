<?php
/**
 * Repone la fecha de vencimiento de las ventas que quedaron sin ella.
 *
 *     php scripts/rellenar_vencimiento_ventas.php            # simulacion
 *     php scripts/rellenar_vencimiento_ventas.php --aplicar
 *
 * ## Por que `vencimiento = emision`
 *
 * No es una suposicion: en el export "Ventas c/ cobro" de Contagram las 3.529 ventas de 2026
 * tienen vencimiento cargado, y en las 3.529 coincide con la emision. Sobre el total de la base,
 * 23.453 de 23.585 ventas con vencimiento lo tienen igual a su emision.
 *
 * ## Por que hace falta
 *
 * Entre el 06/08/2026 y el 15/08/2026 quedaron 200 ventas en `NULL` —parte importadas, parte
 * creadas por el CRM, que no seteaba el campo—. Una venta sin vencimiento **nunca figura como
 * vencida**: ni el KPI del listado ni el aging de la Cuenta Corriente la cuentan, porque los dos
 * tratan el `NULL` como "a vencer". La deuda no desaparece, pero se esconde en el cajon
 * equivocado. La causa de raiz la tapa el default de `Venta::booted()`.
 *
 * ## Que NO toca
 *
 * Un `UPDATE` de una sola columna con PDO crudo: sin Eloquent, sin observers, sin `updated_at`.
 * No mueve un peso —el saldo es el mismo, solo cambia de bucket— ni roza el stock.
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

$CONDICION = 'WHERE deleted_at IS NULL AND fecha_vto_cobro IS NULL';

$afectadas = (int) $pdo->query("SELECT COUNT(*) FROM ventas {$CONDICION}")->fetchColumn();
$rango = $pdo->query("SELECT MIN(fecha_emision) d, MAX(fecha_emision) h FROM ventas {$CONDICION}")->fetch(PDO::FETCH_ASSOC);

echo "base                 : {$db}\n";
echo "ventas sin vencimiento: {$afectadas}\n";
echo "rango de emision      : {$rango['d']} .. {$rango['h']}\n";

foreach ($pdo->query("SELECT COALESCE(origen,'(sin origen)') o, COUNT(*) n FROM ventas {$CONDICION} GROUP BY 1 ORDER BY n DESC") as $fila) {
    printf("  %-16s %s\n", $fila['o'], $fila['n']);
}

if ($soloVer) {
    echo "\n[SIMULACION] no se escribio nada. Agregar --aplicar para ejecutar.\n";
    exit;
}

// Se guardan los ids para poder revertir: sin esto el rollback tendria que adivinar cuales eran.
$ids = $pdo->query("SELECT id FROM ventas {$CONDICION}")->fetchAll(PDO::FETCH_COLUMN);
$archivo = __DIR__.'/../storage/app/rollback_vencimiento_'.date('Ymd_His').'.txt';
file_put_contents($archivo, implode(',', $ids));

$pdo->beginTransaction();
$pdo->exec("UPDATE ventas SET fecha_vto_cobro = fecha_emision {$CONDICION}");
$pdo->commit();

$quedan = (int) $pdo->query("SELECT COUNT(*) FROM ventas {$CONDICION}")->fetchColumn();

echo "\nOK. actualizadas: ".(count($ids))."   quedan sin vencimiento: {$quedan}\n";
echo "ids guardados en : {$archivo}\n";
echo "ROLLBACK: UPDATE ventas SET fecha_vto_cobro = NULL WHERE id IN (<contenido de ese archivo>);\n";
