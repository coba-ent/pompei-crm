<?php
/**
 * Reconstruye los items de las notas de credito que ANULAN LA VENTA ENTERA
 * (monto == total de la venta), copiandolos de venta_items.
 *
 * NO toca stock: escribe unicamente en nota_credito_debito_items, con PDO crudo
 * (sin Eloquent, sin observers). No hay observer para NotaCreditoDebito.
 */
$soloVer = !in_array('--aplicar', $argv, true);

// Credenciales del .env del proyecto, no hardcodeadas: local y produccion no usan
// el mismo usuario de MySQL. El primer argumento puede pisar la base a la que apunta.
$env = [];
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
    if ($linea[0] === '#' || !str_contains($linea, '=')) { continue; }
    [$clave, $valor] = explode('=', $linea, 2);
    $env[trim($clave)] = trim(trim($valor), "\"'");
}

$db = $argv[1] ?? ($env['DB_DATABASE'] ?? 'contagram');
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';

$p = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '');
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$SELECCION = "
  FROM notas_credito_debito n
  JOIN ventas v ON v.id = n.venta_id
  WHERE n.deleted_at IS NULL
    AND v.deleted_at IS NULL
    AND n.tipo = 'credito'                     -- solo NC: una ND que iguala el total no es una devolucion
    AND ABS(n.monto - v.total) < 0.02          -- anula la venta entera
    AND NOT EXISTS (SELECT 1 FROM nota_credito_debito_items i WHERE i.nota_credito_debito_id = n.id)
";

$notas = $p->query("SELECT COUNT(*) $SELECCION")->fetchColumn();
$items = $p->query("SELECT COUNT(*) FROM venta_items x WHERE x.venta_id IN (SELECT v.id $SELECCION)")->fetchColumn();
$maxAntes = $p->query("SELECT COALESCE(MAX(id),0) FROM nota_credito_debito_items")->fetchColumn();

echo "base: {$db}\n";
echo "notas a completar : {$notas}\n";
echo "items a insertar  : {$items}\n";
echo "max(id) actual    : {$maxAntes}\n";

if ($soloVer) { echo "\n[SIMULACION] no se escribio nada. Agregar --aplicar para ejecutar.\n"; exit; }

$p->beginTransaction();
$p->exec("
  INSERT INTO nota_credito_debito_items
    (nota_credito_debito_id, producto_id, cantidad, precio, descuento_pct, iva_pct, origen, created_at, updated_at)
  SELECT n.id, x.producto_id, x.cantidad, x.precio_unitario,
         COALESCE(x.descuento_pct, 0),
         CASE WHEN x.iva_pct REGEXP '^[0-9]+(\\.[0-9]+)?$' THEN CAST(x.iva_pct AS DECIMAL(5,2)) ELSE NULL END,
         'venta_original', NOW(), NOW()
  FROM notas_credito_debito n
  JOIN ventas v ON v.id = n.venta_id
  JOIN venta_items x ON x.venta_id = v.id
  WHERE n.deleted_at IS NULL AND v.deleted_at IS NULL AND n.tipo = 'credito'
    AND ABS(n.monto - v.total) < 0.02
    AND NOT EXISTS (SELECT 1 FROM nota_credito_debito_items i WHERE i.nota_credito_debito_id = n.id)
");
$p->commit();

$maxDespues = $p->query("SELECT COALESCE(MAX(id),0) FROM nota_credito_debito_items")->fetchColumn();
$insertados = $p->query("SELECT COUNT(*) FROM nota_credito_debito_items WHERE id > {$maxAntes}")->fetchColumn();

echo "\nOK. insertados: {$insertados}  (ids {$maxAntes}..{$maxDespues})\n";
echo "ROLLBACK: DELETE FROM nota_credito_debito_items WHERE id > {$maxAntes};\n";
