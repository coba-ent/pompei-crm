<?php
/**
 * Carga el detalle de items de las notas de credito/debito a partir del JSON que
 * produce `scripts/nc_items_desde_detallado.py` (export "Informe de Ventas
 * Detallado" de Contagram).
 *
 *     python scripts/nc_items_desde_detallado.py "public/imports/ventas detallado" /tmp/nc.json
 *     php scripts/importar_items_notas_credito.php /tmp/nc.json            # simulacion
 *     php scripts/importar_items_notas_credito.php /tmp/nc.json --aplicar
 *
 * Complementa a `migrar_items_notas_credito.php`, que reconstruye el detalle de
 * las notas que anulan la venta entera copiandolo de `venta_items`. Este es para
 * las **parciales**, donde ese atajo no sirve y hace falta el export.
 *
 * ## Que NO toca
 *
 * Escribe unicamente en `nota_credito_debito_items`, con PDO crudo (sin Eloquent,
 * sin observers — para NotaCreditoDebito no hay ninguno). No toca stock, ni el
 * `monto` de la nota, ni ningun total: las cajas y las cuentas corrientes salen
 * de la cabecera, no de estos items.
 *
 * ## Por que valida el importe antes de escribir
 *
 * Los items reconstruidos tienen que reproducir el `monto` de la nota. Si no lo
 * hacen, algo no cuadra (export de otro periodo, producto renombrado, alicuota
 * mal leida) y meterlos igual dejaria el informe mintiendo con cara de exacto.
 * Esas notas se saltean y se listan al final para mirarlas a mano.
 */
$archivo = $argv[1] ?? null;
$soloVer = !in_array('--aplicar', $argv, true);
$tolerancia = 0.02;

if (!$archivo || !is_file($archivo)) {
    exit("Uso: php scripts/importar_items_notas_credito.php <archivo.json> [--aplicar]\n");
}

// Credenciales del .env del proyecto: local y produccion no usan el mismo usuario.
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

$exportado = json_decode(file_get_contents($archivo), true);

// Notas de venta que todavia no tienen detalle, indexadas como las indexa el JSON.
$pendientes = [];
$consulta = $pdo->query("
    SELECT n.id, n.legacy_id, n.tipo, n.fecha_emision, n.monto
    FROM notas_credito_debito n
    WHERE n.deleted_at IS NULL
      AND n.venta_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM nota_credito_debito_items i WHERE i.nota_credito_debito_id = n.id)
");
foreach ($consulta as $nota) {
    if (preg_match('/-(?:NC|ND)-(\d+)$/', (string) $nota['legacy_id'], $m)) {
        $pendientes[$nota['tipo'].'-'.$m[1]] = $nota;
    }
}

// El "Codigo" del export es "<id de producto> <codigo real>", asi que el id sale del
// prefijo. Pero los productos recreados despues de la migracion cambiaron de id y ese
// prefijo ya no apunta a nada: para esos se cae al codigo real, que si se conservo.
$existentes = [];
foreach ($pdo->query('SELECT id FROM productos') as $fila) {
    $existentes[(int) $fila['id']] = true;
}

$porCodigo = [];
foreach ($pdo->query('SELECT id, codigo FROM productos WHERE codigo IS NOT NULL') as $fila) {
    $porCodigo[trim((string) $fila['codigo'])] ??= (int) $fila['id'];
}

$resolverProducto = function (?int $id, string $codigo) use ($existentes, $porCodigo): ?int {
    if ($id !== null && isset($existentes[$id])) {
        return $id;
    }
    $real = trim((string) preg_replace('/^\d+\s+/', '', $codigo));

    return $porCodigo[$real] ?? $porCodigo[trim($codigo)] ?? null;
};

$aInsertar = [];
$descuadradas = [];
$sinProducto = [];

foreach ($pendientes as $clave => $nota) {
    if (!isset($exportado[$clave])) {
        continue;
    }

    $items = $exportado[$clave];
    $reconstruido = 0.0;

    foreach ($items as $item) {
        $bruto = $item['cantidad'] * $item['precio'];
        $neto = $bruto - ($bruto * ($item['descuento_pct'] ?? 0) / 100);
        $reconstruido += $neto + ($neto * ($item['iva_pct'] ?? 0) / 100);
    }

    if (abs($reconstruido - (float) $nota['monto']) > $tolerancia) {
        $descuadradas[] = [$nota, round($reconstruido, 2)];
        continue;
    }

    foreach ($items as $item) {
        $item['producto_id'] = $resolverProducto($item['producto_id'], $item['codigo']);

        if ($item['producto_id'] === null) {
            // Sin producto el item entra igual —el importe de la nota no depende de el—
            // pero no aporta CMV: queda listado para revisarlo a mano.
            $sinProducto[] = [$nota, $item['codigo']];
        }
        $aInsertar[] = [$nota['id'], $item];
    }
}

$notasOk = count(array_unique(array_column($aInsertar, 0)));

echo "base                      : {$db}\n";
echo 'notas pendientes          : '.count($pendientes)."\n";
echo 'notas en el export        : '.count($exportado)."\n";
echo "notas que se van a cargar : {$notasOk}\n";
echo 'items a insertar          : '.count($aInsertar)."\n";
echo 'notas descuadradas        : '.count($descuadradas)."  (se saltean)\n";
echo 'items sin producto        : '.count($sinProducto)."  (entran con producto_id NULL)\n";

foreach ($descuadradas as [$nota, $suma]) {
    printf("  DESCUADRE nota %s (%s, %s): monto %s vs items %s\n",
        $nota['id'], $nota['legacy_id'], $nota['fecha_emision'], $nota['monto'], $suma);
}
foreach ($sinProducto as [$nota, $codigo]) {
    printf("  SIN PRODUCTO nota %s: codigo %s\n", $nota['id'], $codigo);
}

if ($soloVer) {
    echo "\n[SIMULACION] no se escribio nada. Agregar --aplicar para ejecutar.\n";
    exit;
}

$maxAntes = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM nota_credito_debito_items')->fetchColumn();

$pdo->beginTransaction();
$sentencia = $pdo->prepare('
    INSERT INTO nota_credito_debito_items
        (nota_credito_debito_id, producto_id, cantidad, precio, descuento_pct, iva_pct, origen, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, \'venta_original\', NOW(), NOW())
');
foreach ($aInsertar as [$notaId, $item]) {
    $sentencia->execute([
        $notaId, $item['producto_id'], $item['cantidad'], $item['precio'],
        $item['descuento_pct'] ?? 0, $item['iva_pct'],
    ]);
}
$pdo->commit();

$insertados = (int) $pdo->query("SELECT COUNT(*) FROM nota_credito_debito_items WHERE id > {$maxAntes}")->fetchColumn();

echo "\nOK. insertados: {$insertados}\n";
echo "ROLLBACK: DELETE FROM nota_credito_debito_items WHERE id > {$maxAntes};\n";
